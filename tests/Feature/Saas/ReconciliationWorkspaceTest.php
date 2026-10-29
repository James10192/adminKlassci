<?php

use App\Filament\Resources\SaasMappingReviewResource;
use App\Models\Group;
use App\Models\SaasMappingReviewEvent;
use App\Models\SaasMappingReviewRecord;
use App\Models\SaasProduct;
use App\Models\SaasProductInstance;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Saas\SaasCapabilities;
use App\Services\Saas\SaasMappingWorkflow;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

function saasWorkspaceOperator(string $email, string $role): User
{
    return User::create([
        'name' => $role.' operator',
        'email' => $email,
        'password' => 'test-password-that-is-not-a-secret',
        'role' => $role,
        'is_active' => true,
    ]);
}

function saasWorkspaceTenant(string $code, ?Group $group = null): Tenant
{
    return Tenant::create([
        'code' => $code,
        'name' => 'Établissement '.$code,
        'subdomain' => $code,
        'database_name' => 'test_'.$code,
        'database_credentials' => ['password' => 'NE_JAMAIS_EXPOSER_MDP'],
        'api_token' => 'NE_JAMAIS_EXPOSER_JETON_'.$code,
        'git_branch' => 'presentation',
        'group_id' => $group?->id,
    ]);
}

function saasWorkspaceProduct(string $code = 'lmd'): SaasProduct
{
    return SaasProduct::create([
        'code' => $code,
        'name' => 'Produit test '.$code,
        'establishment_type' => 'type_'.$code,
        'adapter_key' => 'laravel-v2',
        'is_active' => true,
    ]);
}

function saasWorkspaceDraft(User $actor, Tenant $tenant, ?SaasProduct $product = null, ?Group $group = null): SaasMappingReviewRecord
{
    return app(SaasMappingWorkflow::class)->saveDraft($actor, [
        'legacy_tenant_id' => $tenant->id,
        'kind' => 'school',
        'product_id' => $product?->id,
        'proposed_group_id' => $group?->id,
        'evidence_reference' => 'référence-validation-direction',
    ]);
}

it('persiste un brouillon sans modifier les contrats, les groupes ni le registre SaaS', function () {
    $maker = saasWorkspaceOperator('support@example.invalid', 'support');
    $group = Group::create(['code' => 'ROSTAN', 'name' => 'GROUPE ROSTAN']);
    $tenant = saasWorkspaceTenant('usat');
    $product = saasWorkspaceProduct();

    $review = saasWorkspaceDraft($maker, $tenant, $product, $group);

    expect($review->state)->toBe('draft')
        ->and($review->legacy_tenant_id)->toBe($tenant->id)
        ->and($review->proposed_group_id)->toBe($group->id)
        ->and($review->created_by)->toBe($maker->id)
        ->and($review->events()->count())->toBe(1)
        ->and(Tenant::findOrFail($tenant->id)->group_id)->toBeNull()
        ->and(SaasProductInstance::count())->toBe(0)
        ->and(SaasMappingReviewEvent::first()->details['evidence_reference'])->toBe('référence-validation-direction');
});

it('bloque les doublons, le changement de propriétaire et les modifications après soumission', function () {
    $maker = saasWorkspaceOperator('maker@example.invalid', 'support');
    $other = saasWorkspaceOperator('other@example.invalid', 'support');
    $tenant = saasWorkspaceTenant('islg');
    $product = saasWorkspaceProduct();

    $review = saasWorkspaceDraft($maker, $tenant, $product);

    expect(fn () => saasWorkspaceDraft($other, $tenant, $product))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    app(SaasMappingWorkflow::class)->submit($maker, $review->id);
    expect(fn () => saasWorkspaceDraft($maker, $tenant, $product))
        ->toThrow(ValidationException::class);

    expect(SaasMappingReviewRecord::count())->toBe(1);
});

it('impose un autre valideur et exige d expliquer une difference de groupe USAT', function () {
    $maker = saasWorkspaceOperator('maker@example.invalid', 'super_admin');
    $checker = saasWorkspaceOperator('checker@example.invalid', 'super_admin');
    $group = Group::create(['code' => 'ROSTAN', 'name' => 'GROUPE ROSTAN']);
    $tenant = saasWorkspaceTenant('usat'); // group_id historique vide
    $product = saasWorkspaceProduct();

    $review = saasWorkspaceDraft($maker, $tenant, $product, $group);
    app(SaasMappingWorkflow::class)->submit($maker, $review->id);

    expect(fn () => app(SaasMappingWorkflow::class)->decide(
        $maker, $review->id, true, 'validation commerciale documentée', true
    ))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(fn () => app(SaasMappingWorkflow::class)->decide(
        $checker, $review->id, true, 'validation commerciale documentée', false
    ))->toThrow(ValidationException::class);

    $accepted = app(SaasMappingWorkflow::class)->decide(
        $checker, $review->id, true,
        'Rattachement commercial confirmé, impact portail groupe à traiter séparément.',
        true
    );

    expect($accepted->state)->toBe('approved')
        ->and($accepted->reviewed_by)->toBe($checker->id)
        ->and($accepted->events()->count())->toBe(3)
        ->and($accepted->events()->latest('id')->first()->details['discrepancy_acknowledged'])->toBeTrue()
        ->and(Tenant::findOrFail($tenant->id)->group_id)->toBeNull()
        ->and(SaasProductInstance::count())->toBe(0);
});

it('ne peut pas soumettre un produit inexistant ou inactif', function () {
    $maker = saasWorkspaceOperator('maker@example.invalid', 'support');
    $tenant = saasWorkspaceTenant('demo1');
    $product = saasWorkspaceProduct();
    $product->forceFill(['is_active' => false])->save();

    $draft = saasWorkspaceDraft($maker, $tenant, $product);

    expect(fn () => app(SaasMappingWorkflow::class)->submit($maker, $draft->id))
        ->toThrow(ValidationException::class);
    expect($draft->fresh()->state)->toBe('draft');
});

it('interdit les instances archivées même si leur code existe toujours', function () {
    $maker = saasWorkspaceOperator('maker@example.invalid', 'support');
    $tenant = saasWorkspaceTenant('islg-rostan');
    $tenant->delete();

    expect(fn () => saasWorkspaceDraft($maker, $tenant))
        ->toThrow(ValidationException::class);
    expect(SaasMappingReviewRecord::count())->toBe(0);
});

it('permet une qualification technique uniquement sans produit ni groupe', function () {
    $maker = saasWorkspaceOperator('maker@example.invalid', 'support');
    $tenant = saasWorkspaceTenant('presentation');

    $draft = app(SaasMappingWorkflow::class)->saveDraft($maker, [
        'legacy_tenant_id' => $tenant->id,
        'kind' => 'demonstration',
        'evidence_reference' => 'vérification-exploitation',
    ]);

    expect(app(SaasMappingWorkflow::class)->submit($maker, $draft->id)->state)->toBe('submitted');

    expect(fn () => app(SaasMappingWorkflow::class)->saveDraft($maker, [
        'legacy_tenant_id' => $tenant->id,
        'kind' => 'technical',
        'product_id' => saasWorkspaceProduct()->id,
        'evidence_reference' => 'preuve-technique',
    ]))->toThrow(ValidationException::class);
});

it('respecte les capacités effectives et cache le panneau aux utilisateurs inactifs', function () {
    $support = saasWorkspaceOperator('support@example.invalid', 'support');
    $billing = saasWorkspaceOperator('billing@example.invalid', 'billing');
    $reviewer = saasWorkspaceOperator('reviewer@example.invalid', 'super_admin');

    expect(Gate::forUser($support)->allows(SaasCapabilities::VIEW))->toBeTrue()
        ->and(Gate::forUser($support)->allows(SaasCapabilities::PROPOSE))->toBeTrue()
        ->and(Gate::forUser($support)->allows(SaasCapabilities::REVIEW))->toBeFalse()
        ->and(Gate::forUser($billing)->allows(SaasCapabilities::PROPOSE))->toBeFalse()
        ->and(Gate::forUser($reviewer)->allows(SaasCapabilities::REVIEW))->toBeTrue();

    $billing->forceFill(['is_active' => false])->save();
    expect(Gate::forUser($billing)->allows(SaasCapabilities::VIEW))->toBeFalse();
    $this->actingAs($billing);
    expect(SaasMappingReviewResource::canViewAny())->toBeFalse();
});

it('un dossier refusé peut être révisé par son auteur sans effacer son historique', function () {
    $maker = saasWorkspaceOperator('maker@example.invalid', 'support');
    $checker = saasWorkspaceOperator('checker@example.invalid', 'super_admin');
    $tenant = saasWorkspaceTenant('hetec');
    $product = saasWorkspaceProduct();

    $draft = saasWorkspaceDraft($maker, $tenant, $product);
    app(SaasMappingWorkflow::class)->submit($maker, $draft->id);
    app(SaasMappingWorkflow::class)->decide($checker, $draft->id, false, 'Justificatif commercial incomplet.');

    $edited = saasWorkspaceDraft($maker, $tenant, $product);
    expect($edited->state)->toBe('draft')
        ->and($edited->revision)->toBe(2)
        ->and($edited->events()->count())->toBe(4)
        ->and($edited->reviewed_by)->toBeNull()
        ->and($edited->review_note)->toBeNull();
});
