<?php

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
});
