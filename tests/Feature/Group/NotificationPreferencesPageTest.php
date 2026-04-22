<?php

use App\Enums\AlertType;
use App\Filament\Group\Pages\NotificationPreferences;

it('NotificationPreferences page carries the expected Filament props', function () {
    expect(NotificationPreferences::getSlug())->toBe('mes-preferences-notifications');

    $reflection = new ReflectionClass(NotificationPreferences::class);
    expect($reflection->getStaticPropertyValue('title'))->toBe('Préférences de notification');
    expect($reflection->getStaticPropertyValue('navigationGroup'))->toBe('Mon compte');
});

it('page implements the Filament HasForms contract', function () {
    $class = new ReflectionClass(NotificationPreferences::class);

    $interfaces = array_map(fn ($i) => $i->getName(), $class->getInterfaces());

    expect($interfaces)->toContain('Filament\\Forms\\Contracts\\HasForms');
});

it('page declares every AlertType as a toggle', function () {
    $source = file_get_contents(app_path('Filament/Group/Pages/NotificationPreferences.php'));

    foreach (AlertType::cases() as $type) {
        // Each case is referenced in labelFor() (one-line match arm per type)
        $needle = 'AlertType::' . $type->name;
        expect($source)->toContain($needle);
    }
});

it('page toggles default ON so users opt OUT (verbose default)', function () {
    $source = file_get_contents(app_path('Filament/Group/Pages/NotificationPreferences.php'));

    // enabledTypesFor inverts disabled list — starting state is "every type enabled"
    expect($source)->toContain('enabledTypesFor');
    expect($source)->toContain('! in_array($type->value, $disabled, true)');
});

it('save() writes the inverse (disabled_alert_types) to the model', function () {
    $source = file_get_contents(app_path('Filament/Group/Pages/NotificationPreferences.php'));

    // When a type toggle is OFF, its value ends up in disabled_alert_types
    expect($source)->toContain('$disabled[] = $type->value;');
    expect($source)->toContain("'disabled_alert_types' => \$disabled,");
});

it('digest time options span workday hours with 30-minute granularity', function () {
    $source = file_get_contents(app_path('Filament/Group/Pages/NotificationPreferences.php'));

    expect($source)->toContain('range(6, 20)');
    expect($source)->toContain("['00', '30']");
});

it('page lives under the Mon compte navigation group', function () {
    $class = new ReflectionClass(NotificationPreferences::class);

    expect($class->getStaticPropertyValue('navigationGroup'))->toBe('Mon compte');
    expect($class->getStaticPropertyValue('navigationLabel'))->toBe('Notifications');
});
