<?php

use App\Domain\Care\Retours\Enums\AvisAssistant;
use App\Domain\Care\Retours\Models\RetourAssistant;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Filament\Resources\RetourAssistantResource;
use App\Filament\Resources\RetourAssistantResource\Pages\ListRetoursAssistant;
use App\Filament\Resources\RetourAssistantResource\Pages\ViewRetourAssistant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Care\Support;

/*
 * Les 👍 / 👎 de Nanan : de l'école au panneau, puis à Slack.
 */

const WEBHOOK_RETOURS = 'https://hooks.slack.test/services/T000/B000/RETOURS';

function retourNanan(array $surcharge = []): array
{
    return array_replace_recursive([
        'avis' => 'pas_utile',
        'raison' => 'faux',
        'commentaire' => 'La date de clôture est fausse.',
        'question' => 'Quand ferme la saisie des notes du semestre 1 ?',
        'reponse' => 'La saisie ferme le 15 octobre.',
        'modele' => 'claude-haiku',
        'page' => '/esbtp/notes',
        'utilisateur' => ['id' => 42, 'nom' => 'Awa Koné', 'role' => 'secretaire'],
        'conversation_ref' => 'sess-abc',
        'message_ref' => 991,
        'donne_le' => '2026-10-01T09:30:00+00:00',
    ], $surcharge);
}

beforeEach(function () {
    $this->ecole = Support::instance('presentation');
    $this->jeton = Support::jeton($this->ecole);
    config(['care.slack.webhook' => null]);
});

function envoyerRetour($test, array $corps, string $cle = 'retour-0000001', ?string $jeton = null)
{
    return $test->withToken($jeton ?? $test->jeton)->withHeader('Idempotency-Key', $cle)
        ->postJson('/api/v1/support/retours-assistant', $corps);
}

it('enregistre un 👎 et rend son identifiant', function () {
    $id = envoyerRetour($this, retourNanan())->assertCreated()->assertHeader('Idempotent-Replayed', 'false')->json('id');

    $r = RetourAssistant::findOrFail($id);
    expect($r->tenant_id)->toBe($this->ecole->id)
        ->and($r->avis)->toBe(AvisAssistant::PasUtile)
        ->and($r->message_ref)->toBe('991')
        ->and($r->utilisateur_role)->toBe('secretaire')
        ->and($r->question)->toContain('saisie des notes');
});

it('rejoue la meme cle sans rien ecrire', function () {
    $id = envoyerRetour($this, retourNanan())->json('id');

    envoyerRetour($this, retourNanan())->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('id', $id);
    expect(RetourAssistant::count())->toBe(1);
});

it('exige la cle d idempotence', function () {
    $this->withToken($this->jeton)->postJson('/api/v1/support/retours-assistant', retourNanan())
        ->assertStatus(400)->assertJsonPath('error', 'idempotency_key_required');
});

it('garde un seul avis par personne et par reponse, le dernier', function () {
    envoyerRetour($this, retourNanan(['avis' => 'utile', 'raison' => null, 'commentaire' => null]), 'retour-0000001');
    $r = RetourAssistant::firstOrFail();
    $r->forceFill(['traite_le' => now()])->save();

    envoyerRetour($this, retourNanan(), 'retour-0000002')->assertCreated();

    expect(RetourAssistant::count())->toBe(1)
        ->and($r->fresh()->avis)->toBe(AvisAssistant::PasUtile)
        // Un avis qui change rouvre le retour.
        ->and($r->fresh()->traite_le)->toBeNull();
});

it('ignore une version plus ancienne arrivee en retard', function () {
    envoyerRetour($this, retourNanan(['donne_le' => '2026-10-01T10:00:00+00:00']), 'uuid-0001:2')->assertCreated();

    envoyerRetour($this, retourNanan(['avis' => 'utile', 'raison' => null, 'commentaire' => null, 'donne_le' => '2026-10-01T09:00:00+00:00']), 'uuid-0001')
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(RetourAssistant::count())->toBe(1)->and(RetourAssistant::first()->avis)->toBe(AvisAssistant::PasUtile);
});

it('refuse un corps hors contrat', function () {
    envoyerRetour($this, retourNanan(['avis' => 'bof']))->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    envoyerRetour($this, array_diff_key(retourNanan(), ['message_ref' => 1]), 'retour-0000003')->assertStatus(422);
    envoyerRetour($this, retourNanan(['question' => str_repeat('x', 2001)]), 'retour-0000004')->assertStatus(422);
});

it('accepte des references en chaine ou en entier', function () {
    envoyerRetour($this, retourNanan(['message_ref' => 'msg-12', 'conversation_ref' => 7]))->assertCreated();

    expect(RetourAssistant::firstOrFail())->message_ref->toBe('msg-12')->conversation_ref->toBe('7');
});

it('refuse un identifiant sans la portee de creation', function () {
    $lecture = Support::jeton($this->ecole, ['support:read']);

    envoyerRetour($this, retourNanan(), 'retour-0000001', $lecture)->assertForbidden();
});

it('annonce un 👎 sur Slack sans question, reponse ni commentaire', function () {
    config(['care.slack.webhook' => WEBHOOK_RETOURS]);
    Http::fake([WEBHOOK_RETOURS => Http::response('ok')]);

    envoyerRetour($this, retourNanan())->assertCreated();

    $envois = Http::recorded();
    expect($envois)->toHaveCount(1);
    $corps = json_encode($envois[0][0]->data(), JSON_UNESCAPED_UNICODE);
    expect($corps)->toContain('PRESENTATION')->toContain('pas utile')->toContain('retours-assistant')
        ->not->toContain('saisie des notes')->not->toContain('15 octobre')->not->toContain('clôture est fausse');
});

it('n annonce pas un 👍 sans commentaire', function () {
    config(['care.slack.webhook' => WEBHOOK_RETOURS]);
    Http::fake([WEBHOOK_RETOURS => Http::response('ok')]);

    envoyerRetour($this, retourNanan(['avis' => 'utile', 'raison' => null, 'commentaire' => null]))->assertCreated();

    expect(Http::recorded())->toHaveCount(0);
});

describe('dans le panneau', function () {
    beforeEach(function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        envoyerRetour($this, retourNanan());
        $this->retour = RetourAssistant::firstOrFail();
        $this->support = User::create(['name' => 'Aïcha', 'email' => 'a@klassci.com', 'password' => 'x', 'role' => 'support', 'is_active' => true]);
    });

    it('liste les 👎 a lire et les compte dans le badge', function () {
        $this->actingAs($this->support);

        Livewire::test(ListRetoursAssistant::class)->assertCanSeeTableRecords([$this->retour]);
        expect(RetourAssistantResource::getNavigationBadge())->toBe('1');
    });

    it('montre la question et la reponse completes', function () {
        $this->actingAs($this->support);

        Livewire::test(ViewRetourAssistant::class, ['record' => $this->retour->getRouteKey()])
            ->assertSee('Quand ferme la saisie des notes du semestre 1 ?')
            ->assertSee('La saisie ferme le 15 octobre.');
    });

    it('marque un retour traite avec une note', function () {
        $this->actingAs($this->support);

        Livewire::test(ViewRetourAssistant::class, ['record' => $this->retour->getRouteKey()])
            ->callAction('traiter', ['note' => 'Date corrigée dans le calendrier.'])
            ->assertHasNoActionErrors();

        expect($this->retour->fresh())->traite_par->toBe($this->support->id)->note_interne->toBe('Date corrigée dans le calendrier.')
            ->and(RetourAssistantResource::getNavigationBadge())->toBeNull();
    });

    it('transforme un 👎 en demande au nom de la personne', function () {
        $this->actingAs($this->support);

        Livewire::test(ViewRetourAssistant::class, ['record' => $this->retour->getRouteKey()])
            ->callAction('demande')->assertHasNoActionErrors();

        $ticket = SupportTicket::firstOrFail();
        expect($ticket->tenant_id)->toBe($this->ecole->id)
            ->and($ticket->reporter_external_id)->toBe(42)
            ->and($ticket->customer_category->value)->toBe('INFORMATION_INCORRECTE')
            ->and($ticket->description)->toContain('Quand ferme la saisie')
            ->and($this->retour->fresh()->support_ticket_id)->toBe($ticket->id)
            ->and($this->retour->fresh()->traite_le)->not->toBeNull();
    });

    it('ferme les retours a un role sans capacite', function () {
        config(['care.capacites_par_role.billing' => []]);
        $this->actingAs(User::create(['name' => 'B', 'email' => 'b@klassci.com', 'password' => 'x', 'role' => 'billing', 'is_active' => true]));

        $this->get(RetourAssistantResource::getUrl('index'))->assertForbidden();
    });
});
