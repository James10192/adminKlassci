<?php

use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\TicketStateMachine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Care\Support;

/*
 * L'ecole joint un fichier a sa demande. Ce qui compte : seul ce que
 * l'assainissement produit est stocke, un renvoi ne double pas la piece, et
 * l'ecole ne lit que ses propres pieces.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->ecole = Support::instance('presentation');
    $this->jeton = Support::jeton($this->ecole, ['support:create', 'support:read', 'support:update']);
    $this->reference = $this->withToken($this->jeton)->withHeader('Idempotency-Key', 'cle-pj-000001')
        ->postJson('/api/v1/support/tickets', Support::soumission())->json('reference');
    $this->ticket = SupportTicket::where('reference', $this->reference)->firstOrFail();
});

function pngDeTest(int $l = 40, int $h = 30): string
{
    $im = imagecreatetruecolor($l, $h);
    imagefilledrectangle($im, 0, 0, $l - 1, $h - 1, imagecolorallocate($im, 4, 83, 203));
    ob_start();
    imagepng($im);

    return (string) ob_get_clean();
}

/** Un JPEG portant un segment EXIF avec une position GPS factice. */
function jpegAvecExif(): string
{
    $im = imagecreatetruecolor(20, 20);
    ob_start();
    imagejpeg($im);
    $jpeg = (string) ob_get_clean();
    $charge = "Exif\0\0GPSLatitude=5.3600;GPSLongitude=-4.0083";
    $app1 = "\xFF\xE1".pack('n', strlen($charge) + 2).$charge;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

/** Un JPEG 40x20, bord gauche rouge, dont l'EXIF dit « tourner de 90° dans le sens horaire » (Orientation = 6). */
function jpegOriente(): string
{
    $im = imagecreatetruecolor(40, 20);
    imagefilledrectangle($im, 0, 0, 9, 19, imagecolorallocate($im, 255, 0, 0));
    ob_start();
    imagejpeg($im);
    $jpeg = (string) ob_get_clean();
    $tiff = "MM\0*".pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('nn', 6, 0).pack('N', 0);
    $charge = "Exif\0\0".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($charge) + 2).$charge;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

/**
 * Un PDF lisible par un lecteur : chaque corps devient l'objet i+1, et une vraie
 * table xref les annonce. `$xrefForcee` fait pointer une entree ailleurs, pour
 * simuler un objet que le lecteur trouverait et pas nous.
 *
 * @param  list<string>  $corps
 * @param  array<int, int>  $xrefForcee  numero d'objet => position annoncee
 */
function pdfValide(array $corps, string $trailer = '', array $xrefForcee = []): string
{
    $pdf = "%PDF-1.5\n";
    $positions = [];
    foreach (array_values($corps) as $i => $c) {
        $positions[$i + 1] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n{$c}\nendobj\n";
    }
    $debut = strlen($pdf);
    $pdf .= "xref\n0 ".(count($positions) + 1)."\n0000000000 65535 f \n";
    foreach ($positions as $numero => $position) {
        $pdf .= sprintf("%010d 00000 n \n", $xrefForcee[$numero] ?? $position);
    }

    return $pdf.'trailer << /Size '.(count($positions) + 1)." /Root 1 0 R {$trailer} >>\nstartxref\n{$debut}\n%%EOF";
}

/** Le corps d'un objet flux, `/Length` compris. */
function flux(string $dictionnaire, string $donnees, string $finDeLigne = "\n"): string
{
    return "<< {$dictionnaire} /Length ".strlen($donnees)." >>\nstream{$finDeLigne}{$donnees}\nendstream";
}

/**
 * Un PDF 1.5 dont la table xref est un flux compresse, predicteur 12 compris,
 * comme en produisent Word et LibreOffice. `$enPlus` ajoute des entrees brutes
 * [type, champ 2, champ 3].
 *
 * @param  list<string>  $corps
 * @param  list<array{int, int, int}>  $enPlus
 */
function pdfAvecFluxXref(array $corps, array $enPlus = []): string
{
    $pdf = "%PDF-1.5\n";
    $positions = [];
    foreach (array_values($corps) as $i => $c) {
        $positions[] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n{$c}\nendobj\n";
    }
    $numero = count($positions) + 1;
    $positions[] = strlen($pdf);
    $lignes = "\x00\x00\x00\xFF";
    foreach ($positions as $position) {
        $lignes .= "\x01".pack('n', $position)."\x00";
    }
    foreach ($enPlus as [$type, $a, $b]) {
        $lignes .= chr($type).pack('n', $a).chr($b);
    }
    $taille = 1 + count($positions) + count($enPlus);
    $dict = "/Type /XRef /W [1 2 1] /Size {$taille} /Root 1 0 R /Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 4 >>";
    $pdf .= "{$numero} 0 obj\n".flux($dict, gzcompress(avecPredicteurUp($lignes, 4)))."\nendobj\n";

    return $pdf."startxref\n".end($positions)."\n%%EOF";
}

/** Un PDF dont les objets vivent dans un flux compresse, comme depuis PDF 1.5. */
function pdfAvecObjetsCompresses(string $objets): string
{
    return pdfAvecFlux('/Type /ObjStm /N 1 /First 0 /Filter /FlateDecode', gzcompress($objets));
}

function pdfAvecFlux(string $dictionnaire, string $donnees, string $finDeLigne = "\n"): string
{
    return pdfValide([flux($dictionnaire, $donnees, $finDeLigne)]);
}

/**
 * Le catalogue du document cache dans les donnees d'une image, jamais lues : le
 * trailer le designe, la table xref ne le declare pas. Un lecteur reconstruit
 * alors la table en balayant le fichier, et le trouve.
 */
function pdfAvecRacineCacheeDansUneImage(): string
{
    $cache = '9 0 obj << /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj';
    $pdf = "%PDF-1.5\n1 0 obj\n".flux('/Type /XObject /Subtype /Image /Filter /DCTDecode', "\xFF\xD8 {$cache} \xFF\xD9")."\nendobj\n";
    $table = strlen($pdf);

    return $pdf."xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \ntrailer << /Size 2 /Root 9 0 R >>\nstartxref\n{$table}\n%%EOF";
}

/** Une table xref juste, mais que startxref ne designe pas : le lecteur la reconstruit. */
function pdfAvecStartxrefFaux(): string
{
    return preg_replace('/startxref\n\d+/', 'startxref\n3', pdfValide(['<< /Type /Catalog >>']));
}

/** Une image dont les donnees imitent un objet, que la table xref designe. */
function pdfAvecObjetCacheDansUneImage(): string
{
    $corps = [
        flux('/Type /XObject /Subtype /Image /Filter /DCTDecode', "\xFF\xD8 2 0 obj << /OpenAction 3 0 R >> endobj \xFF\xD9"),
        '<< /Type /Catalog >>',
    ];

    return pdfValide($corps, '', [2 => strpos(pdfValide($corps), '2 0 obj << /Open')]);
}

/**
 * Deux tables, comme une mise a jour incrementale. La plus ancienne (A) declare le
 * catalogue 9 et l'image 1 ; la plus recente (B), celle que designe startxref, ne
 * declare que l'image, dont les donnees imitent un second catalogue 9 porteur de
 * JavaScript. `$trailerB` complete le trailer de B : sans `/Prev`, la chaine du
 * lecteur ne mene pas au catalogue, et il reconstruit — trouvant le faux.
 */
function pdfADeuxTables(string $trailerB, string $entreesB = ''): string
{
    $faux = '9 0 obj << /Type /Catalog /OpenAction << /S /JavaScript /JS (x) >> >> endobj';
    $pdf = "%PDF-1.5\n";
    $catalogue = strlen($pdf);
    $pdf .= "9 0 obj\n<< /Type /Catalog >>\nendobj\n";
    $image = strlen($pdf);
    $pdf .= "1 0 obj\n".flux('/Type /XObject /Subtype /Image /Filter /DCTDecode', "\xFF\xD8 {$faux} \xFF\xD9")."\nendobj\n";
    $a = strlen($pdf);
    $pdf .= "xref\n1 1\n".sprintf('%010d', $image)." 00000 n \n9 1\n".sprintf('%010d', $catalogue)." 00000 n \n";
    $pdf .= "trailer << /Size 10 /Root 9 0 R >>\n";
    $b = strlen($pdf);
    $pdf .= "xref\n1 1\n".sprintf('%010d', $image)." 00000 n \n{$entreesB}";

    return $pdf.'trailer << /Size 10 /Root 9 0 R '.str_replace(['{A}', '{B}'], [$a, $b], $trailerB).">>\nstartxref\n{$b}\n%%EOF";
}

/** Un flux deflate brut dont les premiers octets, stockes tels quels, contiennent le mot `endstream`. */
function deflateAvecEndstreamLitteral(string $objets): string
{
    $leurre = 'endstream';

    return "\x00".pack('v', strlen($leurre)).pack('v', ~strlen($leurre) & 0xFFFF).$leurre.gzdeflate($objets);
}

/** Predicteur PNG « Up » (2) sur des lignes de `$largeur` octets, comme les flux xref. */
function avecPredicteurUp(string $donnees, int $largeur): string
{
    $sortie = '';
    $precedente = str_repeat("\0", $largeur);
    foreach (str_split(str_pad($donnees, (int) ceil(strlen($donnees) / $largeur) * $largeur), $largeur) as $ligne) {
        $sortie .= "\x02";
        for ($i = 0; $i < $largeur; $i++) {
            $sortie .= chr((ord($ligne[$i]) - ord($precedente[$i])) & 0xFF);
        }
        $precedente = $ligne;
    }

    return $sortie;
}

function joindre($test, string $ref, string $contenu, string $nom = 'capture.png', string $cle = 'pj-cle-000001', ?string $jeton = null, string $requete = 'reporter=42')
{
    return $test->withToken($jeton ?? $test->jeton)->withHeader('Idempotency-Key', $cle)
        ->post("/api/v1/support/tickets/{$ref}/attachments?{$requete}", [
            'fichier' => UploadedFile::fake()->createWithContent($nom, $contenu),
            'author_name' => 'Awa Koné',
        ], ['Accept' => 'application/json']);
}

it('stocke la piece sur le disque prive et la montre dans la demande', function () {
    joindre($this, $this->reference, pngDeTest())->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'false')
        ->assertJsonPath('pieces_jointes.0.nom', 'capture.png')
        ->assertJsonPath('pieces_jointes.0.type', 'image/png')
        ->assertJsonPath('pieces_jointes.0.auteur', 'ECOLE');

    $piece = $this->ticket->piecesJointes()->sole();
    Storage::disk('local')->assertExists($piece->path);
    expect($piece->path)->toStartWith("care/{$this->ecole->id}/{$this->ticket->id}/")
        ->and([$piece->width, $piece->height])->toBe([40, 30])
        ->and($this->ticket->events()->where('type', TypeEvenement::PieceJointeClient->value)->count())->toBe(1);
});

it('retire les metadonnees d une photo', function () {
    joindre($this, $this->reference, jpegAvecExif(), 'photo.jpg')->assertCreated();

    $stocke = Storage::disk('local')->get($this->ticket->piecesJointes()->sole()->path);
    expect($stocke)->not->toContain('GPSLatitude')->not->toContain('Exif');
});

it('lit le type sur le contenu, pas sur le nom', function () {
    joindre($this, $this->reference, '<html><script>alert(1)</script></html>', 'capture.png')
        ->assertStatus(422)->assertJsonPath('error', 'attachment_rejected');

    expect($this->ticket->piecesJointes()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuse un PDF qui porte du contenu actif et accepte un PDF simple', function () {
    joindre($this, $this->reference, "%PDF-1.4\n1 0 obj << /OpenAction << /JS (app.alert(1)) >> >> endobj\n%%EOF", 'doc.pdf')
        ->assertStatus(422)->assertJsonPath('error', 'attachment_rejected');

    joindre($this, $this->reference, pdfValide(['<< /Type /Catalog >>']), 'releve.pdf', 'pj-cle-000002')
        ->assertCreated()->assertJsonPath('pieces_jointes.0.type', 'application/pdf');
});

it('ne double pas une piece renvoyee avec la meme cle', function () {
    joindre($this, $this->reference, pngDeTest())->assertCreated();
    joindre($this, $this->reference, pngDeTest())->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    joindre($this, $this->reference, pngDeTest(50, 50))->assertStatus(422)->assertJsonPath('error', 'idempotency_key_reused');

    expect($this->ticket->piecesJointes()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('refuse au dela du plafond par demande sans laisser de fichier', function () {
    config(['care.pieces_jointes.par_demande_max' => 1]);
    joindre($this, $this->reference, pngDeTest())->assertCreated();

    joindre($this, $this->reference, pngDeTest(10, 10), cle: 'pj-cle-000002')
        ->assertStatus(422)->assertJsonPath('error', 'attachment_rejected');

    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('refuse une demande close et rend la main au support sur une demande qui attendait l ecole', function () {
    $etats = app(TicketStateMachine::class);
    $etats->franchir($this->ticket, StatutTicket::WaitingCustomer, Acteur::systeme());
    joindre($this, $this->reference, pngDeTest())->assertCreated();
    expect($this->ticket->fresh()->status)->toBe(StatutTicket::WaitingSupport);

    $etats->franchir($this->ticket->fresh(), StatutTicket::Rejected, Acteur::systeme(), 'Hors périmètre du support.');
    joindre($this, $this->reference, pngDeTest(10, 10), cle: 'pj-cle-000002')
        ->assertStatus(409)->assertJsonPath('error', 'ticket_closed');
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('ne joint et ne rend une piece que la ou l ecole peut lire', function () {
    joindre($this, $this->reference, pngDeTest())->assertCreated();
    $piece = $this->ticket->piecesJointes()->sole();

    $this->withToken($this->jeton)->get("/api/v1/support/tickets/{$this->reference}/attachments/{$piece->id}?reporter=42")
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

    $autre = Support::jeton(Support::instance('hetec'), ['support:read', 'support:update']);
    $this->withToken($autre)->get("/api/v1/support/tickets/{$this->reference}/attachments/{$piece->id}?reporter=42")->assertNotFound();
    $this->withToken($this->jeton)->get("/api/v1/support/tickets/{$this->reference}/attachments/{$piece->id}?reporter=7")->assertNotFound();
    joindre($this, $this->reference, pngDeTest(), jeton: $autre)->assertNotFound();
});

it('exige la portee support:update et une cle', function () {
    joindre($this, $this->reference, pngDeTest(), jeton: Support::jeton($this->ecole))
        ->assertForbidden()->assertJsonPath('error', 'insufficient_scope');

    $this->withToken($this->jeton)->flushHeaders()->withToken($this->jeton)
        ->post("/api/v1/support/tickets/{$this->reference}/attachments?reporter=42", ['fichier' => UploadedFile::fake()->createWithContent('a.png', pngDeTest())], ['Accept' => 'application/json'])
        ->assertStatus(400)->assertJsonPath('error', 'idempotency_key_required');
});

it('refuse un PDF dont le contenu actif est echappe ou compresse', function () {
    joindre($this, $this->reference, "%PDF-1.4\n1 0 obj << /Open#41ction << /S /J#61vaScript /J#53 (x) >> >> endobj\n%%EOF", 'a.pdf')
        ->assertStatus(422)->assertJsonPath('error', 'attachment_rejected');
    joindre($this, $this->reference, pdfAvecObjetsCompresses('<< /OpenAction << /S /JavaScript >> >>'), 'b.pdf', 'pj-cle-000002')
        ->assertStatus(422)->assertJsonPath('error', 'attachment_rejected');
    joindre($this, $this->reference, pdfAvecObjetsCompresses('<< /Type /Page >>'), 'c.pdf', 'pj-cle-000003')
        ->assertCreated();
});

it('refuse une image aux dimensions excessives avant de la decoder, et reduit une grande image', function () {
    config(['care.pieces_jointes.pixels_max' => 1000, 'care.pieces_jointes.cote_max_px' => 20]);
    joindre($this, $this->reference, pngDeTest(40, 30))->assertStatus(422)->assertJsonPath('error', 'attachment_rejected');

    config(['care.pieces_jointes.pixels_max' => 24_000_000]);
    joindre($this, $this->reference, pngDeTest(40, 30), cle: 'pj-cle-000002')->assertCreated();
    expect([$this->ticket->piecesJointes()->sole()->width, $this->ticket->piecesJointes()->sole()->height])->toBe([20, 15]);
});

it('redresse une photo de telephone selon son orientation EXIF', function () {
    joindre($this, $this->reference, jpegOriente(), 'photo.jpg')->assertCreated();

    $piece = $this->ticket->piecesJointes()->sole();
    expect([$piece->width, $piece->height])->toBe([20, 40]);

    // Tourne dans le bon sens : le bord gauche rouge est devenu le haut, pas le bas.
    $im = imagecreatefromstring(Storage::disk('local')->get($piece->path));
    $rouge = fn (int $x, int $y) => (imagecolorat($im, $x, $y) >> 16) & 0xFF;
    expect($rouge(10, 2))->toBeGreaterThan(180)->and($rouge(10, 37))->toBeLessThan(80);
});

it('refuse un PDF qu il ne sait pas lire au lieu de l accepter', function (string $pdf) {
    joindre($this, $this->reference, $pdf, 'x.pdf')->assertStatus(422)->assertJsonPath('error', 'attachment_rejected');
})->with([
    'endstream litteral dans le flux compresse' => fn () => pdfAvecFlux('/Type /ObjStm /Filter /FlateDecode', deflateAvecEndstreamLitteral('<< /OpenAction << /S /JavaScript >> >>')),
    'fin de ligne CR seule' => fn () => pdfAvecFlux('/Type /ObjStm /Filter /FlateDecode', gzcompress('<< /S /JavaScript >>'), "\r"),
    'chaine de filtres inconnue' => fn () => pdfAvecFlux('/Type /ObjStm /Filter [/ASCIIHexDecode /FlateDecode]', bin2hex(gzcompress('<< /S /JavaScript >>')).'>'),
    'filtre indirect' => fn () => pdfAvecFlux('/Type /ObjStm /Filter 5 0 R', gzcompress('<< /S /JavaScript >>')),
    'chiffre' => fn () => pdfValide(['<< /Type /Catalog >>'], '/Encrypt 2 0 R'),
    'mot stream dans une chaine' => fn () => pdfValide(["<< /Title (a stream\nb) >>"]),
    'mot stream en fin de commentaire' => fn () => pdfValide(["<< /Type /Catalog >> % stream"]),
    'parametres indirects' => fn () => pdfAvecFlux('/Filter /FlateDecode /DecodeParms 7 0 R', gzcompress('BT ET')),
    'colonnes indirectes' => fn () => pdfAvecFlux('/Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 4 0 R >>', gzcompress(avecPredicteurUp('BT ET', 4))),
    'double compression' => fn () => pdfAvecFlux('/Filter [/FlateDecode /FlateDecode]', gzcompress(gzcompress('<< /S /JavaScript >>'))),
    'flux d objets deguise en image' => fn () => pdfAvecFlux('/Type /ObjStm /Subtype /Image /N 1 /First 0 /Filter /CCITTFaxDecode', 'donnees'),
    'objet cache dans une image' => fn () => pdfAvecObjetCacheDansUneImage(),
    'objet compresse dans un flux jamais lu' => fn () => pdfAvecFluxXref(['<< /Type /Catalog >>'], [[2, 9, 0]]),
    'ouverture sur une action' => fn () => pdfValide(['<< /Type /Catalog /OpenAction << /S /URI /URI (http://x.test) >> >>']),
    'ouverture indirecte' => fn () => pdfValide(['<< /Type /Catalog /OpenAction 2 0 R >>', '<< /S /URI /URI (http://x.test) >>']),
    'ouverture sur une action compressee' => fn () => pdfAvecObjetsCompresses('<< /OpenAction << /S /URI /URI (http://x.test) >> >>'),
    'racine cachee dans une image' => fn () => pdfAvecRacineCacheeDansUneImage(),
    'startxref qui ne designe pas la table' => fn () => pdfAvecStartxrefFaux(),
    'entree xref sur un autre objet' => fn () => str_replace('0 3', '0 3', pdfValide(['<< /Type /Catalog >>', '<< /Type /Page >>'], '', [2 => 9])),
    'racine absente de la table' => fn () => str_replace('/Root 1 0 R', '/Root 7 0 R', pdfValide(['<< /Type /Catalog >>'])),
    'prev qui ne designe rien' => fn () => pdfValide(['<< /Type /Catalog >>'], '/Prev 3'),
    'ouverture d un fichier distant' => fn () => pdfValide(['<< /Type /Catalog >>', '<< /Type /Annot /A << /S /GoToR /F (x.pdf) /D [0 /Fit] >> >>']),
    'ouverture par reference sur une action' => fn () => pdfValide(['<< /Type /Catalog /OpenAction 2 0 R >>', '<< /S /Named /N /Print >>']),
    'sans table xref' => fn () => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF",
    'predicteur qui masque les noms' => fn () => pdfAvecFlux('/Type /ObjStm /Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 4 >>', gzcompress(avecPredicteurUp('<< /S /JavaScript >>', 4))),
    'flate illisible' => fn () => pdfAvecFlux('/Filter /FlateDecode', 'pas du deflate'),
    'racine declaree hors de la chaine startxref / Prev' => fn () => pdfADeuxTables(''),
    'racine liberee par la mise a jour' => fn () => pdfADeuxTables('/Prev {A} ', "9 1\n0000000000 00001 f \n"),
    'boucle de Prev' => fn () => pdfADeuxTables('/Prev {B} '),
    'table sans trailer' => fn () => preg_replace('/trailer << .*? >>\n/', '', pdfValide(['<< /Type /Catalog >>'])),
]);

it('accepte les flux qu il sait lire ou qui ne portent que des pixels', function (string $pdf) {
    joindre($this, $this->reference, $pdf, 'x.pdf')->assertCreated();
})->with([
    'flux xref avec predicteur' => fn () => pdfAvecFluxXref(['<< /Type /Catalog >>', flux('/Filter /FlateDecode', gzcompress('BT (Releve) Tj ET'))]),
    'image compressee jamais inflatee' => fn () => pdfAvecFlux('/Type /XObject /Subtype /Image /Filter /FlateDecode', random_bytes(4096)),
    'image JPEG' => fn () => pdfAvecFlux('/Type /XObject /Subtype /Image /Filter /DCTDecode', "\xFF\xD8\xFF\xE0 donnees binaires"),
    'deflate brut' => fn () => pdfAvecFlux('/Filter /FlateDecode', gzdeflate('BT /F1 12 Tf (Releve) Tj ET')),
    'page d ouverture, comme mPDF' => fn () => pdfValide(['<< /Type /Catalog /OpenAction [2 0 R /XYZ null null 1] >>', '<< /Type /Page >>']),
    'page d ouverture par reference, comme Ghostscript' => fn () => pdfValide(['<< /Type /Catalog /OpenAction 3 0 R >>', '<< /Type /Page >>', '[2 0 R /Fit]']),
    'page d ouverture compressee' => fn () => pdfAvecObjetsCompresses('<< /Type /Catalog /OpenAction [2 0 R /Fit] >>'),
    'signe' => fn () => pdfValide(['<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached >>']),
    'mise a jour incrementale reliee par Prev' => fn () => pdfADeuxTables('/Prev {A} '),
]);

it('reconnait un renvoi aux octets recus, sans refaire l assainissement', function () {
    joindre($this, $this->reference, pngDeTest())->assertCreated();

    $this->mock(\App\Domain\Care\Tickets\Services\AssainissementPieceJointe::class)->shouldNotReceive('assainir');
    joindre($this, $this->reference, pngDeTest())->assertOk()->assertHeader('Idempotent-Replayed', 'true');
});

it('retire le fichier ecrit quand la suite de l enregistrement echoue', function () {
    $this->mock(\App\Domain\Care\Tickets\Services\Journal::class)
        ->shouldReceive('consigner')->andThrow(new RuntimeException('journal indisponible'));

    joindre($this, $this->reference, pngDeTest())->assertStatus(500);

    expect($this->ticket->piecesJointes()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
