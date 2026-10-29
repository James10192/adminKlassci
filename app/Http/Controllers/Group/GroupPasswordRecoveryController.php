<?php

namespace App\Http\Controllers\Group;

use App\Http\Controllers\Controller;
use App\Models\GroupMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Lien signé + jeton haché, sans authentification préalable ni
 * transmission de mot de passe temporaire par courriel.
 */
final class GroupPasswordRecoveryController extends Controller
{
    public function show(Request $request, int $member): Response
    {
        $account = $this->eligible($member, (string) $request->query('token', ''));

        return response()->view('groupe.set-password', [
            'member' => $account,
            'resetMode' => true,
            'formAction' => $request->fullUrl(),
            'minPasswordLength' => max(8, (int) config('group_portal.member_password_min_length', 10)),
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function store(Request $request, int $member): RedirectResponse
    {
        $validated = $request->validate([
            'password' => [
                'required', 'confirmed',
                Password::min(max(8, (int) config('group_portal.member_password_min_length', 10)))
                    ->letters()->numbers(),
            ],
        ]);

        $token = (string) $request->query('token', '');

        DB::transaction(function () use ($member, $token, $validated): void {
            $account = GroupMember::query()->whereKey($member)->lockForUpdate()->firstOrFail();
            $this->assertToken($account, $token);
            $account->recordPasswordRotation($validated['password']);
        });

        return redirect()->route('filament.group.auth.login')
            ->with('status', 'Votre mot de passe a été mis à jour. Connectez-vous avec votre nouvel accès.');
    }

    private function eligible(int $member, string $token): GroupMember
    {
        $account = GroupMember::query()->findOrFail($member);
        $this->assertToken($account, $token);

        return $account;
    }

    private function assertToken(GroupMember $member, string $token): void
    {
        $ttl = max(1, (int) config('group_portal.invitation_ttl_hours', 24));
        if (! $member->is_active || $token === ''
            || $member->invitation_token === null
            || ! hash_equals((string) $member->invitation_token, hash('sha256', $token))
            || ! $member->invitation_sent_at
            || $member->invitation_sent_at->lt(now()->subHours($ttl))
        ) {
            // Réponse non révélatrice : ne pas exposer l'existence du compte.
            abort(403, 'Lien invalide ou expiré. Demandez un nouvel accès.');
        }
    }
}
