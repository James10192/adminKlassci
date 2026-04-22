<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/up', function () {
    return response()->json(['status' => 'ok'], 200);
});

Route::middleware(['web', 'auth:group', 'throttle:10,1'])
    ->post('/groupe/subscription-banner/dismiss', function () {
        session()->put(
            'gp_subscription_banner_dismissed_until',
            now()->addHours(4),
        );

        return back();
    })
    ->name('groupe.subscription-banner.dismiss');

// Session-scoped alert acknowledgment — alerts are ephemeral (recomputed each
// cache cycle), so a persistent ack table would add schema + fingerprint
// generation + cache-invalidation complexity for an unproven UX. Session
// state with a 4h TTL mirrors the subscription banner dismiss pattern.
Route::middleware(['web', 'auth:group', 'throttle:30,1'])
    ->post('/groupe/alertes/acquitter', function () {
        $fingerprint = (string) request('fingerprint', '');
        if ($fingerprint === '') {
            return back();
        }

        $acknowledged = session('gp_alerts_acknowledged', []);
        $acknowledged[$fingerprint] = now()->addHours(4);
        session()->put('gp_alerts_acknowledged', $acknowledged);

        return back();
    })
    ->name('groupe.alerts.acknowledge');

Route::middleware(['web', 'auth:group', 'throttle:30,1'])
    ->post('/groupe/alertes/retablir', function () {
        $fingerprint = (string) request('fingerprint', '');
        $acknowledged = session('gp_alerts_acknowledged', []);

        if ($fingerprint !== '') {
            unset($acknowledged[$fingerprint]);
            session()->put('gp_alerts_acknowledged', $acknowledged);
        }

        return back();
    })
    ->name('groupe.alerts.unacknowledge');

// Signed unsubscribe link included in every notification email — adds the
// alert type to the member's `disabled_alert_types` so future notifications
// of that type are skipped by AlertNotificationDispatcher. No auth: the
// signature itself proves identity (signed route with member id in URL).
// Signed activation landing after an invitation email — verifies the
// temporary token then lets Filament's auth take over (the user still has
// to enter the temp password + their new one on the set-password page).
Route::middleware(['web', 'signed'])
    ->get('/groupe/activer/{member}', function (int $member) {
        $memberModel = \App\Models\GroupMember::findOrFail($member);
        $token = (string) request('token', '');

        if ($token === '' || $memberModel->invitation_token === null
            || hash('sha256', $token) !== $memberModel->invitation_token) {
            abort(403, 'Jeton invalide ou expiré.');
        }

        // Prime a session flag so the set-password page can short-circuit
        // the mustChangePassword middleware exemption without re-checking
        // the signed URL on every field keystroke.
        session(['gp_invitation_member_id' => $memberModel->id]);

        return redirect()->route('filament.group.auth.login')
            ->with('status', 'Invitation validée. Connectez-vous avec votre mot de passe temporaire, vous serez invité à en définir un nouveau.');
    })
    ->name('groupe.invitation.activate');

Route::middleware(['web', 'auth:group', 'throttle:6,1'])
    ->get('/groupe/definir-mot-de-passe', [\App\Http\Controllers\Group\SetPasswordController::class, 'show'])
    ->name('groupe.set-password.show');

Route::middleware(['web', 'auth:group', 'throttle:6,1'])
    ->post('/groupe/definir-mot-de-passe', [\App\Http\Controllers\Group\SetPasswordController::class, 'store'])
    ->name('groupe.set-password.store');

Route::middleware(['web', 'signed'])
    ->get('/groupe/notifications/desabonner/{member}/{type}', function (int $member, string $type) {
        $memberModel = \App\Models\GroupMember::findOrFail($member);
        $prefs = \App\Models\GroupMemberNotificationPreference::forMember($memberModel);

        if ($type === 'digest') {
            $prefs->update(['daily_digest_warnings' => false]);
        } elseif (\App\Enums\AlertType::tryFrom($type) !== null) {
            $disabled = (array) ($prefs->disabled_alert_types ?? []);
            if (! in_array($type, $disabled, true)) {
                $disabled[] = $type;
                $prefs->update(['disabled_alert_types' => array_values($disabled)]);
            }
        } else {
            // Generic opt-out — kill email entirely
            $prefs->update(['email_enabled' => false]);
        }

        return response()->view('emails.group.unsubscribed', [
            'member' => $memberModel,
            'type' => $type,
        ]);
    })
    ->name('groupe.notifications.unsubscribe');
