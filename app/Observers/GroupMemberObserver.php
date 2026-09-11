<?php

namespace App\Observers;

use App\Models\GroupMember;
use App\Services\Group\GroupMemberInvitationService;
use App\Services\Group\TemporaryPasswordGenerator;
use App\Services\Group\UsernameGenerator;

/**
 * Triggers the invitation flow for newly created members. Runs via the
 * `created` hook (not `creating`) so the row has an id — the activation URL
 * needs it for the signed route.
 *
 * Admins who paste their own password in the form are respected: if the
 * member already has password_changed_at set, we skip the invitation.
 * That preserves the manual-entry escape hatch when an ops cadre prefers to
 * hand-hold a member face-to-face.
 */
class GroupMemberObserver
{
    /**
     * Complète, avant l'INSERT, les deux colonnes qu'un admin peut légitimement
     * laisser vides dans le formulaire :
     *
     *  - `username`, quand il n'y a pas non plus d'email pour se connecter ;
     *  - `password`, qui est NOT NULL en base. Le mot de passe temporaire est
     *    conservé en clair sur l'instance (propriété transitoire, jamais
     *    persistée) pour que l'interface puisse l'afficher une seule fois quand
     *    aucune invitation par email ne part.
     *
     * Le hook `creating` — et non le formulaire Filament — parce que toutes les
     * voies de création passent par là : panel, tinker, seeder, commande.
     */
    public function creating(GroupMember $member): void
    {
        if (empty($member->username) && empty($member->email)) {
            $member->username = app(UsernameGenerator::class)->generate($member->name ?? '');
        }

        if (empty($member->getAttribute('password'))) {
            $motDePasse = app(TemporaryPasswordGenerator::class)->generate();

            // Le cast `hashed` du modèle chiffre la valeur à l'affectation.
            $member->password = $motDePasse;
            $member->password_changed_at = null;
            $member->motDePasseTemporaire = $motDePasse;
        }
    }

    public function created(GroupMember $member): void
    {
        if (! config('group_portal.invite_flow_enabled', false)) {
            return;
        }

        // Admin chose to set the password themselves — honor that path.
        if ($member->password_changed_at !== null) {
            return;
        }

        // Username-only members skip the email invitation. Admin must share
        // the temporary password via `php artisan group-portal:reset-password`
        // which prints the credentials on-screen.
        if (empty($member->email)) {
            return;
        }

        app(GroupMemberInvitationService::class)->invite($member);

        // L'invitation vient de remplacer le mot de passe généré ci-dessus :
        // celui qu'on gardait en mémoire ne vaut plus rien, ne l'affichons pas.
        $member->motDePasseTemporaire = null;
    }
}
