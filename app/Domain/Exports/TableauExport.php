<?php

namespace App\Domain\Exports;

use App\Services\Group\GroupBranding;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithFreezePane;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Version tableur d'un TableauReport.
 *
 * Écrit les valeurs brutes : un montant reste un nombre, pas « 1,4 M FCFA ».
 * C'est la différence entre un classeur qu'on peut trier et sommer et une
 * capture d'écran déguisée en fichier Excel. Le format de nombre, lui, est un
 * habillage d'affichage : il donne les séparateurs de milliers sans rien
 * retirer à la cellule.
 *
 * Le classeur porte le même en-tête que le PDF — groupe, titre, période,
 * filtres appliqués. Il n'en portait aucun : la première ligne était celle des
 * colonnes. Un directeur qui recevait « masse-salariale-2026-09-03.xlsx » par
 * e-mail ne pouvait savoir ni de quel groupe ni de quelle période il parlait,
 * et surtout pas que la consolidation était incomplète — le PDF le disait,
 * le tableur le taisait. Deux documents du même envoi, deux vérités.
 */
class TableauExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomStartCell, WithDrawings, WithFreezePane, WithHeadings, WithProperties, WithStyles, WithTitle
{
    /** Première ligne du tableau proprement dit ; au-dessus vit l'en-tête. */
    private const LIGNE_ENTETES = 7;

    /**
     * @param  array<int, array{label: string, format?: string}>  $colonnes
     * @param  array<int, array<int, string|int|float|null>>  $lignes
     * @param  array<int, string|int|float|null>|null  $totaux
     * @param  array<string, string>  $filtres
     */
    public function __construct(
        private readonly array $colonnes,
        private readonly array $lignes,
        private readonly ?array $totaux = null,
        private readonly string $titre = 'Rapport',
        private readonly ?string $sousTitre = null,
        private readonly array $filtres = [],
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
        return array_map(static fn (array $c): string => $c['label'], $this->colonnes);
    }

    public function startCell(): string
    {
        return 'A' . self::LIGNE_ENTETES;
    }

    /** Les en-têtes restent visibles quand la liste dépasse l'écran. */
    public function freezePane(): string
    {
        return 'A' . (self::LIGNE_ENTETES + 1);
    }

    /**
     * Le nom de l'onglet, qui disait « Worksheet ».
     *
     * Excel refuse au-delà de 31 caractères et interdit []:*?/\ — un titre
     * refusé fait échouer l'écriture entière du fichier, pas seulement l'onglet.
     */
    public function title(): string
    {
        $propre = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $this->titre) ?? $this->titre;

        return mb_substr(trim($propre), 0, 31) ?: 'Rapport';
    }

    /** Les propriétés du fichier, qui annonçaient « Unknown Creator ». */
    public function properties(): array
    {
        return [
            'creator' => $this->branding()->name(),
            // Sans quoi le fichier reste signé « Unknown Creator » en second
            // auteur — visible dans les propriétés de tout classeur Excel.
            'lastModifiedBy' => auth('group')->user()?->name ?? $this->branding()->name(),
            'title' => $this->titre,
            'description' => $this->sousTitre ?? '',
            'company' => $this->branding()->name(),
            'category' => 'Portail groupe KLASSCI',
        ];
    }

    /**
     * Le format d'affichage par colonne, déduit de ce que le rapport déclare.
     *
     * La cellule reste numérique : c'est un masque, pas une chaîne. Sans lui,
     * une masse salariale s'affichait « 12450000 », que personne ne lit.
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->colonnes as $index => $colonne) {
            $lettre = Coordinate::stringFromColumnIndex($index + 1);

            $formats[$lettre] = match ($colonne['format'] ?? null) {
                TableauReport::FCFA => '# ##0" FCFA"',
                TableauReport::NOMBRE => '# ##0',
                TableauReport::POURCENT => '0.0" %"',
                default => null,
            };
        }

        return array_filter($formats);
    }

    /** Le logo du groupe, en haut à droite — la place qu'il occupe sur une facture. */
    public function drawings(): array
    {
        $chemin = $this->branding()->logoPath();

        // PhpSpreadsheet ne sait pas dessiner un SVG ni un WebP : un logo
        // vectoriel ferait échouer l'écriture du classeur. Mieux vaut un
        // classeur sans logo qu'un classeur qui ne s'ouvre pas.
        $extension = $chemin === null ? '' : strtolower(pathinfo($chemin, PATHINFO_EXTENSION));

        if ($chemin === null || ! in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true)) {
            return [];
        }

        $logo = new Drawing();
        $logo->setName('Logo');
        $logo->setDescription($this->branding()->name());
        $logo->setPath($chemin);
        $logo->setHeight(52);
        $logo->setCoordinates(Coordinate::stringFromColumnIndex(max(1, count($this->colonnes))) . '1');
        $logo->setOffsetX(2);
        $logo->setOffsetY(4);

        return [$logo];
    }

    public function styles(Worksheet $sheet): array
    {
        $primaire = ltrim($this->branding()->primaryHex(), '#');
        $derniere = Coordinate::stringFromColumnIndex(max(1, count($this->colonnes)));

        $this->ecrireEntete($sheet, $derniere, $primaire);

        $styles = [
            // La ligne des colonnes porte la couleur du groupe : c'est ce qui
            // fait qu'un classeur ressemble au portail dont il sort.
            self::LIGNE_ENTETES => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $primaire]],
            ],
        ];

        if ($this->totaux !== null) {
            // La ligne de total est mise en gras : sur un tableau long, elle se
            // perd sinon au milieu des établissements.
            $styles[self::LIGNE_ENTETES + count($this->lignes) + 1] = [
                'font' => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => 'thin']],
            ];
        }

        return $styles;
    }

    /**
     * L'en-tête au-dessus du tableau : qui, quoi, sur quelle période, avec
     * quelles réserves.
     *
     * Écrit ici plutôt que dans `array()` : les lignes du tableau doivent
     * rester des lignes de données, sinon le tri d'Excel emporte le titre.
     */
    private function ecrireEntete(Worksheet $sheet, string $derniere, string $primaire): void
    {
        $sheet->setCellValue('A1', $this->branding()->name());
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13)
            ->getColor()->setRGB($primaire);

        $sheet->setCellValue('A2', $this->titre);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(15);

        if (filled($this->sousTitre) && $this->sousTitre !== $this->branding()->name()) {
            $sheet->setCellValue('A3', $this->sousTitre);
            $sheet->getStyle('A3')->getFont()->getColor()->setRGB('475569');
        }

        if ($this->filtres !== []) {
            $morceaux = [];
            foreach ($this->filtres as $libelle => $valeur) {
                $morceaux[] = $libelle . ' : ' . $valeur;
            }

            $sheet->setCellValue('A4', implode('  ·  ', $morceaux));
            $sheet->getStyle('A4')->getFont()->getColor()->setRGB('475569');
        }

        $edite = 'Édité le ' . now()->format('d/m/Y à H:i');
        $auteur = auth('group')->user()?->name;
        $sheet->setCellValue('A5', $edite . ($auteur ? ' par ' . $auteur : ''));
        $sheet->getStyle('A5')->getFont()->setSize(9)->getColor()->setRGB('64748b');

        // Les colonnes s'ajustent au contenu (ShouldAutoSize) ; sans ce
        // fusionnement, le titre et la ligne de filtres — bien plus longs que
        // n'importe quel nom d'établissement — étireraient la colonne A sur
        // toute la largeur de l'écran.
        foreach (['A1', 'A2', 'A3', 'A4', 'A5'] as $cellule) {
            $sheet->mergeCells($cellule . ':' . $derniere . mb_substr($cellule, 1));
        }
    }

    private function branding(): GroupBranding
    {
        return app(GroupBranding::class);
    }
}
