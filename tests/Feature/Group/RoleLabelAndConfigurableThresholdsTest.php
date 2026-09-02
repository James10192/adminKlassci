<?php

use App\Enums\GroupMemberRole;
use App\Filament\Group\Pages\GroupDashboard;
use App\Services\Group\GroupBranding;
use App\Support\RateHealth;

/**
 * L0 — le portail ne doit plus rien afficher qui vienne d'une table écrite en
 * dur, ni pour les libellés de rôle, ni pour les seuils de santé d'un taux.
 *
 * Le déclencheur : un Directeur Général Adjoint était accueilli par
 * « Bienvenue, X — directeur_general_adjoint », parce que GroupDashboard
 * tenait sa propre liste de trois rôles à côté de l'enum qui en compte quatre.
 */
function roleLabelOf(string $role): string
{
    $method = new ReflectionMethod(GroupDashboard::class, 'roleLabel');
    $method->setAccessible(true);

    return $method->invoke(null, $role);
}

it('libelle chaque role de l enum, adjoint compris', function () {
    foreach (GroupMemberRole::cases() as $case) {
        expect(roleLabelOf($case->value))
            ->toBe($case->label())
            ->and(roleLabelOf($case->value))
            ->not->toBe($case->value);
    }
});

it('libelle nommement le Directeur General Adjoint', function () {
    expect(roleLabelOf('directeur_general_adjoint'))->toBe('Directeur Général Adjoint');
});

it('retombe sur la valeur brute pour un role inconnu plutot que de planter', function () {
    expect(roleLabelOf('role_inexistant'))->toBe('role_inexistant');
});

it('ne garde aucune table de roles ecrite en dur dans GroupDashboard', function () {
    $source = file_get_contents(app_path('Filament/Group/Pages/GroupDashboard.php'));

    expect($source)
        ->toContain('GroupMemberRole::tryFrom')
        ->and($source)->not->toContain("'fondateur' => 'Fondateur'");
});

it('lit les seuils de sante depuis la configuration', function () {
    config()->set('group_portal.rate_health.healthy', 90);
    config()->set('group_portal.rate_health.at_risk', 80);

    expect(RateHealth::tone(85.0))->toBe('warning')
        ->and(RateHealth::label(85.0))->toBe('à surveiller')
        ->and(RateHealth::tone(95.0))->toBe('success')
        ->and(RateHealth::tone(75.0))->toBe('danger');
});

it('retombe sur les seuils par defaut quand la configuration est absente', function () {
    config()->set('group_portal.rate_health', null);

    expect(RateHealth::healthyThreshold())->toBe(RateHealth::HEALTHY)
        ->and(RateHealth::atRiskThreshold())->toBe(RateHealth::AT_RISK)
        ->and(RateHealth::tone(72.0))->toBe('success');
});

it('ne laisse aucun seuil 70 / 50 ecrit en dur dans les vues du portail', function () {
    $views = [
        'views/filament/group/pages/financial-overview.blade.php',
        'views/filament/group/pages/benchmarking.blade.php',
        'views/filament/group/widgets/establishment-cards.blade.php',
    ];

    foreach ($views as $view) {
        $source = file_get_contents(resource_path($view));

        expect($source)
            ->toContain('RateHealth::tone')
            ->and($source)->not->toContain('>= 70')
            ->and($source)->not->toContain('>= 50');
    }
});

it('retombe sur la marque KLASSCI quand aucun groupe n est connecte', function () {
    $branding = new GroupBranding;

    expect($branding->currentGroup())->toBeNull()
        ->and($branding->name())->toBe(config('group_portal.branding.name'))
        ->and($branding->primaryHex())->toBe(config('group_portal.branding.primary'))
        ->and($branding->logoUrl())->toContain(config('group_portal.branding.logo'));
});

it('ignore une couleur primaire de groupe qui n est pas un hexadecimal', function () {
    $group = new App\Models\Group(['metadata' => ['branding' => ['primary' => 'rouge vif']]]);

    expect((new GroupBranding)->primaryHex($group))
        ->toBe(config('group_portal.branding.primary'));
});

it('retient une couleur primaire de groupe correctement formee', function () {
    $group = new App\Models\Group(['metadata' => ['branding' => ['primary' => '#8B1E3F']]]);

    expect((new GroupBranding)->primaryHex($group))->toBe('#8B1E3F');
});

it('n ecrit plus le nom ni le logo en dur dans le PanelProvider', function () {
    $source = file_get_contents(app_path('Providers/Filament/GroupPanelProvider.php'));

    expect($source)
        ->toContain('GroupBranding::class')
        ->and($source)->not->toContain("->brandName('KLASSCI Groupe')")
        ->and($source)->not->toContain("->brandLogo(asset(");
});
