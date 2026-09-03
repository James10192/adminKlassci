<?php

namespace App\Domain\Exports\Reports;

use App\Domain\Exports\TableauReport;

/**
 * Coût enseignant du groupe, établissement par établissement.
 *
 * La colonne qui compte pour la direction est la masse BRUTE : l'école verse
 * le net à l'enseignant et reverse les retenues (ITS, CNPS) à l'État. Le net
 * seul sous-estimerait la sortie réelle, d'où les deux colonnes.
 */
class MasseSalarialeReport extends TableauReport
{
    /**
     * @param  array<string, mixed>  $payroll  Sortie de GroupPayrollProvider::computeGroupPayroll().
     */
    public function __construct(
        private readonly array $payroll,
        private readonly string $nomGroupe,
        private readonly string $periode,
    ) {
    }

    public function title(): string
    {
        return 'Masse salariale enseignante';
    }

    public function subtitle(): ?string
    {
        return $this->nomGroupe;
    }

    public function filters(): array
    {
        $filtres = [
            'Période de paie' => $this->periode,
            'Bulletins retenus' => 'validés et payés',
        ];

        $manquants = $this->etablissementsManquants();
        if ($manquants > 0) {
            // Écrit dans le document lui-même : un état imprimé circule sans
            // l'écran qui l'a produit, et un total incomplet qui ne le dit pas
            // est un chiffre faux.
            $filtres['Consolidation'] = "incomplète — {$manquants} établissement(s) injoignable(s)";
        }

        return $filtres;
    }

    public function orientation(): string
    {
        return 'landscape';
    }

    public function colonnes(): array
    {
        return [
            ['label' => 'Établissement', 'format' => self::TEXTE],
            ['label' => 'Enseignants', 'format' => self::NOMBRE],
            ['label' => 'Bulletins', 'format' => self::NOMBRE],
            ['label' => 'Masse brute', 'format' => self::FCFA],
            ['label' => 'Net versé', 'format' => self::FCFA],
            ['label' => 'Retenues', 'format' => self::FCFA],
            ['label' => 'Engagé non versé', 'format' => self::FCFA],
        ];
    }

    public function lignes(): array
    {
        $lignes = [];

        foreach ($this->payroll['establishments'] ?? [] as $paie) {
            $lignes[] = [
                ($paie['error'] ?? false)
                    ? ($paie['tenant_name'] ?? '—') . ' (non consolidé)'
                    : ($paie['tenant_name'] ?? '—'),
                (int) ($paie['enseignants'] ?? 0),
                (int) ($paie['bulletins'] ?? 0),
                (float) ($paie['masse_brute'] ?? 0),
                (float) ($paie['masse_versee'] ?? 0),
                (float) ($paie['retenues'] ?? 0),
                (float) ($paie['masse_engagee'] ?? 0),
            ];
        }

        return $lignes;
    }

    public function totaux(): ?array
    {
        if (empty($this->payroll['establishments'] ?? [])) {
            return null;
        }

        return [
            'TOTAL GROUPE',
            (int) ($this->payroll['enseignants'] ?? 0),
            (int) ($this->payroll['bulletins'] ?? 0),
            (float) ($this->payroll['masse_brute'] ?? 0),
            (float) ($this->payroll['masse_versee'] ?? 0),
            (float) ($this->payroll['retenues'] ?? 0),
            (float) ($this->payroll['masse_engagee'] ?? 0),
        ];
    }

    private function etablissementsManquants(): int
    {
        $manquants = 0;

        foreach ($this->payroll['establishments'] ?? [] as $paie) {
            if ($paie['error'] ?? false) {
                $manquants++;
            }
        }

        return $manquants;
    }
}
