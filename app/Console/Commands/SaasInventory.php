<?php

namespace App\Console\Commands;

use App\Services\Saas\LegacyInventoryReport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

final class SaasInventory extends Command
{
    protected $signature = 'saas:inventory
                            {--format=table : Sortie table ou json, sans ecriture de fichier}';

    protected $description = 'Inventaire groupe/etablissement/produit en lecture seule, sans importer de tenant';

    public function handle(LegacyInventoryReport $inventory): int
    {
        $format = (string) $this->option('format');
        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Format invalide : choisir table ou json.');

            return self::FAILURE;
        }

        try {
            $report = $inventory->generate();
        } catch (QueryException) {
            // Ne pas renvoyer le SQL, un nom de DB ou la connexion aux logs.
            $this->error('Inventaire indisponible : verifier les migrations du master.');

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
