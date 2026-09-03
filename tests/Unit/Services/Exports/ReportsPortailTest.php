<?php

use App\Domain\Exports\Reports\ConsolidationFinanciereReport;
use App\Domain\Exports\Reports\EtatEtablissementsReport;
use App\Domain\Exports\Reports\MasseSalarialeReport;
use App\Domain\Exports\TableauReport;
use App\Services\Export\ReportRenderer;

/**
 * Les trois états que la direction sort du portail. On vérifie qu'ils
 * produisent vraiment un document, et surtout que les chiffres qu'ils portent
 * sont ceux qu'on croit : un rapport faux circule en réunion, il ne lève pas
 * d'exception.
 */
function financialsDemo(): array
{
    return [
        // Un gros établissement très bien recouvré et un petit qui ne l'est pas.
        'esbtp-abidjan' => [
            'tenant_name' => 'ESBTP Abidjan',
            'revenue_expected' => 10_000_000.0,
            'revenue_collected' => 9_000_000.0,
            'outstanding' => 1_000_000.0,
            'collection_rate' => 90.0,
        ],
        'hetec' => [
            'tenant_name' => 'HETEC',
            'revenue_expected' => 100_000.0,
            'revenue_collected' => 10_000.0,
            'outstanding' => 90_000.0,
            'collection_rate' => 10.0,
        ],
    ];
}

it('produit un PDF réel pour la consolidation financière', function () {
    $report = new ConsolidationFinanciereReport(financialsDemo(), 'Groupe ESBTP', 'Année en cours');

    $octets = app(ReportRenderer::class)->pdfBytes($report);

    expect($octets)->toStartWith('%PDF-')
        ->and(strlen($octets))->toBeGreaterThan(3000);
});

it('recalcule le taux du groupe sur les totaux au lieu de moyenner les établissements', function () {
    // La moyenne simple des deux taux donnerait 50 % et laisserait croire que
    // le groupe recouvre une fois sur deux. Pondéré, il recouvre 89,1 %.
    $totaux = (new ConsolidationFinanciereReport(financialsDemo(), 'Groupe ESBTP', 'Année en cours'))->totaux();

    expect($totaux[1])->toBe(10_100_000.0)
        ->and($totaux[2])->toBe(9_010_000.0)
        ->and($totaux[4])->toBe(89.2)
        ->and($totaux[4])->not->toBe(50.0);
});

it('écrit des nombres bruts dans le classeur, pas des montants déjà mis en forme', function () {
    // Un directeur doit pouvoir trier et sommer ses colonnes dans Excel.
    // Une cellule « 9,0 M FCFA » est une capture d'écran déguisée en fichier.
    $lignes = (new ConsolidationFinanciereReport(financialsDemo(), 'G', 'P'))->excelExport()->array();

    expect($lignes[0][1])->toBe(10_000_000.0)
        ->and($lignes[0][1])->toBeFloat()
        ->and($lignes[0][0])->toBe('ESBTP Abidjan');
});

it('ajoute la ligne de total au classeur', function () {
    $lignes = (new ConsolidationFinanciereReport(financialsDemo(), 'G', 'P'))->excelExport()->array();

    expect($lignes)->toHaveCount(3)
        ->and($lignes[2][0])->toBe('TOTAL GROUPE');
});

it('ne fabrique pas de total pour un tableau vide', function () {
    $report = new ConsolidationFinanciereReport([], 'Groupe ESBTP', 'Année en cours');

    expect($report->totaux())->toBeNull()
        ->and($report->rowCount())->toBe(0);
});

it('rend un document lisible même sans aucune donnée', function () {
    // Le cas arrive : groupe neuf, ou toutes les bases injoignables. Le PDF
    // doit le dire, pas sortir un tableau à en-têtes vides.
    $report = new ConsolidationFinanciereReport([], 'Groupe ESBTP', 'Année en cours');

    $html = view('reports.tableau', array_merge($report->viewData(), [
        'reportTitle' => $report->title(),
        'reportSubtitle' => $report->subtitle(),
        'reportFilters' => $report->filters(),
        'reportOrientation' => $report->orientation(),
    ]))->render();

    expect($html)->toContain('Aucune donnée sur la période retenue');
});

it('marque dans la masse salariale les établissements qui n ont pas répondu', function () {
    $payroll = [
        'masse_brute' => 1_000_000.0, 'masse_versee' => 900_000.0, 'retenues' => 100_000.0,
        'masse_engagee' => 0.0, 'bulletins' => 10, 'enseignants' => 8,
        'establishments' => [
            'a' => [
                'tenant_name' => 'ESBTP Abidjan', 'masse_brute' => 1_000_000.0, 'masse_versee' => 900_000.0,
                'retenues' => 100_000.0, 'masse_engagee' => 0.0, 'bulletins' => 10, 'enseignants' => 8,
                'error' => false,
            ],
            'b' => [
                'tenant_name' => 'HETEC', 'masse_brute' => 0.0, 'masse_versee' => 0.0,
                'retenues' => 0.0, 'masse_engagee' => 0.0, 'bulletins' => 0, 'enseignants' => 0,
                'error' => true,
                'etat' => \App\Support\EtatMesure::NON_MESURE,
                'motif' => \App\Support\EtatMesure::MOTIF_INJOIGNABLE,
            ],
        ],
    ];

    $report = new MasseSalarialeReport($payroll, 'Groupe ESBTP', 'Septembre 2026');

    // Dans le tableau, la ligne le dit… et elle le dit en français courant.
    // « (non consolidé) » signifie, en SYSCOHADA comme en IFRS, « retraitements
    // de consolidation non effectués » — pas « manquant ». Suivi de zéros sur la
    // même ligne, l'état affirmait devant un banquier un fait qui n'était pas
    // le nôtre.
    expect($report->lignes()[1][0])->toBe('HETEC (non mesuré)');

    // Et les colonnes chiffrées sont vides, pas à zéro : un zéro affirme qu'on
    // sait et qu'il n'y a rien. La vue PDF rend `null` par un tiret.
    expect($report->lignes()[1][1])->toBeNull();
    expect($report->lignes()[1][3])->toBeNull();

    // …et le bandeau de tête aussi, parce qu'un état imprimé circule sans
    // l'écran qui l'a produit.
    expect($report->filters())
        ->toHaveKey('Consolidation')
        ->and($report->filters()['Consolidation'])->toContain('incomplète');
});

it('ne crie pas à la consolidation incomplète quand tout a répondu', function () {
    $payroll = [
        'masse_brute' => 0.0, 'masse_versee' => 0.0, 'retenues' => 0.0, 'masse_engagee' => 0.0,
        'bulletins' => 0, 'enseignants' => 0,
        'establishments' => [
            'a' => ['tenant_name' => 'ESBTP Abidjan', 'masse_brute' => 0.0, 'masse_versee' => 0.0,
                    'retenues' => 0.0, 'masse_engagee' => 0.0, 'bulletins' => 0, 'enseignants' => 0, 'error' => false],
        ],
    ];

    expect((new MasseSalarialeReport($payroll, 'G', 'P'))->filters())->not->toHaveKey('Consolidation');
});

it('produit un PDF réel pour l état des établissements', function () {
    $kpis = [
        'total_students' => 2100, 'total_inscriptions' => 2150, 'total_staff' => 90,
        'avg_attendance_rate' => 87.4, 'collection_rate' => 71.2,
        'establishments' => [
            'a' => [
                'tenant_name' => 'ESBTP Abidjan', 'academic_year' => '2025-2026', 'students' => 2100,
                'inscriptions' => 2150, 'staff' => 90, 'attendance_rate' => 87.4,
                'collection_rate' => 71.2, 'plan' => 'elite', 'error' => false,
            ],
        ],
    ];

    $octets = app(ReportRenderer::class)->pdfBytes(new EtatEtablissementsReport($kpis, 'Groupe ESBTP', 'Année en cours'));

    expect($octets)->toStartWith('%PDF-');
});

it('nomme le fichier d après le titre du rapport', function () {
    $report = new MasseSalarialeReport(['establishments' => []], 'G', 'P');

    expect(app(ReportRenderer::class)->filename($report, 'xlsx'))
        ->toStartWith('masse-salariale-enseignante-')
        ->toEndWith('.xlsx');
});

it('déclare des colonnes numériques pour les montants et des colonnes texte pour les noms', function () {
    // Le format pilote l'alignement du PDF et l'écriture du classeur ; une
    // colonne mal typée sort un montant aligné à gauche et non sommable.
    $colonnes = (new ConsolidationFinanciereReport([], 'G', 'P'))->colonnes();

    expect($colonnes[0]['format'])->toBe(TableauReport::TEXTE)
        ->and($colonnes[1]['format'])->toBe(TableauReport::FCFA)
        ->and($colonnes[4]['format'])->toBe(TableauReport::POURCENT);
});

it('ne fait pas cohabiter deux conventions dans le même tableau imprimé', function () {
    // Les lignes par établissement renvoyaient déjà `null` quand rien n'était
    // mesuré, mais le TOTAL imprimait encore de vrais zéros. Le cas dur est un
    // groupe d'UNE école : `mentionPerimetre()` se tait (elle ne commente pas
    // un groupe d'un seul établissement), et le PDF affichait alors
    // « HETEC (non mesuré) » sur des cases vides, suivi de « TOTAL GROUPE ·
    // 0 étudiant · 0,0 % » sans la moindre réserve. C'est le document qui
    // circule chez un banquier.
    $rapport = new \App\Domain\Exports\Reports\EtatEtablissementsReport([
        'establishments' => [
            'hetec' => [
                'tenant_name' => 'HETEC',
                'students' => 0, 'inscriptions' => 0, 'staff' => 0,
                'attendance_rate' => 0, 'collection_rate' => 0, 'plan' => 'elite',
                'etat_effectifs' => \App\Support\EtatMesure::NON_MESURE,
                'etat_personnel' => \App\Support\EtatMesure::NON_MESURE,
                'etat_finances' => \App\Support\EtatMesure::NON_MESURE,
                'etat_assiduite' => \App\Support\EtatMesure::NON_MESURE,
                'motif' => \App\Support\EtatMesure::MOTIF_INJOIGNABLE,
            ],
        ],
        'total_students' => 0, 'total_inscriptions' => 0, 'total_staff' => 0,
        'avg_attendance_rate' => 0, 'collection_rate' => 0,
        'perimetre' => [
            'effectifs' => ['etat' => \App\Support\EtatMesure::NON_MESURE, 'repondu' => 0, 'total' => 1],
            'personnel' => ['etat' => \App\Support\EtatMesure::NON_MESURE, 'repondu' => 0, 'total' => 1],
            'finances' => ['etat' => \App\Support\EtatMesure::NON_MESURE, 'repondu' => 0, 'total' => 1],
            'assiduite' => ['etat' => \App\Support\EtatMesure::NON_MESURE, 'repondu' => 0, 'total' => 1],
        ],
    ], 'Groupe Test', 'Année 2026');

    $totaux = $rapport->totaux();

    // Aucune cellule numérique ne doit affirmer un zéro que personne n'a mesuré.
    expect($totaux[2])->toBeNull();  // étudiants
    expect($totaux[3])->toBeNull();  // inscriptions
    expect($totaux[4])->toBeNull();  // personnel
    expect($totaux[5])->toBeNull();  // assiduité
    expect($totaux[6])->toBeNull();  // recouvrement
});

it('mentionne le périmètre de toutes les familles amputées, pas des seules finances', function () {
    // La mention se calculait sur `finances` mais était collée à côté des
    // colonnes étudiants / inscriptions / personnel : un total d'effectifs
    // amputé alors que les finances étaient complètes ne disait rien.
    $etats = fn (int $repondu) => ['etat' => \App\Support\EtatMesure::MESURE, 'repondu' => $repondu, 'total' => 4];

    $rapport = new \App\Domain\Exports\Reports\EtatEtablissementsReport([
        'establishments' => ['a' => ['tenant_name' => 'A', 'plan' => 'free']],
        'total_students' => 500, 'total_inscriptions' => 520, 'total_staff' => 30,
        'avg_attendance_rate' => 88.0, 'collection_rate' => 74.0,
        'perimetre' => [
            'effectifs' => $etats(2),   // amputé
            'personnel' => $etats(4),   // complet
            'finances' => $etats(4),    // complet
            'assiduite' => $etats(4),
        ],
    ], 'Groupe Test', 'Année 2026');

    expect($rapport->totaux()[0])->toContain('sur 2 des 4 établissements');
});
