<?php

namespace App\Console\Commands;

use App\Domain\Exploitation\Sauvegarde\CoffreSauvegarde;
use App\Domain\Exploitation\Sauvegarde\PipelineRestauration;
use App\Domain\Exploitation\Sauvegarde\PipelineSauvegarde;
use App\Domain\Exploitation\Sauvegarde\SceauSauvegarde;
use App\Models\Tenant;
use App\Models\TenantBackup as TenantBackupModel;
use App\Models\VerificationRestauration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Relit une sauvegarde pour de vrai, et enregistre ce qu'elle a rendu.
 *
 * Une sauvegarde jamais restaurée n'est pas une sauvegarde : c'est un fichier
 * dont on espère qu'il contient quelque chose. Tant qu'on ne l'a pas relu, on
 * ne sait ni si la clé est la bonne, ni si l'archive est complète, ni si le
 * dump porte les tables qu'on croit. Les trois échouent en silence.
 *
 * La restauration va dans une base jetable, dont le nom se termine
 * obligatoirement par `_verif_restauration`. Ce n'est pas une convention de
 * nommage, c'est le garde-fou : cette commande écrit dans une base et tourne
 * sur le serveur de production ; si elle se trompait de cible, elle écraserait
 * l'établissement avec une sauvegarde — le sinistre même qu'elle prévient.
 */
class TenantVerifierRestauration extends Command
{
    protected $signature = 'tenant:verifier-restauration
                            {tenant? : Code de l\'instance (toutes si omis avec --all)}
                            {--all : Vérifier chaque instance}
                            {--garder : Conserver la base d\'essai pour l\'inspecter}';

    protected $description = 'Restaure la dernière sauvegarde dans une base jetable et vérifie ce qu\'elle rend';

    public function handle(): int
    {
        $instances = $this->instances();

        if ($instances->isEmpty()) {
            $this->error('Aucune instance à vérifier.');

            return self::FAILURE;
        }

        $echecs = 0;

        foreach ($instances as $instance) {
            if (! $this->verifier($instance)) {
                $echecs++;
            }
        }

        $this->newLine();
        $this->line($echecs === 0
            ? '  Toutes les sauvegardes vérifiées se relisent.'
            : "  {$echecs} sauvegarde(s) ne se relisent pas.");

        return $echecs === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function instances()
    {
        if ($this->option('all')) {
            return Tenant::where('status', 'active')->orderBy('code')->get();
        }

        $code = $this->argument('tenant');

        if ($code === null) {
            $this->error('Précisez un code d\'instance, ou --all.');

            return collect();
        }

        $instance = Tenant::where('code', $code)->first();

        return $instance === null ? collect() : collect([$instance]);
    }

    private function verifier(Tenant $instance): bool
    {
        $this->newLine();
        $this->line("  <options=bold>{$instance->code}</>");

        $sauvegarde = TenantBackupModel::where('tenant_id', $instance->id)
            ->where('status', 'completed')
            ->whereNotNull('database_backup_path')
            ->latest('created_at')
            ->first();

        if ($sauvegarde === null) {
            return $this->conclure($instance, null, 'echouee', 'aucune sauvegarde de base à relire', 0, [], 0);
        }

        $archive = $sauvegarde->database_backup_path;

        if (! is_file($archive)) {
            return $this->conclure($instance, $sauvegarde, 'echouee', "archive introuvable : {$archive}", 0, [], 0);
        }

        $chiffree = str_ends_with($archive, '.enc');

        if ($chiffree && CoffreSauvegarde::cle() === null) {
            return $this->conclure($instance, $sauvegarde, 'echouee', 'archive chiffrée mais aucune clé configurée', 0, [], 0);
        }

        // Le sceau d'abord, avant d'écrire la moindre ligne. Une archive
        // modifiée se déchiffre quand même : sans cette vérification, on
        // injecterait dans une base ce qu'un tiers a voulu y mettre.
        $avertissement = null;

        if ($chiffree) {
            $refus = $this->raisonDeRefuserLeSceau($sauvegarde, $archive);

            if ($refus !== null) {
                return $this->conclure($instance, $sauvegarde, 'echouee', $refus, 0, [], 0);
            }

            if (! SceauSauvegarde::existe($archive)) {
                $avertissement = 'archive antérieure au scellement : son intégrité n\'a pas pu être vérifiée';
                $this->line("    <fg=yellow>{$avertissement}</>");
            }
        }

        $baseEssai = $this->baseEssai($instance);
        $refusCible = $this->raisonDeRefuserLaCible($baseEssai);

        if ($refusCible !== null) {
            return $this->conclure($instance, $sauvegarde, 'echouee', $refusCible, 0, [], 0);
        }

        $debut = microtime(true);
        $fichierOptions = null;
        $fichierCle = null;
        $baseVidee = false;

        try {
            try {
                $this->viderBaseEssai($instance, $baseEssai);
                $baseVidee = true;
            } catch (\Throwable $e) {
                return $this->conclure($instance, $sauvegarde, 'echouee', sprintf(
                    'base d\'essai « %s » inaccessible : créez-la dans cPanel et donnez tous les privilèges à l\'utilisateur de l\'instance (%s)',
                    $baseEssai,
                    $e->getMessage(),
                ), 0, [], (int) (microtime(true) - $debut));
            }

            $fichierOptions = CoffreSauvegarde::fichierSecret(
                PipelineSauvegarde::fichierOptions($instance->database_credentials ?? []),
            );

            if ($chiffree) {
                $fichierCle = CoffreSauvegarde::fichierSecret(CoffreSauvegarde::cle());
            }

            exec(PipelineRestauration::commande($archive, $fichierOptions, $baseEssai, $fichierCle) . ' 2>&1', $sortie, $code);

            if ($code !== 0) {
                return $this->conclure($instance, $sauvegarde, 'echouee', 'la restauration a échoué : ' . self::erreurUtile($sortie), 0, [], (int) (microtime(true) - $debut));
            }

            [$tables, $lignes] = $this->relire($instance, $baseEssai);

            if ($tables === 0) {
                return $this->conclure($instance, $sauvegarde, 'echouee', 'la restauration a abouti sur une base vide', 0, [], (int) (microtime(true) - $debut));
            }

            return $this->conclure($instance, $sauvegarde, 'reussie', $avertissement, $tables, $lignes, (int) (microtime(true) - $debut));
        } finally {
            // Vider après coup rend la place disque, et ne laisse pas traîner
            // la copie des données d'une école dans une base partagée.
            if ($baseVidee && ! $this->option('garder')) {
                try {
                    $this->viderBaseEssai($instance, $baseEssai);
                } catch (\Throwable $e) {
                    \Log::warning("[sauvegarde] base d'essai {$baseEssai} non vidée après vérification", ['erreur' => $e->getMessage()]);
                }
            }

            CoffreSauvegarde::effacerSecret($fichierOptions);
            CoffreSauvegarde::effacerSecret($fichierCle);
        }
    }

    /** La base d'essai configurée, ou celle qu'on dérive de l'instance. */
    private function baseEssai(Tenant $instance): string
    {
        $configuree = trim((string) config('sauvegarde.base_essai', ''));

        return $configuree !== '' ? $configuree : PipelineRestauration::baseEssai($instance->database_name);
    }

    /**
     * Pourquoi refuser d'écrire dans cette base, ou `null`.
     *
     * Cette commande supprime toutes les tables de la base qu'on lui nomme,
     * sur le serveur de production. Trois verrous, parce qu'un seul faux pas
     * viderait la base d'une école : un nom sans caractère douteux, le suffixe
     * d'essai, et aucune instance déclarée sur cette base.
     */
    private function raisonDeRefuserLaCible(string $baseEssai): ?string
    {
        try {
            PipelineRestauration::nomSur($baseEssai);
        } catch (\InvalidArgumentException) {
            return "cible refusée : nom de base invalide ({$baseEssai})";
        }

        if (! PipelineRestauration::estBaseEssai($baseEssai)) {
            return "cible refusée : {$baseEssai} ne se termine pas par " . PipelineRestauration::SUFFIXE_ESSAI;
        }

        if (Tenant::withTrashed()->where('database_name', $baseEssai)->exists()) {
            return "cible refusée : {$baseEssai} est la base d'une instance";
        }

        return null;
    }

    /** Supprime toutes les tables et vues de la base d'essai. */
    private function viderBaseEssai(Tenant $instance, string $baseEssai): void
    {
        $connexion = $this->connexionEssai($instance, $baseEssai);

        try {
            $objets = array_map(function ($ligne) {
                $valeurs = array_values((array) $ligne);

                return ['nom' => (string) $valeurs[0], 'type' => (string) ($valeurs[1] ?? 'BASE TABLE')];
            }, DB::connection($connexion)->select('SHOW FULL TABLES'));

            foreach (PipelineRestauration::instructionsVidage($objets) as $instruction) {
                DB::connection($connexion)->statement($instruction);
            }
        } finally {
            DB::purge($connexion);
        }
    }

    /** La connexion vers la base d'essai, avec les identifiants de l'instance. */
    private function connexionEssai(Tenant $instance, string $baseEssai): string
    {
        $connexion = 'verif_restauration';
        $identifiants = $instance->database_credentials ?? [];

        config(['database.connections.' . $connexion => [
            'driver' => 'mysql',
            'host' => $identifiants['host'] ?? 'localhost',
            'port' => $identifiants['port'] ?? 3306,
            'database' => $baseEssai,
            'username' => $identifiants['username'] ?? '',
            'password' => $identifiants['password'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]]);

        DB::purge($connexion);

        return $connexion;
    }

    /**
     * La sortie d'un client MySQL, sans l'avertissement de nom obsolète.
     *
     * MariaDB préfixe chaque appel à `mysql` ou `mysqldump` d'une ligne qui
     * annonce son renommage. Elle occupait la raison enregistrée au point d'en
     * chasser l'erreur réelle.
     */
    private static function sansBruit(array $lignes): array
    {
        return array_values(array_filter(
            $lignes,
            fn (string $ligne) => ! str_contains($ligne, 'Deprecated program name'),
        ));
    }

    /**
     * La ligne qui dit ce qui a échoué.
     *
     * En cas d'erreur, le client MySQL recopie d'abord l'instruction fautive
     * entre deux lignes de tirets, puis la ligne `ERROR`. Garder les premières
     * lignes enregistrait la requête et perdait la cause.
     */
    private static function erreurUtile(array $lignes): string
    {
        $lignes = self::sansBruit($lignes);
        $erreurs = array_values(array_filter($lignes, fn (string $l) => str_contains($l, 'ERROR')));

        return implode(' ', array_slice($erreurs !== [] ? $erreurs : $lignes, 0, 2));
    }

    /**
     * Pourquoi refuser de relire une archive chiffrée, ou `null`.
     *
     * Trois cas. Un sceau présent doit tenir. Un sceau absent sur une
     * sauvegarde marquée scellée à sa création veut dire qu'on l'a retiré —
     * supprimer le fichier ne doit pas suffire à faire passer une archive
     * modifiée pour une archive ancienne. Reste la sauvegarde prise avant le
     * scellement : on la relit, parce qu'une restauration impossible coûte
     * plus cher qu'une restauration non vérifiée, mais on le dit.
     */
    private function raisonDeRefuserLeSceau(TenantBackupModel $sauvegarde, string $archive): ?string
    {
        if (SceauSauvegarde::existe($archive)) {
            $refus = SceauSauvegarde::raisonDeRefuser($archive, CoffreSauvegarde::cle());

            return $refus === null ? null : "archive refusée : {$refus}";
        }

        return $sauvegarde->est_authentifie === true
            ? 'archive refusée : le sceau posé à sa création a disparu'
            : null;
    }

    /**
     * Ce que la base restaurée contient réellement.
     *
     * Compter les tables ne suffit pas : un dump qui ne porterait que les
     * `CREATE TABLE` restaurerait 187 tables vides et passerait pour bon. On
     * compte donc les lignes des tables qui portent le métier — sans elles,
     * l'établissement n'a rien récupéré.
     */
    private function relire(Tenant $instance, string $baseEssai): array
    {
        $connexion = $this->connexionEssai($instance, $baseEssai);

        $tables = count(DB::connection($connexion)->select('SHOW TABLES'));
        $lignes = [];

        foreach (['users', 'esbtp_etudiants', 'esbtp_inscriptions', 'esbtp_paiements', 'esbtp_notes'] as $table) {
            try {
                $lignes[$table] = DB::connection($connexion)->table($table)->count();
            } catch (\Throwable) {
                // Toutes les instances ne portent pas toutes les tables ; une
                // absente n'est pas un échec de restauration.
            }
        }

        DB::purge($connexion);

        return [$tables, $lignes];
    }

    private function conclure(
        Tenant $instance,
        ?TenantBackupModel $sauvegarde,
        string $verdict,
        ?string $raison,
        int $tables,
        array $lignes,
        int $duree,
    ): bool {
        VerificationRestauration::create([
            'tenant_id' => $instance->id,
            'tenant_backup_id' => $sauvegarde?->id,
            'verdict' => $verdict,
            // La colonne tient 255 caractères. Une raison plus longue faisait
            // échouer l'enregistrement du verdict lui-même : l'échec de la
            // vérification devenait une exception, et ne laissait aucune trace.
            'raison' => $raison === null ? null : mb_strimwidth($raison, 0, 255, '…'),
            'tables_restaurees' => $tables,
            'lignes_par_table' => $lignes === [] ? null : $lignes,
            'duree_secondes' => $duree,
            'verifiee_at' => now(),
        ]);

        if ($verdict === 'reussie') {
            $detail = collect($lignes)->map(fn ($n, $t) => "{$t} : {$n}")->implode(', ');
            $this->line("    <fg=green>relue</> — {$tables} tables, {$detail} ({$duree} s)");

            return true;
        }

        $this->line("    <fg=red>echec</> — {$raison}");

        return false;
    }
}
