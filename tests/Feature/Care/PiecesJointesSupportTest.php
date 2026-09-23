<?php

use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Filament\Resources\SupportTicketResource\Pages\ViewSupportTicket;
use App\Http\Controllers\Care\PieceJointeSupportController;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Care\Support;

/*
 * Le support ouvre les pieces jointes depuis le dossier. Ce qui compte : le lien
 * est signe, et l'acces au dossier est re-verifie a chaque ouverture.
 */

beforeEach(function () {
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $ecole = Support::instance('presentation');
    $jeton = Support::jeton($ecole, ['support:create', 'support:read', 'support:update']);
    $ref = $this->withToken($jeton)->withHeader('Idempotency-Key', 'cle-pjs-00001')
        ->postJson('/api/v1/support/tickets', Support::soumission())->json('reference');
    $im = imagecreatetruecolor(12, 12);
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();
    $this->withToken($jeton)->withHeader('Idempotency-Key', 'pjs-cle-00001')
        ->post("/api/v1/support/tickets/{$ref}/attachments?reporter=42", ['fichier' => UploadedFile::fake()->createWithContent('ecran.png', $png)], ['Accept' => 'application/json'])
        ->assertCreated();
    $this->ticket = SupportTicket::where('reference', $ref)->firstOrFail();
    $this->piece = $this->ticket->piecesJointes()->sole();
    $this->support = User::create(['name' => 'Aïcha', 'email' => 'a@klassci.com', 'password' => 'x', 'role' => 'support', 'is_active' => true]);
    $this->flushHeaders();
});

it('montre la piece dans le dossier avec un lien signe', function () {
    $this->actingAs($this->support);

    Livewire::test(ViewSupportTicket::class, ['record' => $this->ticket->getRouteKey()])
        ->assertSee('ecran.png')
        ->assertSee('/care/pieces/'.$this->piece->id.'?expires=', false);
});

it('ouvre une image par le lien signe, jamais sans signature', function () {
    $this->actingAs($this->support);

    $this->get(PieceJointeSupportController::lien($this->piece))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/care/pieces/'.$this->piece->id)->assertForbidden();
});

it('garde restreinte la piece d un dossier restreint', function () {
    $this->ticket->forceFill(['is_security_restricted' => true])->save();
    $this->actingAs($this->support);

    $this->get(PieceJointeSupportController::lien($this->piece))->assertNotFound();
});

it('renvoie a la connexion du panel quand la session a expire', function () {
    $this->get(PieceJointeSupportController::lien($this->piece))
        ->assertRedirect(route('filament.admin.auth.login'));
});
