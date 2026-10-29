<?php

namespace App\Console\Commands;

use App\Services\Saas\LegacyInventoryReport;
use App\Services\Saas\SaasInventorySchemaInspector;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use PDOException;

final class SaasInventory extends Command
{
    protected $signature = 'saas:inventory
                            {--format=table : Sortie table ou json, sans ecriture de fichier}';

    protected $description = 'Inventaire groupe/etablissement/produit en lecture seule, sans importer de tenant';

    public function handle(LegacyInventoryReport $inventory, SaasInventorySchemaInspector $schema): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Format invalide : choisir table ou json.');

            return self::FAILURE;
        }

        try {
            $inspection = $schema->inspect();

            if (! $inspection['ready']) {
                // Les noms de table et colonne proviennent d'une liste fermee
                // de schemas attendus ; jamais de message SQL brut.
                if ($format === 'json') {
                    $this->line(json_encode([
                        'ok' => false,
                        'error_code' => 'saas_schema_incomplete',
                        'missing_tables' => $inspection['missing_tables'],
                        'missing_columns' => $inspection['missing_columns'],
                        'next_step' => 'php artisan migrate:status',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                } else {
                    $this->error('Inventaire impossible : schema du master incomplet.');
                    if ($inspection['missing_tables'] !== []) {
                        $this->warn('Tables absentes : '.implode(', ', $inspection['missing_tables']));
                    }
                    if ($inspection['missing_columns'] !== []) {
                        $this->warn('Colonnes absentes : '.implode(', ', $inspection['missing_columns']));
                    }
                    $this->line('Verifier : php artisan migrate:status');
                    $this->line('Ne pas utiliser migrate:fresh ou migrate:refresh en production.');
                }

                return self::FAILURE;
            }

            $report = $inventory->generate();
        } catch (QueryException|PDOException) {
            // Ni SQL brut ni identifiants de connexion dans la sortie.
            if ($format === 'json') {
                $this->line(json_encode([
                    'ok' => false,
                    'error_code' => 'master_database_unavailable_or_query_failed',
                    'next_step' => 'php artisan migrate:status',
                ], JSON_THROW_ON_ERROR));
            } else {
                $this->error('Inventaire impossible : connexion, droits de lecture ou requete master a verifier.');
                $this->line('Verifier : php artisan migrate:status');
            }

            return self::FAILURE;
        }

        if ($format === 'json') {
            $this->line(json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));

            return self::SUCCESS;
        }

        $summary = $report['summary'];
        $this->info('Inventaire SaaS — lecture seule, aucune importation');
        $this->table(
            ['Indicateur', 'Nombre'],
            collect($summary)->map(fn (int $n, string $key) => [$key, $n])->values()->all()
        );
        $this->table(
            ['Groupe historique', 'Code', 'Organisation SaaS', 'Etat'],
            array_map(fn (array $group) => [
                $group['group_name'],
                $group['group_code'],
                $group['saas_organization_id'] ?? '—',
                $group['link_status'],
            ], $report['groups'])
        );
        $this->table(
            ['Tenant', 'Groupe', 'Categorie prouvee', 'Correspondance', 'Points a verifier'],
            array_map(fn (array $tenant) => [
                $tenant['legacy_code'],
                $tenant['legacy_group_code'] ?? '—',
                $tenant['establishment_type'] ?? 'inconnue',
                $tenant['saas_establishment_id'] ?? '—',
                implode(', ', $tenant['review_reasons']) ?: 'aucun',
            ], $report['legacy_tenants'])
        );

        $this->warn('Un nom, sous-domaine ou moteur KLASSCIv2 ne prouve jamais LMD ou BTS.');
        $this->line('Utiliser --format=json pour la revue manuelle, sans secrets ni donnees personnelles.');

        return self::SUCCESS;
    }
}
