<?php

use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Filament\Resources\SupportTicketResource;
use App\Filament\Resources\SupportTicketResource\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTicketResource\Pages\ViewSupportTicket;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Care\Support;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $ecole = Support::instance('presentation');
    $this->withToken(Support::jeton($ecole))->withHeader('Idempotency-Key', 'cle-filament-01')
        ->postJson('/api/v1/support/tickets', Support::soumission());
    $this->ticket = SupportTicket::firstOrFail();
    $this->support = User::create(['name' => 'Aïcha', 'email' => 'a@klassci.com', 'password' => 'x', 'role' => 'support', 'is_active' => true]);
});

it('affiche la file a trier au support', function () {
    $this->actingAs($this->support);

    Livewire::test(ListSupportTickets::class)->assertCanSeeTableRecords([$this->ticket]);
});

it('ferme la file a un role sans capacite', function () {
    config(['care.capacites_par_role.billing' => []]);
    $this->actingAs(User::create(['name' => 'B', 'email' => 'b@klassci.com', 'password' => 'x', 'role' => 'billing', 'is_active' => true]));

    $this->get(SupportTicketResource::getUrl('index'))->assertForbidden();
});

it('repond a l ecole depuis le dossier en choisissant la visibilite', function () {
    $this->actingAs($this->support);

    Livewire::test(ViewSupportTicket::class, ['record' => $this->ticket->getRouteKey()])
        ->callAction('repondre', ['visibilite' => VisibiliteMessage::PublicClient->value, 'corps' => 'Bonjour, nous regardons.'])
        ->assertHasNoActionErrors();

    expect($this->ticket->messages()->first()->visibility)->toBe(VisibiliteMessage::PublicClient);
});

it('montre la reponse dans le dossier sans rechargement', function () {
    $this->actingAs($this->support);

    Livewire::test(ViewSupportTicket::class, ['record' => $this->ticket->getRouteKey()])
        ->callAction('repondre', ['visibilite' => VisibiliteMessage::PublicClient->value, 'corps' => 'Réponse visible tout de suite.'])
        ->assertSee('Réponse visible tout de suite.');
});

it('ne trahit pas une demande restreinte par le badge de navigation', function () {
    $this->actingAs($this->support);
    expect(SupportTicketResource::getNavigationBadge())->toBe('1');

    $this->ticket->forceFill(['is_security_restricted' => true])->save();

    expect(SupportTicketResource::getNavigationBadge())->toBeNull();
});

it('pose la demande en attente de l ecole quand le support lui pose une question', function () {
    $this->actingAs($this->support);

    Livewire::test(ViewSupportTicket::class, ['record' => $this->ticket->getRouteKey()])
        ->callAction('repondre', ['visibilite' => VisibiliteMessage::PublicClient->value, 'corps' => 'Quelle classe ?', 'attendre_ecole' => true])
        ->assertHasNoActionErrors();

    expect($this->ticket->fresh()->status)->toBe(StatutTicket::WaitingCustomer);
    Livewire::test(ListSupportTickets::class)->set('activeTab', 'attente_client')->assertCanSeeTableRecords([$this->ticket]);
});

it('ne publie rien quand le dossier a change d etat avant l envoi', function () {
    // La page est ouverte sur un dossier qui peut attendre l'ecole ; entre-temps
    // un autre agent le passe en cours. La transition est relue sous verrou :
    // elle est refusee, et le message ne doit pas partir seul.
    $this->actingAs($this->support);
    $this->ticket->forceFill(['status' => StatutTicket::Triaged])->save();
    $page = Livewire::test(ViewSupportTicket::class, ['record' => $this->ticket->getRouteKey()]);
    $this->ticket->forceFill(['status' => StatutTicket::InProgress])->save();

    $page->callAction('repondre', ['visibilite' => VisibiliteMessage::PublicClient->value, 'corps' => 'Quelle classe ?', 'attendre_ecole' => true])
        ->assertActionMounted('repondre')
        ->assertSet('mountedActionsData.0.corps', 'Quelle classe ?');

    expect($this->ticket->messages()->count())->toBe(0)
        ->and($this->ticket->fresh()->status)->toBe(StatutTicket::InProgress);
});

it('ne propose pas d attendre l ecole quand le dossier ne peut pas l attendre', function () {
    $this->actingAs($this->support);
    $this->ticket->forceFill(['status' => StatutTicket::InProgress])->save();

    Livewire::test(ViewSupportTicket::class, ['record' => $this->ticket->getRouteKey()])
        ->mountAction('repondre')
        ->set('mountedActionsData.0.visibilite', VisibiliteMessage::PublicClient->value)
        ->assertFormFieldIsHidden('attendre_ecole', 'mountedActionForm');
});

it('refuse une reponse sans visibilite choisie', function () {
    $this->actingAs($this->support);

    Livewire::test(ViewSupportTicket::class, ['record' => $this->ticket->getRouteKey()])
        ->callAction('repondre', ['corps' => 'Bonjour.'])
        ->assertHasActionErrors(['visibilite' => 'required']);
});

it('change le statut par la machine a etats', function () {
    $this->actingAs($this->support);

    Livewire::test(ViewSupportTicket::class, ['record' => $this->ticket->getRouteKey()])
        ->callAction('statut', ['vers' => StatutTicket::Triaged->value]);

    expect($this->ticket->fresh()->status)->toBe(StatutTicket::Triaged);
});

it('affiche tout le contexte capte dans le dossier, pas seulement les colonnes de la liste', function () {
    // La liste ne charge que quatre colonnes du contexte. Le dossier reutilisait
    // cette requete : page, navigateur et ecran s'affichaient vides alors qu'ils
    // etaient en base.
    $this->actingAs($this->support);

    Livewire::test(ViewSupportTicket::class, ['record' => $this->ticket->getRouteKey()])
        ->assertSee('/esbtp/notes')
        ->assertSee('390x844')
        ->assertSee('Chrome 128');
});
