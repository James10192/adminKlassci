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

        return Pdf::loadView($report->pdfView(), $this->viewData($report))
            ->setPaper('a4', $report->orientation())
            ->output();
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
