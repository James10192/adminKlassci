<?php

namespace App\Console\Commands;

use App\Services\Saas\LegacyInventoryReport;
use App\Services\Saas\SaasInventorySchemaInspector;
use App\Services\Saas\SaasMappingReview;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use JsonException;
use PDOException;

final class SaasMappingReviewCommand extends Command
{
    protected $signature = 'saas:mapping-review
                            {manifest : Chemin du JSON local prive, sans sauvegarde cote application}
                            {--format=table : Sortie table ou json}';

    protected $description = 'Controle un projet de correspondances groupes/ecoles sans ecriture SQL';

    public function handle(
        SaasInventorySchemaInspector $schema,
        LegacyInventoryReport $inventory,
        SaasMappingReview $review
    ): int {
        $format = (string) $this->option('format');
        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Format invalide (table ou json).');

            return self::FAILURE;
        }

        $path = (string) $this->argument('manifest');
        // Fichier local place par l'operateur autorise ; aucun upload web.
        if (! is_file($path) || ! is_readable($path)
            || filesize($path) === false || filesize($path) > 65536
        ) {
            return $this->failSafely($format, 'invalid_manifest_file');
        }

        $content = @file_get_contents($path);
        if ($content === false || strlen($content) > 65536) {
            return $this->failSafely($format, 'invalid_manifest_file');
        }

        try {
            $manifest = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->failSafely($format, 'invalid_manifest_json');
        }
        if (! is_array($manifest)) {
            return $this->failSafely($format, 'invalid_manifest_schema');
        }

        try {
            if (! $schema->inspect()['ready']) {
                return $this->failSafely($format, 'saas_schema_incomplete');
            }

            $report = $review->review($manifest, $inventory->generate());
        } catch (QueryException|PDOException) {
            return $this->failSafely($format, 'master_database_unavailable');
        }

        if (! ($report['ok'] ?? false)) {
            return $this->failSafely($format, $report['error_code'] ?? 'invalid_manifest_schema');
        }

        if ($format === 'json') {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Revue de correspondances — lecture seule ; aucune importation');
        $this->table(
            ['Tenant', 'Type propose', 'Groupe propose', 'Statut', 'Points bloquants'],
            array_map(fn (array $row) => [
                $row['tenant_code'] ?? '—',
                $row['proposed_establishment_type'] ?? '—',
                $row['proposed_group_code'] ?? '—',
                $row['status'],
                implode(', ', $row['issues']),
            ], $report['proposals'])
        );
        $this->warn('Le rapport ne valide aucun changement de groupe, aucun contrat et aucun import.');
        $this->line('Bloques : '.$report['blocked'].' / '.$report['total']);

        return self::SUCCESS;
    }

    private function failSafely(string $format, string $code): int
    {
        if ($format === 'json') {
            $this->line(json_encode(['ok' => false, 'error_code' => $code]));
        } else {
            $this->error('Revue impossible : '.$code);
        }

        return self::FAILURE;
    }
}
