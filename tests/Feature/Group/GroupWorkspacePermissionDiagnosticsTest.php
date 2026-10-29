<?php

use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\Group\GroupMemberCreator;
use App\Services\Saas\SaasCapabilities;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

function permissionUiAdmin(string $role = 'super_admin'): User
{
    return User::create([
        'name' => 'Compte admin diagnostic',
        'email' => uniqid('diagnostic-').'@example.invalid',
        'password' => 'test-password-only',
        'role' => $role,
        'is_active' => true,
    ]);
}

function permissionUiGroup(): Group
{
    return Group::create([
        'name' => 'Groupe test',
        'code' => uniqid('diagnostic-group-'),
        'status' => 'active',
    ]);
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('permet au super admin actif de monter la modale membre et de voir son formulaire', function () {
    $admin = permissionUiAdmin();
    $this->actingAs($admin);
    $group = permissionUiGroup();

    expect(Gate::forUser($admin)->allows(SaasCapabilities::MANAGE_GROUP_MEMBERS))->toBeTrue()
        ->and(Gate::forUser($admin)->allows(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP))->toBeTrue();

    Livewire::test(EditGroup::class, ['record' => $group->getRouteKey()])
        ->mountAction('quickAddMember')
        ->assertSet('mountedActions.0', 'quickAddMember')
        ->assertSee('Nom complet')
        ->assertSee('Ajouter le membre');
});

it('ouvre un diagnostic non modifiable si le cache config est dépourvu de la permission membres', function () {
    $admin = permissionUiAdmin();
    $this->actingAs($admin);
    $group = permissionUiGroup();

    // Simule un cache de config datant de la PR qui ne déclarait que
    // la capacité rattachement; aucun élargissement implicite n'est autorisé.
    config()->set('saas.capabilities_by_role.super_admin', [
        SaasCapabilities::MANAGE_GROUP_MEMBERSHIP,
    ]);

    expect(Gate::forUser($admin)->allows(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP))->toBeTrue()
        ->and(Gate::forUser($admin)->allows(SaasCapabilities::MANAGE_GROUP_MEMBERS))->toBeFalse();

    Livewire::test(EditGroup::class, ['record' => $group->getRouteKey()])
        ->mountAction('quickAddMember')
        ->assertSee('Ajout de membre indisponible')
        ->assertSee('saas:permissions:diagnose')
        ->assertDontSee('Nom complet');

    expect(fn () => app(GroupMemberCreator::class)->create($admin, $group->id, [
        'name' => 'Nouvel accès',
        'email' => 'invitation@example.invalid',
        'role' => 'fondateur',
        'is_active' => true,
    ]))->toThrow(AuthorizationException::class);

    expect(GroupMember::count())->toBe(0);
});

it('explique au support le manque de permission et ne révèle pas les données de création', function () {
    $this->actingAs(permissionUiAdmin('support'));
    $group = permissionUiGroup();

    Livewire::test(EditGroup::class, ['record' => $group->getRouteKey()])
        ->mountAction('quickAddMember')
        ->assertSee('Ajout de membre indisponible')
        ->assertDontSee('Nom complet');

    expect(GroupMember::count())->toBe(0);
});

it('explique pourquoi un groupe suspendu interdit toute création de membre', function () {
    $admin = permissionUiAdmin();
    $this->actingAs($admin);
    $group = permissionUiGroup();
    $group->update(['status' => 'suspended']);

    Livewire::test(EditGroup::class, ['record' => $group->getRouteKey()])
        ->mountAction('quickAddMember')
        ->assertSee('groupe est suspendu')
        ->assertDontSee('Nom complet');
});

it('diagnostique les capacités configurées sans modifier les droits ni révéler des secrets', function () {
    $admin = permissionUiAdmin();

    $code = Artisan::call('saas:permissions:diagnose', ['--admin-id' => (string) $admin->id]);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('Diagnostic des permissions Groupe')
        ->and($output)->toContain('Permission membres')
        ->and($output)->toContain('accordée')
        ->and($output)->not->toContain($admin->email)
        ->and($output)->not->toContain('test-password-only');
});
