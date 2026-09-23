<?php

namespace App\Http\Requests\Care;

use App\Domain\Care\Tickets\Enums\CategorieClient;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Valide une soumission d'instance, puis reduit le contexte a sa liste blanche.
 *
 * Les champs connus sont valides strictement ; les extras hors liste blanche
 * (config/care.php → contexte) sont ecartes, et l'ecart est journalise. L'instance n'est
 * jamais lue ici : elle vient de l'identifiant (AuthentifierInstance).
 */
class CreerTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('care_tenant');
    }

    public function rules(): array
    {
        $l = config('care.limites');

        return [
            'report' => ['required', 'array'],
            'report.category' => ['required', Rule::in(CategorieClient::values())],
            'report.description' => ['required', 'string', 'min:'.$l['description_min'], 'max:'.$l['description_max']],
            'report.title' => ['nullable', 'string', 'max:'.$l['titre_max']],

            'reporter' => ['required', 'array'],
            'reporter.external_id' => ['required', 'integer', 'min:1'],
            'reporter.name' => ['nullable', 'string', 'max:160'],
            'reporter.email' => ['nullable', 'email', 'max:190'],
            'reporter.roles' => ['nullable', 'array', 'max:20'],
            'reporter.roles.*' => ['string', 'max:64'],

            'context' => ['nullable', 'array'],
            'context.route_name' => ['nullable', 'string', 'max:160'],
            'context.url_path' => ['nullable', 'string', 'max:255'],
            'context.module' => ['nullable', 'string', 'max:64'],
            'context.entity' => ['nullable', 'array'],
            'context.entity.type' => ['nullable', 'string', Rule::in(config('care.contexte.types_entite'))],
            'context.entity.id' => ['nullable', 'integer', 'min:1'],
            'context.academic_year_id' => ['nullable', 'integer', 'min:1'],
            'context.class_id' => ['nullable', 'integer', 'min:1'],
            'context.browser' => ['nullable', 'array'],
            'context.browser.family' => ['nullable', 'string', 'max:32'],
            'context.browser.version' => ['nullable', 'string', 'max:16'],
            'context.os' => ['nullable', 'string', 'max:32'],
            'context.device' => ['nullable', 'string', Rule::in(['mobile', 'tablet', 'desktop'])],
            'context.viewport' => ['nullable', 'string', 'regex:/^\d{2,5}x\d{2,5}$/'],
            'context.locale' => ['nullable', 'string', 'max:12'],
            'context.timezone' => ['nullable', 'string', 'max:64'],
            'context.request_ids' => ['nullable', 'array', 'max:'.$l['request_ids_max']],
            'context.request_ids.*' => ['string', 'max:64'],
            'context.extras' => ['nullable', 'array'],
        ];
    }

    /** Le contexte ramene a sa liste blanche. */
    public function donnees(): array
    {
        $v = $this->validated();

        // Ecartes plutot que refuses : un signalement vaut mieux sans ses details
        // qu'absent. Mais un rattrapage muet ne se cherche jamais, donc chaque
        // ecart laisse une trace (les cles, jamais les valeurs).
        $bruts = (array) ($v['context']['extras'] ?? []);
        $extras = array_filter(
            array_intersect_key($bruts, array_flip(config('care.contexte.extras_autorises'))),
            fn ($x) => is_scalar($x) || $x === null,
        );
        $ecartees = array_keys(array_diff_key($bruts, $extras));
        $octets = strlen(json_encode($extras));
        if ($octets > config('care.limites.extras_octets_max')) {
            $ecartees = array_keys($bruts);
            $extras = [];
        }
        if ($ecartees !== []) {
            Log::warning('care.contexte.extras_ecartes', [
                'instance' => $this->attributes->get('care_tenant')?->code,
                'cles' => array_map(fn ($k) => mb_substr((string) $k, 0, 64), array_slice($ecartees, 0, 20)),
                'octets' => $octets,
            ]);
        }

        if (isset($v['context'])) {
            $v['context']['extras'] = $extras ?: null;
            if (empty($v['context']['entity']['type']) || empty($v['context']['entity']['id'])) {
                unset($v['context']['entity']);
            }
        }

        return $v;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => 'validation_failed',
            'message' => 'La demande est incomplète ou mal formée.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
