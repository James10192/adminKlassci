<?php

namespace App\Services\Export;

use App\Domain\Exports\ExportableReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rend un ExportableReport en PDF ou en tableur.
 *
 * Seul endroit du projet qui appelle DomPDF et Excel. Un contrôleur qui
 * voudrait « juste un petit PDF » sans passer par ici recréerait un en-tête,
 * oublierait le pied paginé, et le jour où l'identité visuelle change il
 * resterait à la traîne.
 */
class ReportRenderer
{
    /** PDF affiché dans un onglet, sans téléchargement. */
    public function pdfPreview(ExportableReport $report): Response
    {
        return $this->pdfResponse($report, 'inline');
    }

    /** PDF téléchargé. */
    public function pdfDownload(ExportableReport $report): Response
    {
        return $this->pdfResponse($report, 'attachment');
    }

    /** Classeur téléchargé. */
    public function excelDownload(ExportableReport $report): BinaryFileResponse
    {
        $export = $report->excelExport();

        if ($export === null) {
            $this->refuse("« {$report->title()} » n'a pas de version tableur.");
        }

        $this->guardVolume(
            $report->rowCount(),
            $report->maxExcelRows(),
            'Le tableur est limité à :max lignes et la sélection en compte :count. Affinez les filtres.'
        );

        return Excel::download($export, $this->filename($report, 'xlsx'));
    }

    /** Octets bruts du PDF — pour l'envoi par e-mail et les tests. */
    public function pdfBytes(ExportableReport $report): string
    {
        $this->guardPdfVolume($report);

        $pdf = Pdf::loadView($report->pdfView(), $this->viewData($report))
            ->setPaper('a4', $report->orientation());

        $pdf->render();
        self::dessinePagination($pdf->getDomPDF());

        return $pdf->output();
    }

    /**
     * Le « Page N / M » du pied, dessine par le moteur.
     *
     * Il vivait dans le gabarit, en `content: counter(pages)`. DomPDF 3.1.6
     * resout `counter(page)` mais rend `counter(pages)` a ZERO — verifie a la
     * main sur un document de deux pages. Tous les rapports sortaient donc
     * « Page 1 / 0 », ce qui est pire qu'une pagination absente : un lecteur
     * qui recoit un tirage incomplet n'a aucun moyen de s'en apercevoir.
     *
     * Le placeholder `{PAGE_COUNT}`, lui, est substitue par le moteur au
     * moment de l'ecriture du fichier — mais uniquement par `page_text()`.
     * On ne passe donc PAS par `<script type="text/php">` : cela demanderait
     * d'activer l'execution de PHP dans les gabarits HTML, ce qui elargit la
     * surface d'attaque d'une application maitre pour un numero de page.
     */
    private static function dessinePagination(\Dompdf\Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $police = $metrics->getFont('DejaVu Sans', 'normal');

        if ($police === null) {
            return; // Plutot aucune pagination qu'une exception a l'export.
        }

        $taille = 7.5;
        $texte = 'Page {PAGE_NUM} / {PAGE_COUNT}';

        // La largeur se mesure sur le texte SUBSTITUE, pas sur le gabarit :
        // « {PAGE_NUM} / {PAGE_COUNT} » est trois fois plus large que « 1 / 3 »,
        // et l'alignement a droite serait calcule sur la mauvaise chaine.
        $largeur = $metrics->getTextWidth('Page 99 / 99', $police, $taille);

        $canvas->page_text(
            $canvas->get_width() - 34 - $largeur,
            $canvas->get_height() - 42,
            $texte,
            $police,
            $taille,
            [0.39, 0.45, 0.55], // #64748b, la couleur du pied
        );
    }

    private function pdfResponse(ExportableReport $report, string $disposition): Response
    {
        return new Response($this->pdfBytes($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="' . $this->filename($report, 'pdf') . '"',
        ]);
    }

    /**
     * La vue reçoit les données du rapport plus ce dont l'en-tête a besoin.
     *
     * Le rapport lui-même n'a pas à connaître le titre ni les filtres qu'il
     * a déclarés : c'est le composant d'en-tête qui les consomme.
     */
    private function viewData(ExportableReport $report): array
    {
        return array_merge($report->viewData(), [
            'reportTitle' => $report->title(),
            'reportSubtitle' => $report->subtitle(),
            'reportFilters' => $report->filters(),
            'reportOrientation' => $report->orientation(),
        ]);
    }

    private function guardPdfVolume(ExportableReport $report): void
    {
        $this->guardVolume(
            $report->rowCount(),
            $report->maxPdfRows(),
            'Ce PDF est limité à :max lignes et la sélection en compte :count. Affinez les filtres, ou prenez la version tableur.'
        );
    }

    /**
     * Refuse plutôt que de rendre une page blanche.
     *
     * DomPDF n'échoue pas franchement sur un gros tableau : il consomme la
     * mémoire jusqu'à la limite PHP et rend un document tronqué ou vide. Un
     * rapport vide ressemble à « il n'y a rien à signaler », ce qui est la
     * pire chose qu'un outil de direction puisse laisser croire.
     */
    private function guardVolume(int $count, int $max, string $message): void
    {
        if ($count <= $max) {
            return;
        }

        $this->refuse(strtr($message, [':max' => number_format($max, 0, ',', ' '), ':count' => number_format($count, 0, ',', ' ')]));
    }

    private function refuse(string $message): never
    {
        throw new HttpException(422, $message);
    }

    /** Nom de fichier daté — public pour que les écrans ne réinventent pas la convention. */
    public function filename(ExportableReport $report, string $extension): string
    {
        return $report->filenameBase() . '-' . now()->format('Y-m-d') . '.' . $extension;
    }
}
