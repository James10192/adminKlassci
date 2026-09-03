<?php

use App\Filament\Group\Pages\GroupDashboard;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;

/**
 * La couleur du bouton « Vérifier alertes » dit ce qu'elle prétend.
 *
 * Il était orange en permanence. Sur le tableau de bord, l'orange signifie
 * déjà « quelque chose demande votre attention » — le bandeau d'abonnement
 * expirant, les pastilles d'alerte. Un bouton orange qui ne dépend de rien use
 * ce signal : un groupe dont tout va bien voyait quand même de l'orange, et un
 * groupe en difficulté ne le distinguait plus du décor.
 */
function membreConnecte(Group $groupe): GroupMember
{
    $membre = GroupMember::create([
        'group_id' => $groupe->id,
        'email' => 'dg@rostan.test',
        'name' => 'Marcel',
        'role' => 'directeur_general',
        'password' => Hash::make('demo1234'),
        'is_active' => true,
    ]);

    test()->actingAs($membre, 'group');
    Filament::setCurrentPanel(Filament::getPanel('group'));

    return $membre;
}

/** La couleur effectivement resolue par l'action, telle que Filament la rend. */
function couleurBoutonAlertes(): string|array|null
{
    $methode = new ReflectionMethod(GroupDashboard::class, 'getHeaderActions');
    $methode->setAccessible(true);

    // L'en-tete melange des actions simples et un groupe d'actions (l'export
    // du rapport) : le groupe n'a pas de nom, on ne l'interroge pas.
    $action = collect($methode->invoke(new GroupDashboard()))
        ->filter(fn ($a) => $a instanceof \Filament\Actions\Action)
        ->first(fn ($a) => $a->getName() === 'check_alerts');

    return $action->getColor();
}

beforeEach(function () {
    $this->groupe = Group::create(['name' => 'Groupe ROSTAN', 'code' => 'rostan', 'status' => 'active']);

    // Le memo d'alertes est une propriete STATIQUE indexee par identifiant de
    // groupe. `RefreshDatabase` remet les identifiants a 1 : sans cette purge,
    // le resultat du test precedent fuiterait dans le suivant.
    \App\Filament\Group\Resources\EstablishmentResource::forgetAlertsCache($this->groupe->id);
});

it('reste gris quand le groupe n\'a aucune alerte', function () {
    membreConnecte($this->groupe);

    expect(couleurBoutonAlertes())->toBe('gray');
});

it('passe en alerte quand un abonnement du groupe expire bientôt', function () {
    Tenant::create([
        'group_id' => $this->groupe->id,
        'code' => 'rostan-bouake',
        'name' => 'Rostan Bouaké',
        'subdomain' => 'rostan-bouake',
        'database_name' => 'klassci_rostan_bouake',
        'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y'],
        'git_branch' => 'main',
        'status' => 'active',
        'plan' => 'elite',
        'subscription_end_date' => now()->addDays(5),
    ]);

    membreConnecte($this->groupe);

    // La teinte exacte appartient au calcul de sévérité ; ce qui compte ici,
    // c'est qu'elle cesse d'être neutre dès qu'il y a quelque chose à voir.
    expect(couleurBoutonAlertes())->not->toBe('gray');
});
