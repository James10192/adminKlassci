<?php

use App\Contracts\Group\GroupPayrollProviderInterface;
use App\Models\Group;
use App\Models\Tenant;
use App\Services\Group\GroupPayrollProvider;
use App\Support\Period\PeriodInterface;

function tenantDemo(int $id, string $code, string $name): Tenant
{
    $tenant = new Tenant();
    $tenant->id = $id;
    $tenant->code = $code;
    $tenant->name = $name;

    return $tenant;
}

it('binds the interface to the concrete provider via GroupServiceProvider', function () {
    expect(app(GroupPayrollProviderInterface::class))
        ->toBeInstanceOf(GroupPayrollProvider::class);
});

it('emptyPayroll returns a stable structure with zeroed amounts', function () {
    $empty = app(GroupPayrollProvider::class)->emptyPayroll(tenantDemo(7, 'esbtp-yakro', 'ESBTP Yakro'));

    expect($empty)
        ->toHaveKey('tenant_code', 'esbtp-yakro')
        ->toHaveKey('tenant_name', 'ESBTP Yakro')
        ->toHaveKey('masse_brute', 0.0)
        ->toHaveKey('masse_nette', 0.0)
        ->toHaveKey('retenues', 0.0)
        ->toHaveKey('masse_versee', 0.0)
        ->toHaveKey('masse_engagee', 0.0)
        ->toHaveKey('masse_brouillon', 0.0)
        ->toHaveKey('bulletins', 0)
        ->toHaveKey('enseignants', 0);
});

it('marque un établissement injoignable plutôt que de le faire passer pour sans paie', function () {
    // Une base en panne et une école qui n'a versé aucun salaire donneraient
    // les mêmes zéros. Le drapeau est ce qui permet à l'écran de dire « non
    // consolidé » au lieu d'afficher un coût nul qui serait faux.
    expect(app(GroupPayrollProvider::class)->emptyPayroll(tenantDemo(1, 'x', 'X')))
        ->toHaveKey('error', true);
});

it('additionne les établissements et compte ceux qu il a consolidés', function () {
    $abidjan = tenantDemo(1, 'esbtp-abidjan', 'ESBTP Abidjan');
    $yakro = tenantDemo(2, 'esbtp-yakro', 'ESBTP Yakro');

    $group = new Group();
    $group->id = 1;
    $group->name = 'Groupe ESBTP';
    $group->setRelation('activeTenants', collect([$abidjan, $yakro]));

    $parCode = [
        'esbtp-abidjan' => [
            'masse_brute' => 1_000_000.0, 'masse_nette' => 850_000.0, 'retenues' => 150_000.0,
            'masse_versee' => 600_000.0, 'masse_engagee' => 250_000.0, 'masse_brouillon' => 40_000.0,
            'bulletins' => 12, 'bulletins_brouillon' => 2, 'enseignants' => 9,
        ],
        'esbtp-yakro' => [
            'masse_brute' => 400_000.0, 'masse_nette' => 340_000.0, 'retenues' => 60_000.0,
            'masse_versee' => 340_000.0, 'masse_engagee' => 0.0, 'masse_brouillon' => 0.0,
            'bulletins' => 5, 'bulletins_brouillon' => 0, 'enseignants' => 4,
        ],
    ];

    // L'agrégateur re-résout le fournisseur depuis le conteneur : la doublure
    // doit donc être un singleton, sinon elle en reçoit une seconde, vierge.
    app()->singleton(GroupPayrollProvider::class, function () use ($parCode) {
        $doublure = new class(
            app(\App\Services\TenantConnectionManager::class),
            app(\App\Services\Group\TenantAggregator::class),
            app(\App\Services\Group\PayrollStateResolver::class),
        ) extends GroupPayrollProvider {
            public array $parCode = [];

            public function computeTenantPayroll(Tenant $tenant, ?PeriodInterface $period = null): array
            {
                return array_merge($this->emptyPayroll($tenant), $this->parCode[$tenant->code], ['error' => false]);
            }
        };

        $doublure->parCode = $parCode;

        return $doublure;
    });

    $totaux = app(GroupPayrollProvider::class)->computeGroupPayroll($group);

    expect($totaux['masse_brute'])->toBe(1_400_000.0)
        ->and($totaux['masse_nette'])->toBe(1_190_000.0)
        ->and($totaux['retenues'])->toBe(210_000.0)
        ->and($totaux['masse_versee'])->toBe(940_000.0)
        ->and($totaux['masse_engagee'])->toBe(250_000.0)
        ->and($totaux['bulletins'])->toBe(17)
        ->and($totaux['enseignants'])->toBe(13)
        ->and($totaux['establishment_count'])->toBe(2)
        ->and($totaux['establishments'])->toHaveKeys(['esbtp-abidjan', 'esbtp-yakro']);
});

it('tient le brouillon hors de la masse engagée', function () {
    // Un bulletin préparé n'est dû à personne. Le confondre avec l'engagé
    // gonflerait le coût affiché au directeur.
    $tenant = tenantDemo(1, 'hetec', 'HETEC');

    $group = new Group();
    $group->id = 2;
    $group->setRelation('activeTenants', collect([$tenant]));

    app()->singleton(GroupPayrollProvider::class, fn () => new class(
        app(\App\Services\TenantConnectionManager::class),
        app(\App\Services\Group\TenantAggregator::class),
        app(\App\Services\Group\PayrollStateResolver::class),
    ) extends GroupPayrollProvider {
        public function computeTenantPayroll(Tenant $tenant, ?PeriodInterface $period = null): array
        {
            return array_merge($this->emptyPayroll($tenant), [
                'masse_engagee' => 0.0,
                'masse_versee' => 0.0,
                'masse_brouillon' => 500_000.0,
                'bulletins' => 0,
                'bulletins_brouillon' => 6,
                'error' => false,
            ]);
        }
    });

    $totaux = app(GroupPayrollProvider::class)->computeGroupPayroll($group);

    expect($totaux['masse_brouillon'])->toBe(500_000.0)
        ->and($totaux['bulletins_brouillon'])->toBe(6)
        ->and($totaux['masse_engagee'])->toBe(0.0)
        ->and($totaux['masse_versee'])->toBe(0.0);
});
