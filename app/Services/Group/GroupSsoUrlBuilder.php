<?php

namespace App\Services\Group;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\SsoTokenSigner;
use App\Support\SsoClaim;
use Illuminate\Support\Facades\Log;

/**
 * L'URL signée qui ouvre un établissement depuis le portail groupe.
 *
 * Ces gardes vivaient dans un widget Livewire, et n'en protégeaient donc qu'un
 * seul écran. La fiche d'un établissement, elle, portait un bouton
 * « Ouvrir l'établissement » qui était un simple lien vers le site : le même
 * libellé, au même endroit, mais le directeur y arrivait sur un écran de
 * connexion alors que le même bouton sur le tableau de bord le connectait.
 *
 * Le contrôle d'appartenance ne doit exister qu'une fois, sinon la deuxième
 * surface l'oublie — et c'est exactement ce qui s'était passé. Le maître est la
 * SEULE autorité sur l'appartenance à un groupe : le tenant ne vérifie que la
 * signature HMAC, l'expiration, la limite de débit et l'open-redirect. Il n'a
 * aucun moyen de rattraper ce contrôle.
 */
class GroupSsoUrlBuilder
{
    /**
     * Le jeton vaut 2 minutes (voir SsoTokenSigner). Les cartes se rafraîchissent
     * toutes les 5 minutes : un clic tardif demande un rechargement, ce qui est
     * le bon compromis pour un jeton d'authentification.
     */
    public function pour(string $tenantCode, string $redirectTo = '/'): ?string
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
        // L'appel d'origine est PUBLIC sur un composant Livewire : il est donc
        // declenchable depuis la console du navigateur, et Livewire renvoie la
        // valeur de retour au JS. Sans ce scope, un membre du groupe ROSTAN
        // pouvait executer `$wire.getSsoUrl('esbtp-abidjan')`, recuperer une URL
        // signee, et se connecter chez un client qui n'est pas le sien.
        //
        // ...ET une ecole que le groupe exploite encore : le code d'une ecole
        // suspendue — typiquement pour impaye — rendait un jeton parfaitement
        // valide.
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

        return $this->urlDeBase($tenant) . '/auth/sso-from-group?token=' . urlencode($token);
    }

    /**
     * Une destination interne au tenant, jamais une URL choisie par l'appelant.
     *
     * Le tenant applique deja un controle d'open-redirect, mais le maitre SIGNE
     * cette valeur : lui laisser passer n'importe quoi revient a authentifier
     * une destination qu'on n'a pas verifiee.
     */
    public static function destinationAutorisee(string $redirectTo): bool
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

    /**
     * L'adresse du site de l'etablissement.
     *
     * `metadata.base_url` est la derogation par etablissement ; sinon le modele
     * compose l'adresse a partir du domaine configure. Le domaine ne se resout
     * qu'a UN endroit : une deuxieme resolution finirait par diverger.
     */
    public function urlDeBase(Tenant $tenant): string
    {
        $metadata = $tenant->metadata ?? [];
        if (isset($metadata['base_url']) && is_string($metadata['base_url'])) {
            return rtrim($metadata['base_url'], '/');
        }

        return $tenant->full_url;
    }
}
