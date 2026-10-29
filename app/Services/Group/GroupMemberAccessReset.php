<?php

namespace App\Services\Group;

use App\Mail\Group\GroupMemberInvitationMail;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\Saas\SaasCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Réinitialisation explicite d'un membre existant, sans divulgation des hashes
 * ou mot de passe au personnel. Pas de mise à jour silencieuse depuis EditAction.
 */
final class GroupMemberAccessReset
{
    public function __construct(private TemporaryPasswordGenerator $generator)
    {
    }

    public function reset(User $operator, int $groupId, int $memberId): void
    {
        Gate::forUser($operator)->authorize(SaasCapabilities::MANAGE_GROUP_MEMBERS);

        if (! config('group_portal.invite_flow_enabled', false)) {
            throw ValidationException::withMessages([
                'reset' => "Les invitations par e-mail sont désactivées. Réactivez le flux ou utilisez la procédure d'assistance vérifiée.",
            ]);
        }

        $key = 'group:reset-access:'.$memberId;
        $max = max(1, (int) config('group_portal.member_reset_max_attempts', 3));
        $decay = max(60, (int) config('group_portal.member_reset_window_seconds', 3600));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages([
                'reset' => "Trop de demandes de réinitialisation. Réessayez plus tard.",
            ]);
        }

        DB::transaction(function () use ($operator, $groupId, $memberId): void {
            $member = GroupMember::query()->whereKey($memberId)->lockForUpdate()->firstOrFail();

            if ((int) $member->group_id !== $groupId || ! $member->is_active || blank($member->email)) {
                throw ValidationException::withMessages([
                    'reset' => "Ce membre n'est pas éligible à une réinitialisation par e-mail dans ce groupe.",
                ]);
            }

            $temporaryPassword = $this->generator->generate();
            $rawToken = Str::random(64);
            $url = URL::temporarySignedRoute(
                'groupe.invitation.activate',
                now()->addHours(max(1, (int) config('group_portal.invitation_ttl_hours', 24))),
                ['member' => $member->id, 'token' => $rawToken],
            );

            // Nouvelle empreinte d'accès et invitation persistées ensemble.
            // La mise en file doit être acquittée, sinon la transaction annule
            // le reset pour ne pas laisser l'ancien membre sans identifiants.
            $member->assignInvitationCredentials($temporaryPassword, hash('sha256', $rawToken));

            Mail::to($member->email)->queue(
                (new GroupMemberInvitationMail($member, $temporaryPassword, $url))->afterCommit()
            );
        });

        RateLimiter::hit($key, $decay);
        // L'envoi est mis en file, pas garanti délivré : la notification UI
        // ne doit jamais prétendre que le destinataire a reçu son e-mail.
    }
}
