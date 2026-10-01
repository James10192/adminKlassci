<?php

namespace App\Http\Requests\Care;

use App\Domain\Care\Retours\Enums\AvisAssistant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Le contrat d'un 👍 / 👎 envoyé par une école (POST /api/v1/support/retours-assistant).
 *
 * Partagé avec l'émetteur côté école : un champ ajouté ici sans l'être là-bas
 * refuserait chaque envoi. Ajouts facultatifs seulement.
 */
class EnregistrerRetourAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('care_tenant');
    }

    public function rules(): array
    {
        $ref = function (string $attribut, mixed $valeur, \Closure $echec) {
            if (! is_int($valeur) && ! (is_string($valeur) && $valeur !== '' && mb_strlen($valeur) <= 64)) {
                $echec("Le champ {$attribut} doit être un entier ou une chaîne de 64 caractères au plus.");
            }
        };

        return [
            'avis' => ['required', 'string', Rule::in(AvisAssistant::values())],
            'raison' => ['nullable', 'string', 'max:60'],
            'commentaire' => ['nullable', 'string', 'max:1000'],
            'question' => ['required', 'string', 'max:2000'],
            'reponse' => ['required', 'string', 'max:4000'],
            'modele' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'string', 'max:255'],
            'utilisateur' => ['required', 'array'],
            'utilisateur.id' => ['required', 'integer', 'min:1'],
            'utilisateur.nom' => ['required', 'string', 'max:160'],
            'utilisateur.role' => ['nullable', 'string', 'max:64'],
            'conversation_ref' => ['nullable', $ref],
            'message_ref' => ['required', $ref],
            // Borne haute : une horloge d'ecole tres en avance figerait le retour
            // (une version plus recente selon donne_le serait ignoree).
            'donne_le' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(10)->toIso8601String()],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => 'validation_failed',
            'message' => 'Le retour est incomplet ou mal formé.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
