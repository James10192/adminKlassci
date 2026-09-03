<?php

namespace App\Domain\Exports\Reports;

use App\Domain\Exports\TableauReport;
use App\Enums\TenantPlan;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Alerts\AlertPayload;
use App\Support\Duree;
use App\Support\EtatMesure;
use Illuminate\Support\Collection;

/**
 * L'état de santé des établissements et l'échéance de leurs abonnements.
 *
 * Ces deux informations existaient à l'écran — bandeau d'abonnement, page des
 * alertes — et nulle part ailleurs. Un fondateur qui prépare un conseil ou une
 * demande de financement ne pouvait ni les imprimer, ni les joindre, ni les
 * programmer par e-mail comme ses trois autres états.
 *
 * Le document ne fabrique aucun chiffre : il met en page ce que
 * `TenantAggregationService` a déjà calculé pour le portail. Recalculer ici
 * garantirait qu'un jour l'écran et le papier se contredisent.
 */
class SanteAbonnementsReport extends TableauReport
{
    /**
     * @param  Collection<int, Tenant>  $etablissements
     * @param  array<string, mixed>  $sante  Sortie de TenantAggregationService::getGroupHealth().
     */
    public function __construct(
        private readonly Collection $etablissements,
        private readonly array $sante,
        private readonly string $nomGroupe,
        private readonly string $periode,
    ) {
    }

    public function title(): string
    {
        return 'Santé et abonnements';
    }

    public function subtitle(): ?string
    {
        return $this->nomGroupe;
    }

    public function filters(): array
    {
        $total = $this->etablissements->count();

        $filtres = [
            'Situation au' => now()->format('d/m/Y'),
            'Période' => $this->periode,
        ];

        // Deux compteurs, parce que ce sont les deux questions qu'on se pose
        // avant d'ouvrir le tableau. Ils portent leur dénominateur : « 2 » seul
        // ne dit pas si c'est deux sur trois ou deux sur quarante.
        $echeance = (int) ($this->sante['subscription_expiring_total_count'] ?? 0);
        if ($echeance > 0) {
            $filtres['Abonnements à échéance'] = $echeance . ' sur ' . $total;
        }

        $enAlerte = $this->alertesParEtablissement()->count();
        if ($enAlerte > 0) {
            $filtres['Établissements en alerte'] = $enAlerte . ' sur ' . $total;
        }

        return $filtres;
    }

    public function orientation(): string
    {
        return 'landscape';
    }

    /**
     * Huit colonnes sur une page : les largeurs sont declarees, sinon DomPDF
     * repartit au juge et coupe « Dans 547 jours » en deux lignes pendant
     * qu'une colonne de dates garde le double de ce qu'il lui faut.
     */
    public function colonnes(): array
    {
        return [
            ['label' => 'Établissement', 'format' => self::TEXTE, 'largeur' => 15],
            ['label' => 'Offre', 'format' => self::TEXTE, 'largeur' => 9],
            ['label' => 'Statut', 'format' => self::TEXTE, 'largeur' => 8],
            ['label' => 'Fin d\'abonnement', 'format' => self::TEXTE, 'largeur' => 10],
            ['label' => 'Échéance', 'format' => self::TEXTE, 'largeur' => 12],
            ['label' => 'Utilisation max', 'format' => self::POURCENT, 'largeur' => 10],
            ['label' => 'Gravité', 'format' => self::TEXTE, 'largeur' => 10],
            ['label' => 'Points de vigilance', 'format' => self::TEXTE, 'largeur' => 26],
        ];
    }

    public function lignes(): array
    {
        $alertes = $this->alertesParEtablissement();
        $lignes = [];

        foreach ($this->etablissements as $etablissement) {
            $sien = $alertes->get($etablissement->code, collect());

            $lignes[] = [
                $etablissement->name,
                TenantPlan::libelleDe($etablissement->plan),
                TenantStatus::libelleDe($etablissement->status),
                $etablissement->subscription_end_date?->format('d/m/Y') ?? EtatMesure::TIRET,
                $this->echeance($etablissement),
                $this->utilisationMax($etablissement),
                $sien->isEmpty()
                    ? EtatMesure::TIRET
                    : $sien->sortBy(fn (AlertPayload $a): int => $a->severity->sortOrder())
                        ->first()->severity->libelle(),
                $sien->isEmpty()
                    ? EtatMesure::TIRET
                    : $sien->map(fn (AlertPayload $a): string => $a->message)->implode(' · '),
            ];
        }

        return $lignes;
    }

    /**
     * L'échéance en toutes lettres.
     *
     * Une colonne « jours restants » aurait imprimé « -12 » pour un abonnement
     * expiré depuis douze jours — un nombre négatif qu'il faut interpréter.
     * Ici la cellule se lit sans effort, et une absence de date reste un tiret
     * plutôt qu'un zéro.
     */
    private function echeance(Tenant $etablissement): string
    {
        $jours = $etablissement->daysRemaining();

        if ($jours === null) {
            return EtatMesure::TIRET;
        }

        if ($jours < 0) {
            return 'Expiré depuis ' . Duree::jours(abs($jours));
        }

        if ($jours === 0) {
            return "Expire aujourd'hui";
        }

        return 'Dans ' . Duree::jours($jours);
    }

    /**
     * Le quota le plus proche de sa limite, en pourcentage.
     *
     * Null quand aucun quota n'est fixé : un établissement sans plafond n'est
     * pas un établissement à 0 % d'utilisation. Le gabarit rend `null` par un
     * tiret, le tableur laisse la case vide.
     */
    private function utilisationMax(Tenant $etablissement): ?float
    {
        $rapports = [
            [$etablissement->current_users, $etablissement->max_users],
            [$etablissement->current_staff, $etablissement->max_staff],
            [$etablissement->current_students, $etablissement->max_students],
            [$etablissement->current_inscriptions_per_year, $etablissement->max_inscriptions_per_year],
            [$etablissement->current_storage_mb, $etablissement->max_storage_mb],
        ];

        $max = null;

        foreach ($rapports as [$courant, $plafond]) {
            if ((float) $plafond <= 0) {
                continue;
            }

            $pourcent = round(((float) $courant / (float) $plafond) * 100, 1);
            $max = $max === null ? $pourcent : max($max, $pourcent);
        }

        return $max;
    }

    /**
     * Les alertes du groupe, regroupées par code d'établissement.
     *
     * @return Collection<string, Collection<int, AlertPayload>>
     */
    private function alertesParEtablissement(): Collection
    {
        return collect($this->sante['alerts'] ?? [])
            ->map(fn ($alerte): AlertPayload => AlertPayload::from($alerte))
            ->filter(fn (AlertPayload $a): bool => $a->tenantCode !== null)
            ->groupBy(fn (AlertPayload $a): string => $a->tenantCode);
    }
}
