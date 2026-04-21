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
