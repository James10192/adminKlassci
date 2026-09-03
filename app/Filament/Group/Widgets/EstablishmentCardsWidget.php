<?php

namespace App\Filament\Group\Widgets;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\SsoTokenSigner;
use App\Services\TenantAggregationService;
use App\Support\SsoClaim;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Log;

class EstablishmentCardsWidget extends Widget
{
    protected static string $view = 'filament.group.widgets.establishment-cards';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '300s';

    public function getEstablishments(): array
    {
        $group = auth('group')->user()->group;
        $kpis = app(TenantAggregationService::class)->getGroupKpis($group);

        return $kpis['establishments'] ?? [];
    }

    /**
     * Generate a fresh SSO URL for each tenant card. Token lifetime is 2min
     * (see SsoTokenSigner); widget polls every 300s so a click >2min after the
     * last render requires a refresh — acceptable tradeoff for security.
     */
    public function getSsoUrl(string $tenantCode, string $redirectTo = '/'): ?string
    {
        $member = auth('group')->user();
        if (! $member) {
            return null;
        }

        // Un membre sans groupe ne peut ouvrir aucun etablissement.
        //
        // Sans ce garde, `where('group_id', null)` devient `group_id IS NULL` :
        // le scope se retournerait et signerait un jeton pour tout tenant NON
        // rattache — c'est-a-dire presque tous. La colonne est aujourd'hui NOT
        // NULL, mais la securite de ce garde reposerait alors sur une
        // contrainte situee dans une autre table, que rien ici ne rappelle.
        if (! $member->group_id) {
            Log::warning('[sso] Jeton refuse : membre sans groupe', ['membre' => $member->id]);

            return null;
        }

        // LE GROUPE DU MEMBRE, PAS LA PLATEFORME ENTIERE.
        //
        // Cette methode est PUBLIQUE sur un composant Livewire : elle est donc
        // appelable depuis la console du navigateur, et Livewire renvoie sa
        // valeur de retour au JS. Sans ce scope, un membre du groupe ROSTAN
        // pouvait executer `$wire.getSsoUrl('esbtp-abidjan')`, recuperer une URL
        // signee, et se connecter chez un client qui n'est pas le sien.
        //
        // Le maitre est la SEULE autorite sur l'appartenance a un groupe : le
        // tenant ne verifie que la signature HMAC, l'expiration, le rate-limit
        // et l'open-redirect. Il n'a aucun moyen de rattraper ce controle.
        // ...ET une ecole que le groupe exploite encore. Les cartes n'affichent
        // que les actifs, mais la methode est publique : `$wire.getSsoUrl(...)`
        // avec le code d'une ecole suspendue — typiquement pour impaye —
        // rendait un jeton signe parfaitement valide.
        $tenant = Tenant::where('code', $tenantCode)
            ->where('group_id', $member->group_id)
            ->where('status', TenantStatus::Active->value)
            ->first();

        if (! $tenant) {
            Log::warning('[sso] Jeton refuse : etablissement hors du groupe du membre, ou inactif', [
                'membre' => $member->id,
                'groupe' => $member->group_id,
                'tenant_demande' => $tenantCode,
            ]);

            return null;
        }

        // La destination est bornee : `$redirectTo` arrive du meme appel Livewire
        // et part signe vers le tenant. Une valeur libre y ferait entrer une URL
        // absolue choisie par l'appelant.
        if (! self::destinationAutorisee($redirectTo)) {
            Log::warning('[sso] Destination refusee', [
                'membre' => $member->id,
                'destination' => $redirectTo,
            ]);

            return null;
        }

        try {
            $token = app(SsoTokenSigner::class)->sign([
                SsoClaim::TENANT_CODE => $tenantCode,
                SsoClaim::USER_EMAIL => $member->email,
                SsoClaim::REDIRECT_TO => $redirectTo,
                SsoClaim::ISSUED_BY => $member->email,
                SsoClaim::GROUP_MEMBER_ID => $member->id,
            ]);
        } catch (\Exception $e) {
            Log::warning("SSO URL generation failed for {$tenantCode}: {$e->getMessage()}");
            return null;
        }

        $baseUrl = $this->tenantBaseUrl($tenant);

        return $baseUrl . '/auth/sso-from-group?token=' . urlencode($token);
    }

    /**
     * Une destination interne au tenant, jamais une URL choisie par l'appelant.
     *
     * Le tenant applique deja un controle d'open-redirect, mais le maitre SIGNE
     * cette valeur : lui laisser passer n'importe quoi revient a authentifier
     * une destination qu'on n'a pas verifiee.
     */
    private static function destinationAutorisee(string $redirectTo): bool
    {
        if ($redirectTo === '') {
            return false;
        }

        // Un chemin absolu du site, et rien d'autre : ni schema, ni hote, ni
        // « // » qui ferait une URL protocol-relative.
        //
        // Deux details qui n'en sont pas :
        //   — `%` est EXCLU. Il y figurait, et laissait donc passer une barre
        //     oblique encodee : `/%2f%2fevil.com`, decode par le tenant, devient
        //     `///evil.com`. Le refus de « // » ne servait plus a rien.
        //   — `\z` et non `$`, qui accepte un saut de ligne final : `"/a\n"`
        //     passait, et un saut de ligne dans une valeur qu'on signe est le
        //     debut d'une injection d'en-tete.
        return (bool) preg_match('#^/(?!/)[A-Za-z0-9/_\-.~?&=]*\z#', $redirectTo);
    }

    private function tenantBaseUrl(Tenant $tenant): string
    {
        $metadata = $tenant->metadata ?? [];
        if (isset($metadata['base_url']) && is_string($metadata['base_url'])) {
            return rtrim($metadata['base_url'], '/');
        }

        $subdomain = $tenant->subdomain ?? $tenant->code;
        return "https://{$subdomain}.klassci.com";
    }
}
