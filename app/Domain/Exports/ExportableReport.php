<?php

namespace App\Domain\Exports;

/**
 * Un document que le portail sait produire en PDF et en tableur.
 *
 * Le contrat existe pour que rien, dans un contrôleur, n'appelle DomPDF ou
 * Excel directement. Une page exportable décrit son document — titre, vue,
 * données, filtres appliqués — et ReportRenderer se charge du reste :
 * l'en-tête aux couleurs du groupe, le pied paginé, les garde-fous de
 * volume, le nom de fichier.
 *
 * L'intérêt n'est pas l'abstraction pour elle-même : c'est que le jour où le
 * fondateur demande un logo différent ou une mention légale en pied de page,
 * ça se change à un seul endroit pour les douze rapports.
 */
abstract class ExportableReport
{
    /** Titre affiché en tête du document et dans le nom de fichier. */
    abstract public function title(): string;

    /** Vue Blade rendue en PDF. Doit envelopper son contenu dans x-report-document. */
    abstract public function pdfView(): string;

    /** Variables passées à la vue PDF. */
    abstract public function viewData(): array;

    /**
     * Nombre de lignes que porte le document.
     *
     * Sert aux garde-fous de volume : DomPDF ne rend pas un tableau de
     * cinq mille lignes, il épuise la mémoire du serveur mutualisé et
     * renvoie une page blanche — ce qui est le pire des échecs, parce qu'il
     * ressemble à un rapport vide plutôt qu'à une erreur.
     */
    abstract public function rowCount(): int;

    /** Sous-titre optionnel, sous le titre. */
    public function subtitle(): ?string
    {
        return null;
    }

    /**
     * Filtres appliqués, affichés en bandeau sous le titre.
     *
     * Un rapport filtré qui ne dit pas comment il l'est devient un piège :
     * le lecteur croit voir tout le groupe alors qu'il voit une filière.
     *
     * @return array<string, string> [libellé => valeur]
     */
    public function filters(): array
    {
        return [];
    }

    /** 'portrait' ou 'landscape'. */
    public function orientation(): string
    {
        return 'portrait';
    }

    /**
     * Export tableur, ou null si le document n'a pas de version chiffrée.
     *
     * Retourne une classe portant les concerns Maatwebsite (FromArray,
     * WithHeadings…).
     */
    public function excelExport(): ?object
    {
        return null;
    }

    /** Base du nom de fichier, sans extension ni date. */
    public function filenameBase(): string
    {
        $slug = \Illuminate\Support\Str::slug($this->title());

        return $slug !== '' ? $slug : 'rapport';
    }

    /** Au-delà, le PDF est refusé avec un message qui dit quoi faire. */
    public function maxPdfRows(): int
    {
        return (int) config('group_portal.exports.max_pdf_rows', 1000);
    }

    /** Au-delà, le tableur est refusé. Bien plus haut : Excel encaisse. */
    public function maxExcelRows(): int
    {
        return (int) config('group_portal.exports.max_excel_rows', 50000);
    }
}
