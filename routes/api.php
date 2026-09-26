<?php

use App\Http\Controllers\API\DeployWebhookController;
use App\Http\Controllers\API\LMSRegistryController;
use App\Http\Controllers\API\TenantCacheInvalidateController;
use App\Http\Controllers\API\TenantLimitsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

// Tenant Limits API - Protected by custom API token middleware
Route::middleware(['tenant.api'])->group(function () {
    Route::get('/tenants/{code}/limits', [TenantLimitsController::class, 'show'])
        ->name('api.tenants.limits');

    Route::post('/tenants/{code}/cache/invalidate', TenantCacheInvalidateController::class)
        ->name('api.tenants.cache.invalidate');

    // LMS Registry — liste des tenants actifs pour le login unifié
    Route::get('/lms/tenants', [LMSRegistryController::class, 'tenants'])
        ->name('api.lms.tenants');
});

// Deploy Webhook — appelé par GitHub Actions (pas de CSRF, auth par Bearer token)
Route::post('/deploy', DeployWebhookController::class)->name('api.deploy');

/*
|--------------------------------------------------------------------------
| KLASSCI Care — API instances v1 (docs/support/KLASSCI_CARE_BLUEPRINT.md §13)
|--------------------------------------------------------------------------
| Authentification par identifiant dedie et porte (care.instance:<portee>),
| jamais par tenants.api_token. Aucune route ne porte de code d'instance.
*/
Route::prefix('v1/support')->name('api.care.')->group(function () {
    Route::post('/tickets', [\App\Http\Controllers\API\Care\TicketController::class, 'store'])
        ->middleware(['care.instance:support:create', 'throttle:care-ecriture'])
        ->name('tickets.store');

    Route::post('/tickets/{reference}/messages', [\App\Http\Controllers\API\Care\TicketController::class, 'repondre'])
        ->where('reference', 'KC-\d{4}-\d{6,}')
        ->middleware(['care.instance:support:update', 'throttle:care-ecriture'])
        ->name('tickets.messages.store');

    Route::post('/tickets/{reference}/attachments', [\App\Http\Controllers\API\Care\PieceJointeController::class, 'store'])
        ->where('reference', 'KC-\d{4}-\d{6,}')
        ->middleware(['care.instance:support:update', 'throttle:care-pieces'])
        ->name('tickets.attachments.store');

    Route::middleware(['care.instance:support:read', 'throttle:care-lecture'])->group(function () {
        Route::get('/bootstrap', \App\Http\Controllers\API\Care\BootstrapController::class)->name('bootstrap');
        Route::get('/tickets', [\App\Http\Controllers\API\Care\TicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/{reference}', [\App\Http\Controllers\API\Care\TicketController::class, 'show'])
            ->where('reference', 'KC-\d{4}-\d{6,}')
            ->name('tickets.show');
        Route::get('/tickets/{reference}/attachments/{piece}', [\App\Http\Controllers\API\Care\PieceJointeController::class, 'show'])
            ->where(['reference' => 'KC-\d{4}-\d{6,}', 'piece' => '\d+'])
            ->name('tickets.attachments.show');
    });
});

// API du CLI de l'équipe (klassci admin:*) : voir routes/api-cli.php.
require __DIR__ . '/api-cli.php';
