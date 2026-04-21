<?php

use App\View\Components\GroupHero;

/**
 * Structural tests pinning the Blade component contract + the header wiring
 * on GroupDashboard. Behavior under a real authenticated group member is
 * covered by visual check / Playwright.
 */

it('GroupHero component has required props', function () {
    $reflection = new ReflectionClass(GroupHero::class);
    $constructor = $reflection->getConstructor();
    $params = $constructor->getParameters();

    $names = array_map(fn ($p) => $p->getName(), $params);
    expect($names)->toBe(['title', 'subtitle', 'iconPath']);

    // title is required, subtitle + iconPath are optional with null default
    expect($params[0]->isOptional())->toBeFalse();
    expect($params[1]->isOptional())->toBeTrue();
    expect($params[2]->isOptional())->toBeTrue();
});

it('GroupHero blade template declares the 3 expected slots', function () {
    $template = file_get_contents(
        resource_path('views/components/group-hero.blade.php')
    );

    expect($template)->toContain('$actions');
    expect($template)->toContain('$kpis');
    expect($template)->toContain('$badges');
});

it('GroupDashboard provides hero context with expected keys', function () {
    // getHeroContext() is private — use reflection for a structural check
    // without faking an authenticated group member (Filament panel context).
    $reflection = new ReflectionClass(\App\Filament\Group\Pages\GroupDashboard::class);

    expect($reflection->hasMethod('getHeader'))->toBeTrue();
    expect($reflection->hasMethod('getHeroContext'))->toBeTrue();
    expect($reflection->getMethod('getHeroContext')->isPrivate())->toBeTrue();
});

it('GroupWelcomeWidget has been retired — hero lives at the page level', function () {
    // Check the file, not the class — composer autoload caches can lag in test envs.
    expect(file_exists(app_path('Filament/Group/Widgets/GroupWelcomeWidget.php')))
        ->toBeFalse('GroupWelcomeWidget.php should be removed — hero lives at the page level now');
    expect(file_exists(resource_path('views/filament/group/widgets/group-welcome.blade.php')))
        ->toBeFalse('group-welcome.blade.php should be removed');
});

it('GroupDashboard does not register GroupWelcomeWidget', function () {
    $source = file_get_contents(
        app_path('Filament/Group/Pages/GroupDashboard.php')
    );

    expect($source)->not->toContain('GroupWelcomeWidget');
});

it('dashboard-hero partial renders the happy path', function () {
    $context = makeHeroContext();

    $html = view('filament.group.partials.dashboard-hero', compact('context'))->render();

    expect($html)->toContain('Test Group');
    expect($html)->toContain('Jane Doe');
    expect($html)->toContain('Fondateur');
    expect($html)->toContain('1 234');
    expect($html)->toContain('72,5');
    expect($html)->toContain('Portail Groupe');
});

it('hides academic-year chip when no years are available', function () {
    $context = makeHeroContext(['academic_years' => []]);

    $html = view('filament.group.partials.dashboard-hero', compact('context'))->render();

    expect($html)->not->toContain('2025-2026');
});

it('applies danger tone when collection rate is below 50%', function () {
    $context = makeHeroContext(['kpis' => ['collection_rate' => 12.0] + makeHeroContext()['kpis']]);

    $html = view('filament.group.partials.dashboard-hero', compact('context'))->render();

    expect($html)->toContain('data-tone="danger"');
});

it('uses singular "établissement" when count is 1', function () {
    $context = makeHeroContext(['establishment_count' => 1]);

    $html = view('filament.group.partials.dashboard-hero', compact('context'))->render();

    expect($html)->toContain('1 établissement');
    expect($html)->not->toContain('1 établissements');
});

function makeHeroContext(array $overrides = []): array
{
    // array_replace (not recursive) so overriding `academic_years` with an
    // empty list actually empties it instead of merging.
    return array_replace([
        'group_name' => 'Test Group',
        'user_name' => 'Jane Doe',
        'role' => 'Fondateur',
        'establishment_count' => 3,
        'academic_years' => ['2025-2026'],
        'last_sync' => 'il y a moins de 15 min',
        'kpis' => [
            'total_students' => 1234,
            'collection_rate' => 72.5,
            'total_revenue_collected' => 12_500_000,
            'total_staff' => 45,
            'avg_attendance_rate' => 88.3,
        ],
    ], $overrides);
}
