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
