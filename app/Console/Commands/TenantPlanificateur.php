<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Exploitation\LanceurPlanificateur;
use App\Support\Exploitation\PoulsPlanificateur;
use Illuminate\Console\Command;

/**
 * Fait tourner le planificateur Laravel de chaque école depuis la seule tâche
 * cron d'adminKlassci.
 *
 * Toutes les écoles vivent sur le même serveur qu'adminKlassci, qui connaît
 * leurs dossiers. Une ligne cron par école était un oubli garanti à chaque
 * nouvelle instance : sans elle, la boîte d'envoi KLASSCI Care, le suivi des
 * réponses du support et les relances ne partent jamais, sans un mot.
 *
 * Une école qui a déjà sa propre tâche cron n'est pas relancée : son pouls
 * (`passages.cron`) a moins de SILENCE_MAX secondes. Les deux ensemble
 * enverraient deux fois chaque relance. Une école dont le code ne connaît pas
 * encore le pouls n'est pas touchée non plus : on ne peut pas savoir si elle
 * a sa tâche cron.
 */
class TenantPlanificateur extends Command
{
    /** Deux minutes et demie : un passage manqué est toléré, deux non. */
    public const SILENCE_MAX = 150;

    protected $signature = 'tenant:planificateur
        {--tenant= : Code d\'une seule école}
        {--etat : Afficher l\'état sans rien lancer}';

    protected $description = 'Lancer le planificateur (schedule:run) des écoles qui n\'ont pas leur propre tâche cron';

    public function handle(LanceurPlanificateur $lanceur): int
    {
        $ecoles = Tenant::active()
            ->when($this->option('tenant'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('code')
            ->get();
        $lignes = [];

        foreach ($ecoles as $ecole) {
            [$decision, $detail] = $this->decider($ecole);

            if ($decision === 'lance' && ! $this->option('etat')) {
                $lanceur->lancer($ecole->cheminInstallationExistant());
            }

            $lignes[] = [$ecole->code, $decision, $detail];
        }

        if ($this->option('etat') || $this->getOutput()->isVerbose()) {
            $this->table(['École', 'Décision', 'Détail'], $lignes);
        }

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string} */
    private function decider(Tenant $ecole): array
    {
        $installation = $ecole->cheminInstallationExistant();
        if ($installation === null) {
            return ['ignoree', ucfirst((string) $ecole->motifDossierIntrouvable())];
        }

        $pouls = new PoulsPlanificateur($installation);
        if (! $pouls->codeAJour()) {
            return ['ignoree', 'Code antérieur au pouls : déployez l\'école'];
        }

        $silenceCron = $pouls->silence('cron');
        if ($silenceCron !== null && $silenceCron <= self::SILENCE_MAX) {
            return ['cron_propre', "Sa tâche cron est passée il y a {$silenceCron} s"];
        }

        $silenceMaster = $pouls->silence('master');

        return ['lance', $silenceMaster === null
            ? 'Aucun planificateur ne tournait'
            : "Lancé par adminKlassci (dernier passage il y a {$silenceMaster} s)"];
    }
}
