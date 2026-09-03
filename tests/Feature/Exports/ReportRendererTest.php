<?php

use App\Domain\Exports\ExportableReport;
use App\Services\Export\ReportRenderer;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Le moteur de documents est vérifié en produisant vraiment un PDF et un
 * classeur, pas en observant qu'on a bien appelé DomPDF. Un test qui se
 * contente de compter les appels laisse passer exactement ce qui casse en
 * production : un en-tête qui ne compile pas, une image illisible, un
 * document de zéro octet.
 */
class ExportDemo implements FromArray, WithHeadings
{
    public function __construct(private int $lignes = 3) {}

    public function array(): array
    {
        return collect(range(1, $this->lignes))
            ->map(fn (int $i) => ["École {$i}", $i * 100, $i * 10.5])
            ->all();
    }

    public function headings(): array
    {
        return ['Établissement', 'Étudiants', 'Taux'];
    }
}

class RapportDemo extends ExportableReport
{
    public function __construct(private int $lignes = 3, private string $sens = 'portrait') {}

    public function title(): string
    {
        return 'Effectifs par établissement';
    }

    public function subtitle(): ?string
    {
        return 'Année universitaire 2025-2026';
    }

    public function filters(): array
    {
        return ['Périmètre' => 'Groupe entier', 'Filière' => 'Toutes'];
    }

    public function pdfView(): string
    {
        return 'tests.rapport-demo';
    }

    public function viewData(): array
    {
        return ['lignes' => (new ExportDemo($this->lignes))->array()];
    }

    public function rowCount(): int
    {
        return $this->lignes;
    }

    public function orientation(): string
    {
        return $this->sens;
    }

    public function excelExport(): ?object
    {
        return new ExportDemo($this->lignes);
    }
}

beforeEach(function () {
    // Vue jetable, écrite dans le dossier de vues réel puis retirée : rendre
    // un PDF suppose une vue sur disque, DomPDF ne prenant pas de chaîne.
    $this->vueDir = resource_path('views/tests');
    @mkdir($this->vueDir, 0755, true);
    file_put_contents($this->vueDir . '/rapport-demo.blade.php', <<<'BLADE'
    <x-report-document :title="$reportTitle" :subtitle="$reportSubtitle" :filters="$reportFilters">
        <table class="donnees">
            <thead><tr><th>Établissement</th><th class="num">Étudiants</th><th class="num">Taux</th></tr></thead>
            <tbody>
            @foreach($lignes as $l)
                <tr><td>{{ $l[0] }}</td><td class="num">{{ $l[1] }}</td><td class="num">{{ $l[2] }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </x-report-document>
    BLADE);
});

afterEach(function () {
    @unlink($this->vueDir . '/rapport-demo.blade.php');
    @rmdir($this->vueDir);
});

it('produit un PDF reel et non un fichier vide', function () {
    $octets = app(ReportRenderer::class)->pdfBytes(new RapportDemo);

    expect($octets)->toStartWith('%PDF-')
        ->and(strlen($octets))->toBeGreaterThan(3000);
});

it('sert l apercu en ligne et le telechargement en piece jointe', function () {
    $renderer = app(ReportRenderer::class);

    $apercu = $renderer->pdfPreview(new RapportDemo);
    $telechargement = $renderer->pdfDownload(new RapportDemo);

    expect($apercu->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($apercu->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->and($telechargement->headers->get('Content-Disposition'))->toStartWith('attachment;');
});

it('date le nom de fichier et le derive du titre', function () {
    $disposition = app(ReportRenderer::class)->pdfDownload(new RapportDemo)
        ->headers->get('Content-Disposition');

    expect($disposition)
        ->toContain('effectifs-par-etablissement')
        ->toContain(now()->format('Y-m-d'))
        ->toContain('.pdf');
});

it('produit un classeur reel, reconnaissable a sa signature ZIP', function () {
    Maatwebsite\Excel\Facades\Excel::fake();

    app(ReportRenderer::class)->excelDownload(new RapportDemo);

    Maatwebsite\Excel\Facades\Excel::assertDownloaded(
        'effectifs-par-etablissement-' . now()->format('Y-m-d') . '.xlsx',
        fn (ExportDemo $export) => count($export->array()) === 3
    );
});

it('refuse le PDF au-dela du seuil plutot que de rendre une page blanche', function () {
    config()->set('group_portal.exports.max_pdf_rows', 10);

    expect(fn () => app(ReportRenderer::class)->pdfBytes(new RapportDemo(50)))
        ->toThrow(HttpException::class);
});

it('dit combien de lignes et quoi faire quand il refuse', function () {
    config()->set('group_portal.exports.max_pdf_rows', 10);

    try {
        app(ReportRenderer::class)->pdfBytes(new RapportDemo(2500));
        $message = null;
    } catch (HttpException $e) {
        $message = $e->getMessage();
    }

    expect($message)
        ->toContain('2 500')
        ->toContain('10')
        ->toContain('Affinez les filtres');
});

it('laisse passer le tableur bien au-dela du seuil PDF', function () {
    Maatwebsite\Excel\Facades\Excel::fake();
    config()->set('group_portal.exports.max_pdf_rows', 10);
    config()->set('group_portal.exports.max_excel_rows', 50000);

    app(ReportRenderer::class)->excelDownload(new RapportDemo(2500));

    Maatwebsite\Excel\Facades\Excel::assertDownloaded(
        'effectifs-par-etablissement-' . now()->format('Y-m-d') . '.xlsx'
    );
});

it('refuse proprement un rapport sans version tableur', function () {
    $sansTableur = new class extends ExportableReport
    {
        public function title(): string
        {
            return 'Sans tableur';
        }

        public function pdfView(): string
        {
            return 'tests.rapport-demo';
        }

        public function viewData(): array
        {
            return ['lignes' => []];
        }

        public function rowCount(): int
        {
            return 0;
        }
    };

    expect(fn () => app(ReportRenderer::class)->excelDownload($sansTableur))
        ->toThrow(HttpException::class);
});

it('porte le titre, le sous-titre et les filtres dans le document', function () {
    // On relit le HTML compilé plutôt que le PDF : extraire du texte d'un PDF
    // demanderait une dépendance de plus pour vérifier ce que Blade a déjà
    // produit.
    $html = view('tests.rapport-demo', [
        'lignes' => [['École 1', 100, 10.5]],
        'reportTitle' => 'Effectifs par établissement',
        'reportSubtitle' => 'Année universitaire 2025-2026',
        'reportFilters' => ['Périmètre' => 'Groupe entier'],
        'reportOrientation' => 'portrait',
    ])->render();

    expect($html)
        ->toContain('Effectifs par établissement')
        ->toContain('Année universitaire 2025-2026')
        ->toContain('Périmètre')
        ->toContain('Groupe entier')
        // Pied paginé et identité du groupe
        ->toContain('page-num')
        ->toContain(config('group_portal.branding.name'));
});

it('embarque le logo dans le document plutot que d y mettre une URL', function () {
    $html = view('tests.rapport-demo', [
        'lignes' => [],
        'reportTitle' => 'X',
        'reportSubtitle' => null,
        'reportFilters' => [],
        'reportOrientation' => 'portrait',
    ])->render();

    // DomPDF ne suit pas les URL distantes, et on ne lui ouvre pas le réseau.
    expect($html)->toContain('data:image/');
});

it('ne laisse aucun appel direct a DomPDF ou Excel hors du moteur', function () {
    $suspects = [];

    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($fichiers as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }

        $chemin = $fichier->getPathname();

        if (str_contains($chemin, 'Services/Export/ReportRenderer.php')) {
            continue;
        }

        $source = file_get_contents($chemin);

        if (preg_match('/\bPdf::loadView\b|\bExcel::download\b/', $source) === 1) {
            $suspects[] = basename($chemin);
        }
    }

    expect($suspects)->toBe([]);
});
