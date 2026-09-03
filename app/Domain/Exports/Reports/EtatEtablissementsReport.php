<?php

namespace App\Domain\Exports\Reports;

use App\Domain\Exports\TableauReport;

/**
 * L'état des établissements : la vue que le directeur général adjoint
 * demandait — effectifs, personnel, assiduité, recouvrement, sur une page.
 */
class EtatEtablissementsReport extends TableauReport
{
    /**
     * @param  array<string, mixed>  $kpis  Sortie de GroupKpiProvider::computeGroupKpis().
     */
    public function __construct(
        private readonly array $kpis,
        private readonly string $nomGroupe,
        private readonly string $periode,
    ) {
    }

    public function title(): string
    {
        return 'État des établissements';
    }

    public function subtitle(): ?string
    {
        return $this->nomGroupe;
    }

    public function filters(): array
    {
        return [
            'Période' => $this->periode,
            'Effectifs' => 'année universitaire en cours',
        ];
    }

    public function orientation(): string
    {
        return 'landscape';
    }

    public function colonnes(): array
    {
        return [
            ['label' => 'Établissement', 'format' => self::TEXTE],
            ['label' => 'Année', 'format' => self::TEXTE],
            ['label' => 'Étudiants', 'format' => self::NOMBRE],
            ['label' => 'Inscriptions', 'format' => self::NOMBRE],
            ['label' => 'Personnel', 'format' => self::NOMBRE],
            ['label' => 'Assiduité', 'format' => self::POURCENT],
            ['label' => 'Recouvrement', 'format' => self::POURCENT],
            ['label' => 'Offre', 'format' => self::TEXTE],
        ];
    }

    public function lignes(): array
    {
        $lignes = [];

        foreach ($this->kpis['establishments'] ?? [] as $etablissement) {
            $lignes[] = [
                ($etablissement['error'] ?? false)
                    ? ($etablissement['tenant_name'] ?? '—') . ' (non consolidé)'
                    : ($etablissement['tenant_name'] ?? '—'),
                $etablissement['academic_year'] ?? '—',
                (int) ($etablissement['students'] ?? 0),
                (int) ($etablissement['inscriptions'] ?? 0),
                (int) ($etablissement['staff'] ?? 0),
                (float) ($etablissement['attendance_rate'] ?? 0),
                (float) ($etablissement['collection_rate'] ?? 0),
                $etablissement['plan'] ?? '—',
            ];
        }

        return $lignes;
    }

    public function totaux(): ?array
    {
        if (empty($this->kpis['establishments'] ?? [])) {
            return null;
        }

        return [
            'TOTAL GROUPE',
            '',
            (int) ($this->kpis['total_students'] ?? 0),
            (int) ($this->kpis['total_inscriptions'] ?? 0),
            (int) ($this->kpis['total_staff'] ?? 0),
            // Assiduité et recouvrement sont déjà pondérés par le fournisseur :
            // une moyenne simple donnerait le même poids à toutes les écoles.
            (float) ($this->kpis['avg_attendance_rate'] ?? 0),
            (float) ($this->kpis['collection_rate'] ?? 0),
            '',
        ];
    }
}
