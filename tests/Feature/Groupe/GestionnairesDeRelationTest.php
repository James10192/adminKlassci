<?php

use App\Filament\Resources\GroupResource\Pages\ViewGroup;
use App\Filament\Resources\GroupResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\GroupResource\RelationManagers\TenantsRelationManager;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Tenant;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * Les deux gestionnaires de la fiche groupe, exercés pour de vrai : on monte
 * l'action ET on la joue, parce que les deux pannes signalées ne se voyaient
 * qu'au moment du rendu du formulaire ou de l'INSERT.
 */
function decorGroupe(?int $groupeDeLEtablissement = null): array
{
    $suffixe = uniqid();

    $admin = User::create([
        'name' => 'Admin ' . $suffixe,
        'email' => 'admin' . $suffixe . '@klassci.test',
        'password' => bcrypt('secret'),
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    $groupe = Group::create(['name' => 'Groupe', 'code' => 'g-' . $suffixe, 'status' => 'active']);

    $etablissement = Tenant::create([
        'group_id' => $groupeDeLEtablissement,
        'code' => 'libre-' . $suffixe,
        'name' => 'LIBRE',
        'subdomain' => 'libre-' . $suffixe,
        'database_name' => 'klassci_libre',
        'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y'],
        'git_branch' => 'main',
        'status' => 'active',
        'plan' => 'elite',
    ]);

    return [$admin, $groupe, $etablissement];
}

function tableEtablissements(Group $groupe)
{
    return Livewire::test(TenantsRelationManager::class, [
        'ownerRecord' => $groupe,
        'pageClass' => ViewGroup::class,
    ]);
}

it('ouvre la liste des etablissements du groupe', function (): void {
    [$admin, $groupe] = decorGroupe();
    $this->actingAs($admin);

    tableEtablissements($groupe)->assertSuccessful();
});

it('rattache un etablissement libre au groupe', function (): void {
    [$admin, $groupe, $etablissement] = decorGroupe();
    $this->actingAs($admin);

    tableEtablissements($groupe)
        ->mountTableAction('attach')
        ->setTableActionData(['tenant_id' => $etablissement->getKey()])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($etablissement->refresh()->group_id)->toBe($groupe->getKey());
});

it('ne propose pas un etablissement deja rattache a un groupe', function (): void {
    [$admin, $groupe] = decorGroupe();
    $autreGroupe = Group::create(['name' => 'Autre', 'code' => 'autre-' . uniqid(), 'status' => 'active']);
    [, , $dejaPris] = decorGroupe($autreGroupe->getKey());
    $this->actingAs($admin);

    tableEtablissements($groupe)
        ->mountTableAction('attach')
        ->assertFormFieldExists(
            'tenant_id',
            'mountedTableActionForm',
            fn ($champ) => ! array_key_exists($dejaPris->getKey(), $champ->getOptions()),
        );
});

it('retire un etablissement du groupe sans le supprimer', function (): void {
    [$admin, $groupe] = decorGroupe();
    [, , $etablissement] = decorGroupe($groupe->getKey());
    $this->actingAs($admin);

    tableEtablissements($groupe)
        ->callTableAction('detach', $etablissement)
        ->assertHasNoTableActionErrors();

    expect($etablissement->refresh()->group_id)->toBeNull()
        ->and(Tenant::whereKey($etablissement->getKey())->exists())->toBeTrue();
});

it('cree un membre du groupe avec un mot de passe temporaire utilisable', function (): void {
    [$admin, $groupe] = decorGroupe();
    $this->actingAs($admin);

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $groupe,
        'pageClass' => ViewGroup::class,
    ])
        ->callTableAction('create', data: [
            'name' => 'Marcel Test',
            'email' => 'marcel.test@klassci.test',
            'role' => 'fondateur',
            'is_active' => true,
        ])
        ->assertHasNoTableActionErrors();

    $membre = GroupMember::where('email', 'marcel.test@klassci.test')->first();

    expect($membre)->not->toBeNull()
        ->and($membre->password)->not->toBeEmpty()
        ->and($membre->mustChangePassword())->toBeTrue();

    // Sans invitation par email, l'unique occasion de lire ce mot de passe est
    // la notification affichée juste après la création.
    Notification::assertNotified('Mot de passe temporaire — affiché une seule fois');
});

it('garde le mot de passe genere en clair sur l instance, jamais en base', function (): void {
    [, $groupe] = decorGroupe();

    $membre = GroupMember::create([
        'group_id' => $groupe->getKey(),
        'name' => 'Creation Directe',
        'email' => 'directe@klassci.test',
        'role' => 'fondateur',
        'is_active' => true,
    ]);

    expect($membre->motDePasseTemporaire)->not->toBeEmpty()
        ->and(Hash::check($membre->motDePasseTemporaire, $membre->password))->toBeTrue()
        ->and($membre->fresh()->motDePasseTemporaire)->toBeNull()
        ->and(array_key_exists('motDePasseTemporaire', $membre->getAttributes()))->toBeFalse();
});

it('honore le mot de passe saisi par l administrateur', function (): void {
    [$admin, $groupe] = decorGroupe();
    $this->actingAs($admin);

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $groupe,
        'pageClass' => ViewGroup::class,
    ])
        ->callTableAction('create', data: [
            'name' => 'Membre Choisi',
            'email' => 'choisi@klassci.test',
            'password' => 'MotDePasseChoisi#2026',
            'role' => 'fondateur',
            'is_active' => true,
        ])
        ->assertHasNoTableActionErrors();

    $membre = GroupMember::where('email', 'choisi@klassci.test')->first();

    expect(Hash::check('MotDePasseChoisi#2026', $membre->password))->toBeTrue();

    Notification::assertNotNotified('Mot de passe temporaire — affiché une seule fois');
});

it('cree un membre sans email en lui donnant un identifiant', function (): void {
    [$admin, $groupe] = decorGroupe();
    $this->actingAs($admin);

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $groupe,
        'pageClass' => ViewGroup::class,
    ])
        ->callTableAction('create', data: [
            'name' => 'Sans Email',
            'role' => 'fondateur',
            'is_active' => true,
        ])
        ->assertHasNoTableActionErrors();

    $membre = GroupMember::where('name', 'Sans Email')->first();

    expect($membre)->not->toBeNull()
        ->and($membre->username)->not->toBeEmpty()
        ->and($membre->password)->not->toBeEmpty();
});
