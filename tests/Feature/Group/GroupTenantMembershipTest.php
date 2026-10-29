<?php

use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Resources\GroupResource\RelationManagers\TenantsRelationManager;
use App\Models\Group;
use App\Models\SaasMappingReviewRecord;
use App\Models\SaasProduct;
use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\User;
use App\Services\Group\GroupTenantMembershipService;
use App\Services\Saas\SaasCapabilities;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function membershipAdmin(string $email, string $role = 'super_admin'): User
{
    return User::create([
        'name' => $role.' test',
        'email' => $email,
        'password' => 'secure-test-password',
        'role' => $role,
        'is_active' => true,
    ]);
}

function membershipGroup(string $code): Group
{
    return Group::create([
        'code' => $code,
        'name' => 'Groupe '.$code,
        'status' => 'active',
    ]);
}

function membershipTenant(string $code, ?Group $group = null): Tenant
{
    return Tenant::create([
        'code' => $code,
        'name' => 'École '.$code,
        'subdomain' => $code,
        'database_name' => 'test_'.$code,
        'database_credentials' => ['password' => 'NE_PAS_AFFICHER_CREDENTIAL'],
        'api_token' => 'NE_PAS_AFFICHER_TOKEN_'.$code,
        'git_branch' => 'presentation',
        'group_id' => $group?->id,
    ]);
}

it('corrige la relation HasMany, rattache une école indépendante et garde un journal atomique', function () {
    $operator = membershipAdmin('membership-admin@example.invalid');
    $group = membershipGroup('grouperostan');
    $tenant = membershipTenant('usat');

    $result = app(GroupTenantMembershipService::class)->associate(
        $operator, $group->id, $tenant->id, 'Décision commerciale documentée.'
    );

    expect($group->tenants()->count())->toBe(1)
        ->and($result->group_id)->toBe($group->id)
        ->and(Tenant::findOrFail($tenant->id)->group_id)->toBe($group->id)
        ->and(TenantActivityLog::where('tenant_id', $tenant->id)
            ->where('action', 'group_associated')->count())->toBe(1);

    $event = TenantActivityLog::where('tenant_id', $tenant->id)->firstOrFail();
    expect($event->metadata['previous_group_id'])->toBeNull()
        ->and($event->metadata['new_group_id'])->toBe($group->id)
        ->and($event->metadata['reason'])->toBe('Décision commerciale documentée.')
        ->and($event->performed_by_user_id)->toBe($operator->id)
        ->and($event->metadata)->not->toHaveKey('database_credentials');
});

it('ne transfère jamais en silence une école entre deux groupes', function () {
    $operator = membershipAdmin('membership-owner@example.invalid');
    $first = membershipGroup('first');
    $second = membershipGroup('second');
    $tenant = membershipTenant('islg', $first);

    expect(fn () => app(GroupTenantMembershipService::class)->associate(
        $operator, $second->id, $tenant->id, 'Transfert jamais implicite.'
    ))->toThrow(ValidationException::class);

    expect($tenant->fresh()->group_id)->toBe($first->id)
        ->and(TenantActivityLog::where('action', 'group_associated')->count())->toBe(0);
});

it('refuse une association répétée, un tenant archivé et un groupe suspendu', function () {
    $operator = membershipAdmin('membership-check@example.invalid');
    $group = membershipGroup('check');
    $existing = membershipTenant('existing', $group);
    $archived = membershipTenant('oldschool');
    $archived->delete();

    expect(fn () => app(GroupTenantMembershipService::class)->associate(
        $operator, $group->id, $existing->id, 'Rattachement déjà existant.'
    ))->toThrow(ValidationException::class);

    expect(fn () => app(GroupTenantMembershipService::class)->associate(
        $operator, $group->id, $archived->id, 'Tentative sur école archivée.'
    ))->toThrow(ValidationException::class);

    $group->update(['status' => 'suspended']);
    expect(fn () => app(GroupTenantMembershipService::class)->dissociate(
        $operator, $group->id, $existing->id, 'Groupe actuellement suspendu.'
    ))->toThrow(ValidationException::class);
});

it('refuse un compte support, facturation ou inactif même si une requête contourne la modale', function () {
    $support = membershipAdmin('membership-support@example.invalid', 'support');
    $billing = membershipAdmin('membership-billing@example.invalid', 'billing');
    $inactive = membershipAdmin('membership-inactive@example.invalid');
    $inactive->forceFill(['is_active' => false])->save();
    $group = membershipGroup('secure');
    $tenant = membershipTenant('protected');

    foreach ([$support, $billing, $inactive] as $operator) {
        expect(Gate::forUser($operator)->allows(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP))->toBeFalse();
        expect(fn () => app(GroupTenantMembershipService::class)->associate(
            $operator, $group->id, $tenant->id, 'Tentative sans permission effective.'
        ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    }

    expect($tenant->fresh()->group_id)->toBeNull()
        ->and(TenantActivityLog::where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('ne retire un établissement que du bon groupe et conserve le motif', function () {
    $operator = membershipAdmin('membership-detach@example.invalid');
    $group = membershipGroup('groupa');
    $other = membershipGroup('groupb');
    $tenant = membershipTenant('tenantb', $group);

    expect(fn () => app(GroupTenantMembershipService::class)->dissociate(
        $operator, $other->id, $tenant->id, 'Le mauvais groupe est choisi.'
    ))->toThrow(ValidationException::class);

    $result = app(GroupTenantMembershipService::class)->dissociate(
        $operator, $group->id, $tenant->id, 'Retrait confirmé par la direction.'
    );
    expect($result->group_id)->toBeNull()
        ->and(TenantActivityLog::where('tenant_id', $tenant->id)
            ->where('action', 'group_dissociated')->count())->toBe(1)
        ->and(TenantActivityLog::where('action', 'group_dissociated')->first()->metadata['previous_group_id'])->toBe($group->id);
});

it('la fenêtre Filament ajouter un établissement se monte sans action AttachAction de pivot', function () {
    $operator = membershipAdmin('membership-ui@example.invalid');
    $group = membershipGroup('ui');
    membershipTenant('chooseme');
    $this->actingAs($operator);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(TenantsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditGroup::class,
    ])->mountTableAction('associate_tenant')
      ->assertSet('mountedTableActions.0', 'associate_tenant')
      ->assertSee('Établissement existant');
});
