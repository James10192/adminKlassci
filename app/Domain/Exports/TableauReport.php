<?php

namespace App\Domain\Exports;

/**
 * Rapport en forme de tableau : la forme que prennent tous les états du
 * portail groupe (comparaison par établissement, masse salariale, effectifs).
 *
 * Un rapport concret ne décrit que ses colonnes et ses lignes ; l'enveloppe
 * PDF, le tableur et les garde-fous viennent d'ici. Écrire trois vues Blade
 * quasi identiques serait trois endroits où corriger la même chose.
 *
 * Les lignes portent des valeurs BRUTES, pas des chaînes déjà formatées :
 * le PDF les met en forme à l'affichage, le tableur les écrit telles quelles.
 * Un directeur qui ouvre le classeur doit pouvoir trier et sommer ses
 * colonnes, ce qu'une cellule « 1,4 M FCFA » lui interdirait.
 */
abstract class TableauReport extends ExportableReport
{
    public const TEXTE = 'texte';
    public const FCFA = 'fcfa';
    public const NOMBRE = 'nombre';
    public const POURCENT = 'pourcent';

    /**
     * Colonnes du tableau, dans l'ordre.
     *
     * @return array<int, array{label: string, format?: string}>
     */
    abstract public function colonnes(): array;

    /**
     * Lignes, valeurs brutes, alignées sur l'ordre des colonnes.
     *
     * @return array<int, array<int, string|int|float|null>>
     */
    abstract public function lignes(): array;

    /**
     * Ligne de total, ou null si le tableau ne s'additionne pas.
     *
     * @return array<int, string|int|float|null>|null
     */
    public function totaux(): ?array
    {
        return null;
    }

    final public function pdfView(): string
    {
        return 'reports.tableau';
    }

    final public function viewData(): array
    {
        return [
            'colonnes' => $this->colonnes(),
            'lignes' => $this->lignes(),
            'totaux' => $this->totaux(),
        ];
    }

    public function rowCount(): int
    {
        return count($this->lignes());
    }

    public function excelExport(): ?object
    {
        return new TableauExport(
            array_map(fn (array $c) => $c['label'], $this->colonnes()),
            $this->lignes(),
            $this->totaux(),
        );
    }
}
