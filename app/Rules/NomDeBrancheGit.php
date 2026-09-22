<?php

namespace App\Rules;

use App\Support\Git\NomDeBranche;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regle de validation posee sur chaque entree d'un nom de branche.
 * La forme seule : l'existence sur origin se verifie au deploiement,
 * dans le depot du tenant (TenantDeploy).
 */
class NomDeBrancheGit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $motif = NomDeBranche::motifDeRefus($value);

        if ($motif !== null) {
            $fail($motif);
        }
    }
}
