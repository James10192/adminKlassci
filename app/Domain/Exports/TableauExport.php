<?php

namespace App\Domain\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Version tableur d'un TableauReport.
 *
 * Écrit les valeurs brutes : un montant reste un nombre, pas « 1,4 M FCFA ».
 * C'est la différence entre un classeur qu'on peut trier et sommer et une
 * capture d'écran déguisée en fichier Excel.
 */
class TableauExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize
{
    /**
     * @param  array<int, string>  $entetes
     * @param  array<int, array<int, string|int|float|null>>  $lignes
     * @param  array<int, string|int|float|null>|null  $totaux
     */
    public function __construct(
        private readonly array $entetes,
        private readonly array $lignes,
        private readonly ?array $totaux = null,
    ) {
    }

    public function array(): array
    {
        $lignes = $this->lignes;

        if ($this->totaux !== null) {
            $lignes[] = $this->totaux;
        }

        return $lignes;
    }

    public function headings(): array
    {
        return $this->entetes;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => ['font' => ['bold' => true]],
        ];

        // La ligne de total est mise en gras : sur un tableau long, elle se
        // perd sinon au milieu des établissements.
        if ($this->totaux !== null) {
            $ligneTotal = count($this->lignes) + 2; // +1 en-tête, +1 pour passer en 1-indexé
            $styles[$ligneTotal] = ['font' => ['bold' => true]];
        }

        return $styles;
    }
}
