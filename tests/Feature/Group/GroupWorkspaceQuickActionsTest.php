<?php

use App\Filament\Forms\Group\AssociateTenantFields;
use App\Filament\Forms\Group\GroupMemberFields;
use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Resources\GroupResource\Pages\ViewGroup;
use App\Filament\Resources\GroupResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\GroupResource\RelationManagers\TenantsRelationManager;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\User;
use App\Services\Group\GroupMemberCreator;
use App\Services\Saas\SaasCapabilities;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function groupWorkspaceAdmin(string $role = 'super_admin'): User
{
    return User::create([
        'name' => 'Opérateur '.$role,
        'email' => uniqid($role.'-').'@example.invalid',
        'password' => 'testing-password',
        'role' => $role,
        'is_active' => true,
    ]);
}

function groupWorkspaceGroup(string $status = 'active'): Group
{
    return Group::create([
        'name' => 'Groupe Workspace',
        'code' => uniqid('group-'),
        'status' => $status,
    ]);
}

function groupWorkspaceTenant(string $code): Tenant
{
    return Tenant::create([
        'code' => $code,
        'name' => 'Institut '.$code,
        'subdomain' => $code,
        'database_name' => 'test_'.$code,
        'database_credentials' => ['password' => 'NE_PAS_AFFICHER'],
        'api_token' => 'TEST_SECRET_'.$code,
        'git_branch' => 'presentation',
    ]);
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('affiche les deux actions rapides dans la fiche Modifier le groupe', function () {
    $group = groupWorkspaceGroup();
    $this->actingAs(groupWorkspaceAdmin());

    Livewire::test(EditGroup::class, ['record' => $group->getRouteKey()])
        ->mountAction('quickAssociateTenant')
        ->assertSet('mountedActions.0', 'quickAssociateTenant')
        ->assertSee('Établissement existant');
});

it('affiche les actions rapides également depuis Voir le groupe', function () {
    $group = groupWorkspaceGroup();
    $this->actingAs(groupWorkspaceAdmin());

    Livewire::test(ViewGroup::class, ['record' => $group->getRouteKey()])
        ->mountAction('quickAddMember')
        ->assertSet('mountedActions.0', 'quickAddMember')
        ->assertSee('Nom complet');
});

it('permet de rattacher directement un établissement par modale sans changer son contrat', function () {
    $group = groupWorkspaceGroup();
    $tenant = groupWorkspaceTenant('workspace1');
    $oldPlan = $tenant->plan;
    $this->actingAs(groupWorkspaceAdmin());

    Livewire::test(EditGroup::class, ['record' => $group->getRouteKey()])
        ->callAction('quickAssociateTenant', data: [
            'tenant_id' => $tenant->id,
            'reason' => 'Accord et rattachement groupe vérifié.',
        ])->assertHasNoActionErrors();

    expect($tenant->fresh()->group_id)->toBe($group->id)
        ->and($tenant->fresh()->plan)->toBe($oldPlan)
        ->and(TenantActivityLog::where('tenant_id', $tenant->id)
            ->where('action', 'group_associated')->count())->toBe(1);
});

it('crée un membre dans le bon groupe sans exposer de hash via un raccourci', function () {
    config()->set('group_portal.invite_flow_enabled', false);
    $group = groupWorkspaceGroup();
    $this->actingAs(groupWorkspaceAdmin());

    Livewire::test(EditGroup::class, ['record' => $group->getRouteKey()])
        ->callAction('quickAddMember', data: [
            'name' => 'Membre direction',
            'email' => null,
            'username' => 'direction.workspace',
            'password' => 'NewInitialPassword123',
            'role' => 'directeur_general',
            'is_active' => true,
        ])->assertHasNoActionErrors();

    $member = GroupMember::where('username', 'direction.workspace')->firstOrFail();
    expect($member->group_id)->toBe($group->id)
        ->and($member->password)->not->toBe('NewInitialPassword123')
        ->and(\Illuminate\Support\Facades\Hash::check('NewInitialPassword123', $member->password))->toBeTrue();
});

it('ne laisse jamais le support ajouter un établissement ou un membre en contournant les boutons', function () {
    $support = groupWorkspaceAdmin('support');
    $group = groupWorkspaceGroup();
    $tenant = groupWorkspaceTenant('workspace2');

    expect(Gate::forUser($support)->allows(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP))->toBeFalse()
        ->and(Gate::forUser($support)->allows(SaasCapabilities::MANAGE_GROUP_MEMBERS))->toBeFalse();

    expect(fn () => app(GroupMemberCreator::class)->create($support, $group->id, [
        'name' => 'Tentative',
        'email' => 'intruder@example.invalid',
        'role' => 'fondateur',
        'is_active' => true,
    ]))->toThrow(AuthorizationException::class);

    expect($tenant->fresh()->group_id)->toBeNull()
        ->and(GroupMember::where('group_id', $group->id)->count())->toBe(0);
});

it('bloque la création de membre dans un groupe suspendu et exige un secret initial sans invitation', function () {
    config()->set('group_portal.invite_flow_enabled', false);
    $admin = groupWorkspaceAdmin();
    $group = groupWorkspaceGroup('suspended');

    expect(fn () => app(GroupMemberCreator::class)->create($admin, $group->id, [
        'name' => 'Membre', 'email' => 'member@example.invalid',
        'password' => 'ValidPassword123',
        'role' => 'fondateur', 'is_active' => true,
    ]))->toThrow(ValidationException::class);

    $group->update(['status' => 'active']);
    expect(fn () => app(GroupMemberCreator::class)->create($admin, $group->id, [
        'name' => 'Membre', 'email' => 'member@example.invalid',
        'role' => 'fondateur', 'is_active' => true,
    ]))->toThrow(ValidationException::class);
    expect(GroupMember::count())->toBe(0);
});

it('monte les deux actions relationnelles et ne révèle aucun jeton technique dans les champs', function () {
    $group = groupWorkspaceGroup();
    $this->actingAs(groupWorkspaceAdmin());
    groupWorkspaceTenant('workspace3');

    Livewire::test(TenantsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditGroup::class,
    ])->mountTableAction('associate_tenant')
        ->assertSee('Établissement existant')
        ->assertDontSee('TEST_SECRET_workspace3');

    $tenantField = AssociateTenantFields::make()[0];
    expect($tenantField->getName())->toBe('tenant_id');

    $memberFields = GroupMemberFields::make();
    expect(collect($memberFields)->map(fn ($field) => $field->getName())->all())->toContain('name', 'role');
});
