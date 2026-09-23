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

    joindre($this, $this->reference, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF", 'releve.pdf', 'pj-cle-000002')
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
