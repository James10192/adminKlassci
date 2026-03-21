<?php

use App\Http\Controllers\API\DeployWebhookController;
use App\Http\Controllers\API\LMSRegistryController;
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

    // LMS Registry — liste des tenants actifs pour le login unifié
    Route::get('/lms/tenants', [LMSRegistryController::class, 'tenants'])
        ->name('api.lms.tenants');
});

// Deploy Webhook — appelé par GitHub Actions (pas de CSRF, auth par Bearer token)
Route::post('/deploy', DeployWebhookController::class)->name('api.deploy');
