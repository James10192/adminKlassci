<?php

namespace App\Http\Middleware\Care;

use App\Domain\Care\Acces\Models\TenantApiCredential;
use App\Enums\TenantStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie une instance aupres de l'API KLASSCI Care, et verifie la portee.
 *
 *   Route::middleware('care.instance:support:create')
 *
 * Ce qui la distingue de VerifyTenantApiToken, volontairement :
 *  - en-tete Authorization uniquement. Un jeton en parametre d'URL finit dans
 *    les journaux d'acces ; il est refuse, pas ignore, pour que l'erreur se
 *    voie au lieu d'un 401 incomprehensible ;
 *  - le secret n'est jamais stocke en clair, et se compare en temps constant ;
 *  - l'instance est celle de l'identifiant. Aucune route de cette API ne porte
 *    de code d'instance, donc aucune ne peut en viser une autre.
 */
class AuthentifierInstance
{
    public function handle(Request $request, Closure $next, string $portee): Response
    {
        if ($request->query->has('token') || $request->query->has('api_token')) {
            return $this->refus(400, 'token_in_query', 'Le jeton doit être transmis dans l\'en-tête Authorization, jamais dans l\'URL.');
        }

        $jeton = $request->bearerToken();
        $parties = $jeton ? TenantApiCredential::decouper($jeton) : null;
        if ($parties === null) {
            return $this->refus(401, 'unauthenticated', 'Identifiant absent ou mal formé.');
        }

        $credential = TenantApiCredential::with('tenant')->where('key_id', $parties['key_id'])->first();
        if ($credential === null || ! $credential->verifierSecret($parties['secret']) || ! $credential->estUtilisable()) {
            return $this->refus(401, 'unauthenticated', 'Identifiant invalide, révoqué ou expiré.');
        }

        $tenant = $credential->tenant;
        if ($tenant === null || $tenant->status === TenantStatus::Cancelled->value) {
            return $this->refus(403, 'tenant_inactive', 'Cette instance n\'est plus servie.');
        }

        if (! $credential->accorde($portee)) {
            return $this->refus(403, 'insufficient_scope', "Portée requise : {$portee}.");
        }

        // Une ecriture par identifiant et par tranche de cinq minutes, pas une par requete.
        if ($credential->last_used_at === null || $credential->last_used_at->lt(now()->subMinutes(5))) {
            $credential->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $request->attributes->set('care_tenant', $tenant);
        $request->attributes->set('care_credential', $credential);

        return $next($request);
    }

    private function refus(int $statut, string $code, string $message): Response
    {
        return response()->json(['error' => $code, 'message' => $message], $statut);
    }
}
