<?php

namespace App\Services\Group;

use App\Mail\Group\GroupMemberPasswordResetMail;
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
 * Réinitialisation par lien à usage unique, sans exposer ni modifier
 * le mot de passe courant avant la validation par le membre lui-même.
 */
final class GroupMemberAccessReset
{
    public function reset(User $operator, int $groupId, int $memberId): void
    {
        Gate::forUser($operator)->authorize(SaasCapabilities::MANAGE_GROUP_MEMBERS);

        if (! config('group_portal.invite_flow_enabled', false)) {
            throw ValidationException::withMessages([
                'reset' => "Les invitations par e-mail sont désactivées. Utilisez la procédure d'assistance vérifiée.",
            ]);
        }

        $key = 'group:reset-access:'.$memberId;
        $max = max(1, (int) config('group_portal.member_reset_max_attempts', 3));
        $decay = max(60, (int) config('group_portal.member_reset_window_seconds', 3600));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages([
                'reset' => 'Trop de demandes de réinitialisation. Réessayez plus tard.',
            ]);
        }

        DB::transaction(function () use ($groupId, $memberId): void {
            $member = GroupMember::query()->whereKey($memberId)->lockForUpdate()->firstOrFail();
            if ((int) $member->group_id !== $groupId || ! $member->is_active || blank($member->email)) {
                throw ValidationException::withMessages([
                    'reset' => "Ce membre n'est pas éligible à la réinitialisation par e-mail dans ce groupe.",
                ]);
            }

            $token = Str::random(64);
            $validUntil = now()->addHours(max(1, (int) config('group_portal.invitation_ttl_hours', 24)));

            // Le secret d'authentification actuel reste INCHANGÉ. Seul le
            // jeton de récupération est renouvelé, révoquant les anciens.
            $member->forceFill([
                'invitation_token' => hash('sha256', $token),
                'invitation_sent_at' => now(),
            ])->save();

            $url = URL::temporarySignedRoute(
                'groupe.password-reset.show',
                $validUntil,
                ['member' => $member->id, 'token' => $token],
            );

            Mail::to($member->email)->queue(
                (new GroupMemberPasswordResetMail($member, $url))->afterCommit()
            );
        });

        RateLimiter::hit($key, $decay);
        // Aucune garantie de livraison : seule la mise en file est confirmée.
        // Si la file échoue après commit, l'ancien mot de passe reste utilisable.
    }
}
