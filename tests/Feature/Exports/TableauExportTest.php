<?php

use App\Domain\Exports\TableauExport;
use App\Domain\Exports\TableauReport;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Le classeur, lu comme un destinataire le recevrait.
 *
 * Les tests qui suivent ouvrent réellement le .xlsx produit. C'est la seule
 * façon d'avoir vu ce qui manquait : le fichier n'ouvrait sur RIEN — pas de
 * groupe, pas de période, pas de réserve de consolidation. Le PDF disait
 * « incomplète — 4 établissements injoignables », le classeur du même envoi
 * se taisait.
 */
function classeur(TableauExport $export): array
{
    $chemin = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($chemin, Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));

    $zip = new ZipArchive();
    $zip->open($chemin);

    $contenu = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nom = $zip->getNameIndex($i);
        $contenu[$nom] = $zip->getFromIndex($i);
    }

    $zip->close();
    @unlink($chemin);

    return $contenu;
}

function exportDemo(): TableauExport
{
    return new TableauExport(
        [
            ['label' => 'Établissement', 'format' => TableauReport::TEXTE],
            ['label' => 'Masse brute', 'format' => TableauReport::FCFA],
            ['label' => 'Assiduité', 'format' => TableauReport::POURCENT],
        ],
        [['ESBTP Abidjan', 1_250_000.0, 87.4]],
        ['TOTAL GROUPE', 1_250_000.0, 87.4],
        'Masse salariale enseignante',
        'Groupe ROSTAN',
        ['Période de paie' => 'Septembre 2026', 'Consolidation' => 'incomplète — 2 établissements injoignables'],
    );
}

it('ouvre le classeur sur l identité du groupe, la période et les réserves', function () {
    $chaines = classeur(exportDemo())['xl/sharedStrings.xml'];

    expect($chaines)
        ->toContain('Masse salariale enseignante')
        ->toContain('Groupe ROSTAN')
        ->toContain('Septembre 2026')
        // La réserve de consolidation était dans le PDF et nulle part ailleurs.
        ->toContain('incomplète')
        ->toContain(config('group_portal.branding.name'));
});

it('laisse les lignes du tableau être des lignes de données', function () {
    // L'en-tête est écrit au-dessus, pas injecté dans le tableau : sinon le
    // premier tri d'Excel emporte le titre au milieu des établissements.
    $feuille = classeur(exportDemo())['xl/worksheets/sheet1.xml'];

    // Les colonnes commencent en ligne 7, les données en 8.
    expect(exportDemo()->startCell())->toBe('A7')
        ->and($feuille)->toContain('<row r="7"')
        ->and($feuille)->toContain('<row r="8"')
        // …et les en-têtes restent visibles au défilement.
        ->and($feuille)->toContain('state="frozen"');
});

it('nomme l onglet et le fichier d après le rapport, pas « Worksheet »', function () {
    $fichiers = classeur(exportDemo());

    expect($fichiers['xl/workbook.xml'])->toContain('Masse salariale enseignante')
        ->and($fichiers['xl/workbook.xml'])->not->toContain('name="Worksheet"')
        // Les propriétés du fichier disaient « Unknown Creator ».
        ->and($fichiers['docProps/core.xml'])->toContain('Masse salariale enseignante')
        ->and($fichiers['docProps/core.xml'])->not->toContain('Unknown Creator');
});

it('tronque un titre d onglet qu Excel refuserait', function () {
    // Au-delà de 31 caractères ou avec []:*?/\, Excel n'ouvre pas le fichier
    // — ce n'est pas l'onglet qui est refusé, c'est le classeur entier.
    $export = new TableauExport(
        [['label' => 'A', 'format' => TableauReport::TEXTE]],
        [],
        null,
        'Consolidation financière du groupe : périmètre complet [2026]',
    );

    expect(mb_strlen($export->title()))->toBeLessThanOrEqual(31)
        ->and($export->title())->not->toContain(':')
        ->and($export->title())->not->toContain('[');
});

it('habille les montants sans cesser d en faire des nombres', function () {
    $formats = exportDemo()->columnFormats();

    // La colonne texte n'a pas de masque, les deux autres en ont un.
    expect($formats)->not->toHaveKey('A')
        ->and($formats['B'])->toContain('FCFA')
        ->and($formats['C'])->toContain('%');

    // Et la cellule reste numérique : le masque est un affichage.
    $feuille = classeur(exportDemo())['xl/worksheets/sheet1.xml'];
    expect($feuille)->toContain('1250000');
});
