<?php

namespace App\Domain\Exports\Reports;

use App\Domain\Exports\TableauReport;

/**
 * Recettes du groupe, établissement par établissement.
 *
 * C'est l'état que le directeur général sort pour un conseil : ce qui était
 * attendu, ce qui est rentré, ce qui manque, et où.
 */
class ConsolidationFinanciereReport extends TableauReport
{
    /**
     * @param  array<string, array<string, mixed>>  $financials  Sortie de GroupFinancialsProvider, par code établissement.
     */
    public function __construct(
        private readonly array $financials,
        private readonly string $nomGroupe,
        private readonly string $periode,
    ) {
    }

    public function title(): string
    {
        return 'Consolidation financière';
    }

    public function subtitle(): ?string
    {
        return $this->nomGroupe;
    }

    public function filters(): array
    {
        return [
            'Période' => $this->periode,
            'Établissements' => (string) count($this->financials),
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
            ['label' => 'Attendu', 'format' => self::FCFA],
            ['label' => 'Encaissé', 'format' => self::FCFA],
            ['label' => 'Reste à recouvrer', 'format' => self::FCFA],
            ['label' => 'Taux', 'format' => self::POURCENT],
        ];
    }

    public function lignes(): array
    {
        $lignes = [];

        foreach ($this->financials as $donnees) {
            $lignes[] = [
                $donnees['tenant_name'] ?? '—',
                (float) ($donnees['revenue_expected'] ?? 0),
                (float) ($donnees['revenue_collected'] ?? 0),
                (float) ($donnees['outstanding'] ?? 0),
                (float) ($donnees['collection_rate'] ?? 0),
            ];
        }

        return $lignes;
    }

    public function totaux(): ?array
    {
        if (empty($this->financials)) {
            return null;
        }

        $attendu = 0.0;
        $encaisse = 0.0;
        $reste = 0.0;

        foreach ($this->financials as $donnees) {
            $attendu += (float) ($donnees['revenue_expected'] ?? 0);
            $encaisse += (float) ($donnees['revenue_collected'] ?? 0);
            $reste += (float) ($donnees['outstanding'] ?? 0);
        }

        // Le taux du groupe se recalcule sur les totaux : faire la moyenne des
        // taux par établissement donnerait le même poids à une école de trente
        // élèves qu'à une de deux mille.
        $taux = $attendu > 0 ? min(100, round(($encaisse / $attendu) * 100, 1)) : 0.0;

        return ['TOTAL GROUPE', $attendu, $encaisse, $reste, $taux];
    }
}
