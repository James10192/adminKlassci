<?php

namespace App\Providers;

use App\Domain\Care\Acces\Services\Capacites;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** KLASSCI Care : capacites du personnel et limites de debit par instance. */
class CareServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (Capacites::toutes() as $capacite) {
            Gate::define($capacite, fn ($user) => Capacites::accorde($user, $capacite));
        }

        // Par instance, pas par IP : toutes les instances partagent le meme hote.
        $parInstance = fn (Request $r) => 'care:'.(optional($r->attributes->get('care_tenant'))->id ?? 'ip:'.$r->ip());

        RateLimiter::for('care-ecriture', fn (Request $r) => Limit::perMinute((int) config('care.limites.tickets_par_minute'))->by($parInstance($r)));
        RateLimiter::for('care-lecture', fn (Request $r) => Limit::perMinute((int) config('care.limites.lectures_par_minute'))->by($parInstance($r)));
    }
}
