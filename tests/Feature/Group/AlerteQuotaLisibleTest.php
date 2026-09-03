<?php

use App\Models\Group;
use App\Models\Tenant;
use App\Services\TenantAggregationService;

/**
 * Ce que lit vraiment le directeur dans une alerte de quota.
 *
 * Le message était construit avec la racine de la colonne (`users`,
 * `students`) et le `round()` brut de PHP. Sur un portail entièrement
 * français, la liste des alertes annonçait « Quota users à 93.3% » — un mot
 * anglais et un point décimal.
 */
function tenantAvecQuota(Group $groupe, int $courant, int $plafond, string $code = 'rostan-yopougon'): Tenant
{
    return Tenant::create([
        'group_id' => $groupe->id,
        'code' => $code,
        'name' => 'Rostan Yopougon',
        'subdomain' => $code,
        'database_name' => 'klassci_' . str_replace('-', '_', $code),
        'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y'],
        'git_branch' => 'main',
        'status' => 'active',
        'plan' => 'elite',
        'max_users' => $plafond,
        'current_users' => $courant,
    ]);
}

/** @return list<string> les messages d'alerte produits pour ce groupe */
function messagesAlertes(Group $groupe): array
{
    $sante = app(TenantAggregationService::class)->getGroupHealthMetrics($groupe);

    return array_map(fn ($a) => \App\Support\Alerts\AlertPayload::from($a)->message, $sante['alerts']);
}

beforeEach(function () {
    $this->groupe = Group::create(['name' => 'Groupe ROSTAN', 'code' => 'rostan', 'status' => 'active']);
});

it('nomme le quota en français et pointe la virgule décimale', function () {
    // 28 / 30 = 93,3 % — au-dessus du seuil critique, sous le plafond.
    tenantAvecQuota($this->groupe, 28, 30);

    $messages = messagesAlertes($this->groupe);

    expect($messages)->toContain('Quota comptes à 93,3 %');

    foreach ($messages as $message) {
        expect($message)->not->toContain('users');
        expect($message)->not->toContain('93.3');
    }
});

it('n\'ecrit pas « 105,0 » quand le pourcentage est rond', function () {
    // Un pourcentage rond ne traine pas une decimale nulle.
    tenantAvecQuota($this->groupe, 21, 20);

    expect(messagesAlertes($this->groupe))->toContain('Quota comptes dépassé (105 %)');
});
