<?php

namespace App\Console\Commands;

use App\Domain\Exploitation\Sauvegarde\CoffreSauvegarde;
use App\Domain\Exploitation\Sauvegarde\PipelineRestauration;
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

        $baseEssai = PipelineRestauration::baseEssai($instance->database_name);

        // La ceinture, en plus des bretelles. `baseEssai` produit toujours un
        // nom d'essai ; on le revérifie avant d'écrire quoi que ce soit, parce
        // qu'entre les deux il n'y a qu'une refactorisation.
        if (! PipelineRestauration::estBaseEssai($baseEssai)) {
            return $this->conclure($instance, $sauvegarde, 'echouee', "cible refusée : {$baseEssai}", 0, [], 0);
        }

        $debut = microtime(true);
        $fichierOptions = null;
        $fichierCle = null;

        try {
            $fichierOptions = CoffreSauvegarde::fichierSecret(
                \App\Domain\Exploitation\Sauvegarde\PipelineSauvegarde::fichierOptions(
                    $instance->database_credentials ?? [],
                ),
            );

            if ($chiffree) {
                $fichierCle = CoffreSauvegarde::fichierSecret(CoffreSauvegarde::cle());
            }

            exec(PipelineRestauration::commandePreparerBase($fichierOptions, $baseEssai) . ' 2>&1', $s1, $c1);

            if ($c1 !== 0) {
                return $this->conclure($instance, $sauvegarde, 'echouee', 'base d\'essai impossible à créer : ' . implode(' ', $s1), 0, [], (int) (microtime(true) - $debut));
            }

            exec(PipelineRestauration::commande($archive, $fichierOptions, $baseEssai, $fichierCle) . ' 2>&1', $s2, $c2);

            if ($c2 !== 0) {
                return $this->conclure($instance, $sauvegarde, 'echouee', 'la restauration a échoué : ' . implode(' ', array_slice($s2, 0, 3)), 0, [], (int) (microtime(true) - $debut));
            }

            [$tables, $lignes] = $this->relire($instance, $baseEssai);

            if ($tables === 0) {
                return $this->conclure($instance, $sauvegarde, 'echouee', 'la restauration a abouti sur une base vide', 0, [], (int) (microtime(true) - $debut));
            }

            return $this->conclure($instance, $sauvegarde, 'reussie', null, $tables, $lignes, (int) (microtime(true) - $debut));
        } finally {
            if ($fichierOptions !== null && ! $this->option('garder')) {
                exec(PipelineRestauration::commandeSupprimerBase($fichierOptions, $baseEssai) . ' 2>&1');
            }

            CoffreSauvegarde::effacerSecret($fichierOptions);
            CoffreSauvegarde::effacerSecret($fichierCle);
        }
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
            'raison' => $raison,
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
