<?php

namespace App\Http\Requests;

use App\Rules\NomDeBrancheGit;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

/**
 * Charge utile de POST /api/deploy.
 *
 * Le jeton est verifie dans authorize(), donc AVANT la validation : un appelant
 * sans jeton recoit 401, jamais le detail des regles.
 */
class DeployWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attendu = config('app.deploy_webhook_token');

        if (empty($attendu)) {
            Log::error('DeployWebhook: DEPLOY_WEBHOOK_TOKEN non configuré dans .env');
            throw new HttpResponseException(
                response()->json(['error' => 'Webhook non configuré côté serveur.'], 500)
            );
        }

        $jeton = $this->bearerToken();

        if (! $jeton || ! hash_equals($attendu, $jeton)) {
            Log::warning('DeployWebhook: Tentative non autorisée', [
                'ip' => $this->ip(),
                'user_agent' => $this->userAgent(),
            ]);
            throw new HttpResponseException(response()->json(['error' => 'Unauthorized'], 401));
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_code'     => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/'],
            'branch'          => ['nullable', 'string', new NomDeBrancheGit()],
            'skip_backup'     => ['nullable', 'boolean'],
            'skip_migrations' => ['nullable', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('DeployWebhook: charge utile refusée', [
            'ip' => $this->ip(),
            'erreurs' => $validator->errors()->toArray(),
        ]);

        throw new HttpResponseException(response()->json([
            'error' => 'Charge utile invalide.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
