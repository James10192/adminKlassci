<?php

use App\View\Components\GroupHero;

/**
 * PR6a — Dashboard hero structural tests. Behavior under a real authenticated
 * group member is covered by visual check / Playwright; here we pin the Blade
 * component contract and the header wiring on GroupDashboard.
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

it('GroupWelcomeWidget has been retired (PR6a)', function () {
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

it('dashboard-hero partial renders without error given a valid context shape', function () {
    $context = [
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
    ];

    $html = view('filament.group.partials.dashboard-hero', compact('context'))->render();

    expect($html)->toContain('Test Group');
    expect($html)->toContain('Jane Doe');
    expect($html)->toContain('Fondateur');
    expect($html)->toContain('1 234');   // thousand-sep
    expect($html)->toContain('72,5');     // FR decimal
    expect($html)->toContain('Portail Groupe');
});
