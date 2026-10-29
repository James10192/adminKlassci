<?php

use App\Services\MailPulse\MailPulseClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.mailpulse', [
        'enabled' => true,
        'base_url' => 'https://mailpulse.example',
        'api_key' => 'test-secret-never-log',
        'timeout' => 5,
    ]);
    Http::preventStrayRequests();
});

it('refuse tout appel lorsque MailPulse est desactive', function () {
    config()->set('services.mailpulse.enabled', false);

    expect(app(MailPulseClient::class)->sendEmail('admin@ecole.ci', 'Bienvenue', 'Message', 'event-1'))
        ->toMatchArray(['ok' => false, 'error_code' => 'disabled']);

    Http::assertNothingSent();
});

it('exige une cle idempotente stable sans tenter de reseau', function () {
    expect(app(MailPulseClient::class)->sendEmail('admin@ecole.ci', 'Bienvenue', 'Message', ''))
        ->toMatchArray(['ok' => false, 'error_code' => 'idempotency_key_required']);

    Http::assertNothingSent();
});

it('refuse une configuration non HTTPS, sans envoyer le jeton', function () {
    config()->set('services.mailpulse.base_url', 'http://mailpulse.example');

    expect(app(MailPulseClient::class)->sendEmail('admin@ecole.ci', 'Bienvenue', 'Message', 'evt-1'))
        ->toMatchArray(['ok' => false, 'error_code' => 'not_configured']);

    Http::assertNothingSent();
});

it('envoie une notification email via MailPulse et non un fournisseur direct', function () {
    Http::fake(['https://mailpulse.example/api/v1/messages' => Http::response([
        'message' => ['id' => 'msg-42', 'status' => 'queued'],
    ], 202)]);

    $result = app(MailPulseClient::class)->sendEmail(
        'direction@ecole.ci', 'Activation', 'Bienvenue sur KLASSCI', 'signup:42:email:activation'
    );

    expect($result)->toMatchArray(['ok' => true, 'status' => 'queued', 'message_id' => 'msg-42']);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://mailpulse.example/api/v1/messages'
            && $request->hasHeader('Authorization', 'Bearer test-secret-never-log')
            && $request->hasHeader('Idempotency-Key', 'signup:42:email:activation')
            && $request['channel'] === 'email'
            && $request['recipient']['value'] === 'direction@ecole.ci'
            && $request['metadata']['subject'] === 'Activation';
    });
    Http::assertSentCount(1);
});

it('ne suppose jamais un consentement marketing lors de la synchronisation contact', function () {
    Http::fake(['https://mailpulse.example/api/v1/contacts' => Http::response([
        'contact' => ['id' => 'contact-1'],
    ], 200)]);

    $result = app(MailPulseClient::class)->upsertContact('direction@ecole.ci', 'school:42', 'contact:42');

    expect($result['ok'])->toBeTrue();
    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://mailpulse.example/api/v1/contacts'
            && $request->hasHeader('Idempotency-Key', 'contact:42')
            && ! array_key_exists('subscribed', $request->data());
    });
});

it('laisse le worker outbox gerer les reprises sans retenter immediatement', function () {
    Http::fake(['https://mailpulse.example/api/v1/messages' => Http::response(['error' => 'unavailable'], 503)]);

    expect(app(MailPulseClient::class)->sendEmail('admin@ecole.ci', 'Activation', 'Message', 'event-1'))
        ->toMatchArray(['ok' => false, 'error_code' => 'transient_failure']);

    Http::assertSentCount(1);
});

it('ne retente pas aveuglement apres une erreur de permissions', function () {
    Http::fake(['https://mailpulse.example/api/v1/messages' => Http::response(['error' => 'invalid key'], 401)]);

    expect(app(MailPulseClient::class)->sendEmail('admin@ecole.ci', 'Activation', 'Message', 'event-1'))
        ->toMatchArray(['ok' => false, 'error_code' => 'unauthorized']);
    Http::assertSentCount(1);
});
