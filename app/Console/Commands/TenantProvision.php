<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Support\Git\NomDeBranche;
use App\Support\Shell\CommandeDistante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class TenantProvision extends Command
{
    protected $signature = 'tenant:provision
                            {--code= : Code unique du tenant (ex: lycee-yop)}
                            {--name= : Nom complet de l\'établissement}
                            {--subdomain= : Sous-domaine (ex: lycee-yop)}
                            {--branch= : Branche Git (défaut: main)}
                            {--plan=free : Plan tarifaire (free, essentiel, professional, elite)}
                            {--admin-email= : Email de l\'administrateur principal}
                            {--admin-name= : Nom de l\'administrateur principal}
                            {--timezone=UTC : Fuseau de l\'etablissement (ex: Africa/Porto-Novo). Ne se change plus sans degats une fois des donnees saisies}';

    protected $description = 'Provisionner un nouveau tenant complet (17 étapes: DB, Git, .env, migrations, subdomain, SSL)';

    private array $steps = [];
    private int $currentStep = 0;
    private int $totalSteps = 17;

    public function handle()
    {
        $this->info('🚀 PROVISIONNEMENT D\'UN NOUVEAU TENANT');
        $this->newLine();

        // Collecter les informations
        $code = $this->option('code') ?: $this->ask('Code du tenant (ex: lycee-yop)');
        $name = $this->option('name') ?: $this->ask('Nom complet de l\'établissement');
        $subdomain = $this->option('subdomain') ?: $this->ask('Sous-domaine', $code);
        $branch = $this->option('branch') ?: $this->choice('Branche Git', ['main', 'develop', 'staging'], 0);
        $plan = $this->option('plan') ?: $this->choice('Plan tarifaire', ['free', 'essentiel', 'professional', 'elite'], 0);
        $adminEmail = $this->option('admin-email') ?: $this->ask('Email administrateur');
        $adminName = $this->option('admin-name') ?: $this->ask('Nom administrateur');
        $timezone = (string) $this->option('timezone');

        // Ces valeurs finissent dans un chemin, un nom de base (CREATE DATABASE),
        // une commande git, ssh, et le .env de l'ecole. On les refuse ici, avant
        // la moindre ecriture et le moindre processus.
        if (($motif = $this->motifDeRefus($code, $subdomain, $branch, $name)) !== null) {
            $this->error("❌ Provisionnement refusé : {$motif}");
            return 1;
        }

        // Le fuseau est le SEUL reglage qui ne se rattrape pas apres coup :
        // Laravel ecrit les horodatages dedans, donc le changer sur une
        // instance qui a deja des inscriptions laisse derriere des lignes
        // ecrites dans l'ancien. On le valide donc ici, pas au deploiement.
        $decalageBase = $this->decalageBaseDeDonnees($timezone);

        if ($decalageBase === null) {
            return 1;
        }

        // Vérifier que le tenant n'existe pas déjà
        if (Tenant::where('code', $code)->exists()) {
            $this->error("❌ Un tenant avec le code '{$code}' existe déjà.");
            return 1;
        }

        if (Tenant::where('subdomain', $subdomain)->exists()) {
            $this->error("❌ Un tenant avec le sous-domaine '{$subdomain}' existe déjà.");
            return 1;
        }

        // Configuration du plan
        $planConfig = $this->getPlanConfiguration($plan);

        // Afficher le résumé
        $this->displayProvisioningSummary($code, $name, $subdomain, $branch, $plan, $adminEmail, $adminName, $timezone, $decalageBase);

        if (!$this->confirm('Confirmer le provisionnement ?', true)) {
            $this->warn('⚠️  Provisionnement annulé.');
            return 0;
        }

        $this->newLine();

        try {
            // Étape 1: Créer l'enregistrement du tenant dans la BDD Master
            $this->step('Création de l\'enregistrement tenant dans klassci_master');
            $tenant = $this->createTenantRecord($code, $name, $subdomain, $branch, $plan, $planConfig, $adminEmail, $adminName);

            // Étape 2: Générer les credentials de la BDD
            $this->step('Génération des credentials de base de données');
            $dbPassword = Str::random(32);
            $databaseName = "c2569688c_{$code}";

            // Étape 3: Créer la base de données
            $this->step("Création de la base de données '{$databaseName}'");
            $this->createDatabase($databaseName, $dbPassword);

            // Étape 4: Mettre à jour les credentials dans le tenant
            $this->step('Enregistrement des credentials dans le tenant');
            $tenant->update([
                'database_name' => $databaseName,
                'database_credentials' => [
                    'host' => 'localhost',
                    'port' => 3306,
                    'username' => 'c2569688c_tenant',
                    'password' => $dbPassword,
                ],
            ]);

            // Étape 5: Créer le répertoire du tenant
            $this->step('Création du répertoire du tenant');
            $tenantPath = env('PRODUCTION_PATH') . $code;
            $this->createDirectory($tenantPath);

            // Étape 6: Clone du repository Git
            $this->step("Clone du repository Git (branche: {$branch})");
            $this->cloneRepository($tenantPath, $branch);

            // Étape 7: Créer le fichier .env.production
            $this->step('Création du fichier .env.production');
            $this->createEnvFile($tenantPath, $code, $name, $databaseName, $dbPassword, $timezone, $decalageBase);

            // Étape 8: Installer les dépendances Composer
            $this->step('Installation des dépendances Composer');
            $this->executeRemoteCommand($tenantPath, ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction']);

            // Étape 9: Générer la clé d'application
            $this->step('Génération de la clé d\'application Laravel');
            $this->executeRemoteCommand($tenantPath, ['php', 'artisan', 'key:generate', '--force']);

            // Étape 10: Créer le lien symbolique storage
            $this->step('Création du lien symbolique storage');
            $this->executeRemoteCommand($tenantPath, ['php', 'artisan', 'storage:link']);

            // Étape 11: Exécuter les migrations
            $this->step('Exécution des migrations de base de données');
            $this->executeRemoteCommand($tenantPath, ['php', 'artisan', 'migrate', '--force']);

            // Étape 12: Exécuter les seeders (si disponibles)
            $this->step('Exécution des seeders (optionnel)');
            try {
                $this->executeRemoteCommand($tenantPath, ['php', 'artisan', 'db:seed', '--class=InitialDataSeeder', '--force']);
            } catch (\Exception $e) {
                $this->warn('⚠️  Aucun seeder trouvé ou erreur lors du seeding (ignoré)');
            }

            // Étape 13: Configurer les permissions
            $this->step('Configuration des permissions des fichiers');
            $this->executeRemoteCommand($tenantPath, ['chmod', '-R', '775', 'storage']);
            $this->executeRemoteCommand($tenantPath, ['chmod', '-R', '775', 'bootstrap/cache']);
            $this->executeRemoteCommand($tenantPath, ['chown', '-R', 'c2569688c:c2569688c', '.']);

            // Étape 14: Cache des configurations
            $this->step('Mise en cache des configurations');
            $this->executeRemoteCommand($tenantPath, ['php', 'artisan', 'config:cache']);
            $this->executeRemoteCommand($tenantPath, ['php', 'artisan', 'route:cache']);

            // Étape 15: Créer le sous-domaine via cPanel UAPI (simulé)
            $this->step("Création du sous-domaine '{$subdomain}.{$this->domaineTenant()}'");
            $this->createSubdomain($subdomain, $tenantPath);

            // Étape 16: Installer le certificat SSL (simulé)
            $this->step('Installation du certificat SSL (Let\'s Encrypt)');
            $this->installSSL($subdomain);

            // Étape 17: Health check initial
            $this->step('Vérification de santé initiale du tenant');
            sleep(2); // Attendre que le serveur web se rafraîchisse
            $this->call('tenant:health-check', [
                'tenant' => $code,
                '--check' => 'http_status',
            ]);

            // Récupérer le commit hash
            $commitHash = trim($this->executeRemoteCommand($tenantPath, ['git', 'rev-parse', 'HEAD'], true));
            $tenant->update([
                'git_commit_hash' => $commitHash,
                'last_deployed_at' => now(),
                'status' => 'active',
            ]);

            // Log d'activité
            TenantActivityLog::create([
                'tenant_id' => $tenant->id,
                'action' => 'tenant_provisioned',
                'description' => "Tenant provisionné avec succès",
                'metadata' => [
                    'plan' => $plan,
                    'branch' => $branch,
                    'database' => $databaseName,
                ],
            ]);

            $this->newLine(2);
            $this->displayProvisioningSuccess($tenant, $subdomain, $adminEmail);

            return 0;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error("❌ Erreur lors du provisionnement : {$e->getMessage()}");
            $this->newLine();

            // Marquer le tenant comme suspended si créé
            if (isset($tenant)) {
                $tenant->update(['status' => 'suspended']);

                TenantActivityLog::create([
                    'tenant_id' => $tenant->id,
                    'action' => 'provisioning_failed',
                    'description' => "Échec du provisionnement",
                    'metadata' => ['error' => $e->getMessage()],
                ]);
            }

            \Log::error("Erreur provisionnement tenant {$code}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return 1;
        }
    }

    private function step(string $description): void
    {
        $this->currentStep++;
        $this->info("[{$this->currentStep}/{$this->totalSteps}] {$description}...");
        $this->steps[] = $description;
    }

    private function createTenantRecord(
        string $code,
        string $name,
        string $subdomain,
        string $branch,
        string $plan,
        array $planConfig,
        string $adminEmail,
        string $adminName
    ): Tenant {
        return Tenant::create([
            'code' => $code,
            'name' => $name,
            'subdomain' => $subdomain,
            'git_branch' => $branch,
            'status' => 'provisioning',
            'plan' => $plan,
            'monthly_fee' => $planConfig['monthly_fee'],
            'subscription_start_date' => now(),
            'subscription_end_date' => now()->addYear(),
            'max_users' => $planConfig['max_users'],
            'max_staff' => $planConfig['max_users'],
            'max_students' => $planConfig['max_inscriptions_per_year'],
            'max_inscriptions_per_year' => $planConfig['max_inscriptions_per_year'],
            'max_storage_mb' => $planConfig['max_storage_mb'],
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'support_email' => $adminEmail,
        ]);
    }

    private function createDatabase(string $databaseName, string $password): void
    {
        // Créer la base de données
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Créer l'utilisateur (ou le réutiliser)
        $username = 'c2569688c_tenant';

        // Vérifier si l'utilisateur existe déjà
        $userExists = DB::select("SELECT User FROM mysql.user WHERE User = ?", [$username]);

        if (empty($userExists)) {
            DB::statement("CREATE USER '{$username}'@'localhost' IDENTIFIED BY '{$password}'");
        } else {
            DB::statement("ALTER USER '{$username}'@'localhost' IDENTIFIED BY '{$password}'");
        }

        // Accorder les privilèges
        DB::statement("GRANT ALL PRIVILEGES ON `{$databaseName}`.* TO '{$username}'@'localhost'");
        DB::statement("FLUSH PRIVILEGES");
    }

    private function createDirectory(string $path): void
    {
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function cloneRepository(string $path, string $branch): void
    {
        $repoUrl = 'https://github.com/your-organization/KLASSCIv2.git'; // À configurer dans .env

        $this->executeRemoteCommand(
            dirname($path),
            ['git', 'clone', '-b', $branch, '--', $repoUrl, basename($path)]
        );
    }

    /**
     * Le decalage a poser sur la session MySQL, deduit du fuseau demande.
     *
     * MySQL renseigne lui-meme sept colonnes d'horodatage de l'application
     * metier (`useCurrent()` dans leur migration, dont l'historique du parcours
     * d'inscription). Il les ecrit dans le fuseau de SA session : laissee a
     * celle du serveur, une instance a UTC+1 verrait ces colonnes prendre une
     * heure de retard sur le `created_at` de la MEME ligne.
     *
     * On pose un decalage fixe plutot que le nom du fuseau, parce que les
     * tables de fuseaux de MySQL ne sont pas chargees sur l'hebergement mutualise
     * — `SET time_zone='Africa/Porto-Novo'` y echoue. Un decalage fixe est donc
     * exact tant que le fuseau n'a pas d'heure d'ete, ce qui est le cas des
     * deux pays servis. Pour tout autre, on REFUSE plutot que de figer un
     * decalage juste la moitie de l'annee.
     *
     * @return string|null Le decalage (`+01:00`), ou null si le fuseau est
     *                     refuse — le message a alors deja ete affiche.
     */
    private function decalageBaseDeDonnees(string $fuseau): ?string
    {
        try {
            $zone = new \DateTimeZone($fuseau);
        } catch (\Exception $e) {
            $this->error("❌ Fuseau inconnu : '{$fuseau}'. Utilisez un identifiant IANA (ex: Africa/Abidjan, Africa/Porto-Novo).");

            return null;
        }

        $maintenant = new \DateTime('now', $zone);
        $transitions = $zone->getTransitions($maintenant->getTimestamp(), strtotime('+2 years'));

        // La premiere transition rendue est l'etat courant, pas un changement.
        if (count($transitions) > 1) {
            $this->error("❌ Le fuseau '{$fuseau}' pratique l'heure d'ete.");
            $this->line("   Cette commande ne sait poser qu'un decalage fixe pour la base, qui serait");
            $this->line("   faux la moitie de l'annee. Provisionnez, puis posez DB_TIMEZONE a la main");
            $this->line("   apres avoir verifie que les tables de fuseaux de MySQL sont chargees.");

            return null;
        }

        $secondes = $zone->getOffset($maintenant);
        $signe = $secondes < 0 ? '-' : '+';
        $secondes = abs($secondes);

        return sprintf('%s%02d:%02d', $signe, intdiv($secondes, 3600), intdiv($secondes % 3600, 60));
    }

    private function createEnvFile(string $path, string $code, string $name, string $database, string $password, string $timezone, string $decalageBase): void
    {
        $domaine = $this->domaineTenant();
        $envContent = <<<ENV
APP_NAME="{$name}"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://{$code}.{$domaine}

# Le fuseau de l'etablissement, et celui de sa session MySQL.
#
# C'est MAINTENANT qu'ils se posent : Laravel ecrit les horodatages dans le
# fuseau de l'application, donc le changer une fois des inscriptions saisies
# laisse derriere des lignes ecrites dans l'ancien, que les nouvelles ne
# rejoignent pas. C'est aussi pourquoi APP_TIMEZONE n'est pas dans les cles
# que le CLI sait modifier a distance.
#
# UTC est le defaut, et le comportement historique. Africa/Abidjan vaut
# UTC+0 sans heure d'ete : les instances ivoiriennes n'ont rien a changer.
APP_TIMEZONE={$timezone}
DB_TIMEZONE={$decalageBase}

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE={$database}
DB_USERNAME=c2569688c_tenant
DB_PASSWORD={$password}

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DRIVER=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=noreply@klassci.com
MAIL_FROM_NAME="\${APP_NAME}"
ENV;

        file_put_contents("{$path}/.env", $envContent);
    }

    /**
     * Le domaine sous lequel vivent les sites d'etablissement.
     *
     * Il etait ecrit en dur a six endroits de cette commande, dont le fichier
     * `.env` genere pour la nouvelle ecole : une plateforme qui change de nom y
     * inscrirait une `APP_URL` fausse, en production, sans une seule erreur.
     */
    private function domaineTenant(): string
    {
        return trim((string) config('group_portal.tenant_domain', 'klassci.com'), '/');
    }

    private function createSubdomain(string $subdomain, string $path): void
    {
        // TODO: Implémenter l'API cPanel UAPI
        // Pour l'instant, simulation
        $this->warn('⚠️  Création du sous-domaine simulée (API cPanel à implémenter)');

        // Code exemple pour cPanel UAPI:
        // $cpanel = new \cPanel\API($host, $user, $password);
        // $cpanel->api2('SubDomain', 'addsubdomain', [
        //     'domain' => $subdomain,
        //     'rootdomain' => 'klassci.com',
        //     'dir' => $path . '/public',
        // ]);
    }

    private function installSSL(string $subdomain): void
    {
        // TODO: Implémenter l'installation SSL via cPanel UAPI ou Let's Encrypt
        // Pour l'instant, simulation
        $this->warn('⚠️  Installation SSL simulée (Let\'s Encrypt / cPanel UAPI à implémenter)');

        // Code exemple pour Let's Encrypt:
        // $certbot = "certbot certonly --webroot -w {$path}/public -d {$subdomain}.klassci.com";
        // exec($certbot);
    }

    /**
     * @param list<string> $command argv — jamais une chaine relue par un shell.
     */
    private function executeRemoteCommand(string $path, array $command, bool $returnOutput = false): string
    {
        $libelle = implode(' ', $command);

        // En local (développement) : argv direct, aucun shell.
        if (app()->environment('local')) {
            $result = Process::path($path)->run($command);

            if (!$result->successful()) {
                throw new \Exception("Commande échouée: {$libelle}\n{$result->errorOutput()}");
            }

            return $returnOutput ? $result->output() : '';
        }

        // En production (via SSH) : le shell distant relit une chaine, donc chaque
        // mot y est cite (CommandeDistante). Le `--` empeche ssh de lire la
        // destination comme une option.
        $host = env('PRODUCTION_HOST');
        $user = env('PRODUCTION_USER');

        $result = Process::run(['ssh', '--', "{$user}@{$host}", CommandeDistante::construire($path, $command)]);

        if (!$result->successful()) {
            throw new \Exception("Commande SSH échouée: {$libelle}\n{$result->errorOutput()}");
        }

        return $returnOutput ? $result->output() : '';
    }

    /**
     * Rend null si les identifiants sont admissibles, sinon la raison du refus.
     *
     * Le code devient un repertoire, un nom de base `c2569688c_{code}` (64
     * caracteres max en MySQL, d'ou 54 ici) et l'APP_URL ; le sous-domaine est
     * une etiquette DNS. Le nom de l'ecole est ecrit entre guillemets dans le
     * .env : un guillemet ou un saut de ligne y ajouterait des cles (APP_DEBUG=true…).
     */
    private function motifDeRefus(mixed $code, mixed $subdomain, mixed $branch, mixed $name): ?string
    {
        $etiquette = '/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/';

        if (!is_string($code) || strlen($code) > 54 || preg_match($etiquette, $code) !== 1) {
            return 'code invalide (minuscules, chiffres et tirets, sans tiret en bordure, 54 caractères au plus).';
        }

        if (!is_string($subdomain) || strlen($subdomain) > 63 || preg_match($etiquette, $subdomain) !== 1) {
            return 'sous-domaine invalide (minuscules, chiffres et tirets, sans tiret en bordure, 63 caractères au plus).';
        }

        if (($motif = NomDeBranche::motifDeRefus($branch)) !== null) {
            return "branche : {$motif}";
        }

        if (!is_string($name) || trim($name) === '' || preg_match('/["\\\\$\p{Cc}]/u', $name) === 1) {
            return 'nom d\'établissement invalide (vide, ou contenant « " », « \\ », « $ » ou un caractère de contrôle).';
        }

        return null;
    }

    private function getPlanConfiguration(string $plan): array
    {
        return match($plan) {
            'free' => [
                'monthly_fee' => 0,
                'max_users' => 5,
                'max_inscriptions_per_year' => 50,
                'max_storage_mb' => 512,
            ],
            'essentiel' => [
                'monthly_fee' => 100000,
                'max_users' => 20,
                'max_inscriptions_per_year' => 700,
                'max_storage_mb' => 2048,
            ],
            'professional' => [
                'monthly_fee' => 200000,
                'max_users' => 30,
                'max_inscriptions_per_year' => 3000,
                'max_storage_mb' => 5120,
            ],
            'elite' => [
                'monthly_fee' => 400000,
                'max_users' => 999999,
                'max_inscriptions_per_year' => 999999,
                'max_storage_mb' => 20480,
            ],
        };
    }

    private function displayProvisioningSummary(
        string $code,
        string $name,
        string $subdomain,
        string $branch,
        string $plan,
        string $adminEmail,
        string $adminName,
        string $timezone,
        string $decalageBase
    ): void {
        $this->newLine();
        $this->table(
            ['Paramètre', 'Valeur'],
            [
                ['Code', $code],
                ['Nom', $name],
                ['Sous-domaine', "{$subdomain}.{$this->domaineTenant()}"],
                ['Branche Git', $branch],
                ['Plan', $plan],
                ['Admin Email', $adminEmail],
                ['Admin Nom', $adminName],
                ['Fuseau', $timezone . ' (base : ' . $decalageBase . ')'],
            ]
        );
        $this->newLine();
    }

    private function displayProvisioningSuccess(Tenant $tenant, string $subdomain, string $adminEmail): void
    {
        $this->info('🎉 PROVISIONNEMENT TERMINÉ AVEC SUCCÈS !');
        $this->newLine();

        $this->table(
            ['Information', 'Valeur'],
            [
                ['ID Tenant', $tenant->id],
                ['Code', $tenant->code],
                ['Nom', $tenant->name],
                ['URL', "https://{$subdomain}.{$this->domaineTenant()}"],
                ['Base de données', $tenant->database_name],
                ['Plan', $tenant->plan],
                ['Statut', $tenant->status],
                ['Date création', $tenant->created_at->format('Y-m-d H:i:s')],
            ]
        );

        $this->newLine();
        $this->info('📧 Prochaines étapes :');
        $this->line("  1. Créer le compte administrateur principal ({$adminEmail})");
        $this->line("  2. Configurer les paramètres de l'établissement");
        $this->line("  3. Importer les données initiales (si nécessaire)");
        $this->line("  4. Tester l'accès à https://{$subdomain}.{$this->domaineTenant()}");
        $this->newLine();
    }
}
