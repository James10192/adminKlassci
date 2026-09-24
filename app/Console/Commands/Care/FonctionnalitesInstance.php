<?php

namespace App\Console\Commands\Care;

use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\TenantFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Activer ou desactiver les fonctionnalites KLASSCI Care d'une instance.
 *
 * Le deploiement est progressif, ecole par ecole : une fonctionnalite absente
 * de tenant_features est desactivee (config/care.php). Sans cette commande, la
 * seule facon de l'activer etait d'ecrire en base a la main.
 *
 *   php artisan care:fonctionnalites presentation                  # etat
 *   php artisan care:fonctionnalites presentation --activer=tout
 *   php artisan care:fonctionnalites presentation --desactiver=support_screenshot
 */
class FonctionnalitesInstance extends Command
{
    protected $signature = 'care:fonctionnalites
        {tenant : Code de l\'instance}
        {--activer= : Fonctionnalites a activer, separees par des virgules, ou « tout »}
        {--desactiver= : Fonctionnalites a desactiver, separees par des virgules, ou « tout »}';

    protected $description = 'Afficher, activer ou desactiver les fonctionnalites KLASSCI Care d\'une instance';

    public function handle(): int
    {
        $tenant = Tenant::where('code', $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error('Instance introuvable : '.$this->argument('tenant'));

            return self::FAILURE;
        }

        $connues = config('care.fonctionnalites', []);

        try {
            $activer = $this->lire('activer', $connues);
            $desactiver = $this->lire('desactiver', $connues);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($communes = array_intersect($activer, $desactiver)) {
            $this->error('A la fois activee et desactivee : '.implode(', ', $communes));

            return self::FAILURE;
        }

        // Toute la validation est faite : on ecrit tout ou rien.
        $etats = array_fill_keys($activer, true) + array_fill_keys($desactiver, false);
        $basculees = DB::transaction(function () use ($tenant, $etats) {
            $basculees = [];
            foreach ($etats as $cle => $etat) {
                $ligne = TenantFeature::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'feature_key' => $cle],
                    ['is_enabled' => $etat],
                );
                if ($ligne->wasRecentlyCreated || $ligne->wasChanged('is_enabled')) {
                    $basculees[$cle] = $etat;
                }
            }

            return $basculees;
        });

        // Journalise ce qui a reellement change : rejouer la meme commande n'ecrit rien.
        if ($basculees !== []) {
            TenantActivityLog::log($tenant->id, 'care_features_changed', 'Fonctionnalites KLASSCI Care modifiees', null, [
                'activees' => array_keys(array_filter($basculees)),
                'desactivees' => array_keys(array_diff_key($basculees, array_filter($basculees))),
            ]);
        }

        $actives = TenantFeature::where('tenant_id', $tenant->id)
            ->whereIn('feature_key', $connues)
            ->where('is_enabled', true)
            ->pluck('feature_key')
            ->all();

        $this->table(['Fonctionnalite', 'Etat'], array_map(
            fn ($cle) => [$cle, in_array($cle, $actives, true) ? 'active' : 'desactivee'],
            $connues,
        ));

        // La capture passe par les pieces jointes, donc par le portail : seule,
        // elle est acceptee ici mais ne s'affiche nulle part (config/care.php).
        if (in_array('support_screenshot', $actives, true) && ! in_array('support_customer_portal', $actives, true)) {
            $this->warn('support_screenshot est active sans support_customer_portal : la capture ne s\'affichera pas.');
        }

        if ($basculees !== []) {
            $this->info('Visible sur l\'instance sous 5 minutes (cache du bootstrap).');
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function lire(string $option, array $connues): array
    {
        $brut = trim((string) $this->option($option));
        if ($brut === '') {
            return [];
        }
        if ($brut === 'tout') {
            return $connues;
        }

        $cles = array_values(array_unique(array_filter(array_map('trim', explode(',', $brut)))));
        if ($inconnues = array_diff($cles, $connues)) {
            throw new \InvalidArgumentException(
                'Fonctionnalite inconnue : '.implode(', ', $inconnues).'. Connues : '.implode(', ', $connues)
            );
        }

        return $cles;
    }
}
