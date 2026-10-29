<?php

namespace App\Services\MailPulse;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Transport MailPulse pour le control-plane KLASSCI.
 *
 * Aucun appelant implicite : l'envoi n'est autorise que si le feature flag est
 * active. Les futurs jobs outbox doivent fournir une cle d'idempotence stable.
 * Resend ne doit jamais etre appele directement par adminKLASSCI.
 */
final class MailPulseClient
{
    /**
     * @return array{ok: bool, status: string, message_id: ?string, error_code: ?string}
     */
    public function sendEmail(string $recipient, string $subject, string $body, string $idempotencyKey): array
    {
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL) || trim($subject) === '' || trim($body) === '') {
            return $this->failure('invalid_payload');
        }

        return $this->request('messages', [
            'channel' => 'email',
            'recipient' => ['type' => 'email', 'value' => $recipient],
            'content' => ['type' => 'text', 'text' => $body],
            'metadata' => ['subject' => $subject],
        ], $idempotencyKey);
    }

    /**
     * Synchronise un contact. L'opt-in marketing est facultatif et JAMAIS
     * presume : seules les decisions explicites passent subscribed=true/false.
     *
     * @return array{ok: bool, status: string, message_id: ?string, error_code: ?string}
     */
    public function upsertContact(
        string $email,
        string $externalId,
        string $idempotencyKey,
        ?bool $subscribed = null
    ): array {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || trim($externalId) === '') {
            return $this->failure('invalid_payload');
        }

        $payload = ['email' => $email, 'external_id' => $externalId, 'language' => 'fr'];

        if ($subscribed !== null) {
            $payload['subscribed'] = $subscribed;
        }

        return $this->request('contacts', $payload, $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, status: string, message_id: ?string, error_code: ?string}
     */
    private function request(string $endpoint, array $payload, string $idempotencyKey): array
    {
        // Refuse les repetitions qui risqueraient de doubler la facturation.
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 255) {
            return $this->failure('idempotency_key_required');
        }

        $config = (array) config('services.mailpulse', []);
        if (! ($config['enabled'] ?? false)) {
            return $this->failure('disabled');
        }

        $base = rtrim((string) ($config['base_url'] ?? ''), '/');
        $key = (string) ($config['api_key'] ?? '');
        $parts = parse_url($base);

        if (! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || isset($parts['user'], $parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || $key === ''
        ) {
            return $this->failure('not_configured');
        }

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->asJson()
                ->timeout(max(1, min(30, (int) ($config['timeout'] ?? 10))))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post($base.'/api/v1/'.$endpoint, $payload);
        } catch (ConnectionException) {
            // Ni identifiant, ni contenu, ni reponse de transport en journal.
            return $this->failure('transport_unavailable');
        }

        if (! $response->successful()) {
            // Le workflow outbox choisit la politique de retry selon ce code.
            return $this->failure(match ($response->status()) {
                401, 403 => 'unauthorized',
                429 => 'rate_limited',
                408, 500, 502, 503, 504 => 'transient_failure',
                default => 'provider_rejected',
            });
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $message = isset($data['message']) && is_array($data['message']) ? $data['message'] : [];

        return [
            'ok' => true,
            'status' => (string) ($message['status'] ?? ($endpoint === 'contacts' ? 'upserted' : 'queued')),
            'message_id' => isset($message['id']) && is_string($message['id']) ? $message['id'] : null,
            'error_code' => null,
        ];
    }

    /**
     * @return array{ok: bool, status: string, message_id: ?string, error_code: ?string}
     */
    private function failure(string $code): array
    {
        return ['ok' => false, 'status' => 'failed', 'message_id' => null, 'error_code' => $code];
    }
}
