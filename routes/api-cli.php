<?php

use App\Domain\Cli\CapacitesCli as Cap;
use App\Http\Controllers\API\Cli\DemandesController;
use App\Http\Controllers\API\Cli\DeploiementsController;
use App\Http\Controllers\API\Cli\EcolesController;
use App\Http\Controllers\API\Cli\JournalController;
use App\Http\Controllers\API\Cli\SanteController;
use App\Http\Controllers\API\Cli\SauvegardesController;
use App\Http\Controllers\API\Cli\SqlController;
use Illuminate\Support\Facades\Route;

/*
| API du CLI de l'équipe KLASSCI (klassci admin:*). Un jeton par membre
| (php artisan cli:jeton <email>) ; chaque route exige une capacité, que le
| rôle actuel du membre doit encore porter (App\Domain\Cli\CapacitesCli).
*/
Route::prefix('cli')->name('api.cli.')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::middleware('cli.capacite:' . Cap::LIRE)->group(function () {
        Route::get('/ecoles', [EcolesController::class, 'index'])->name('ecoles.index');
        Route::get('/ecoles/{code}', [EcolesController::class, 'show'])->name('ecoles.show');
        Route::get('/sante', [SanteController::class, 'index'])->name('sante.index');
        Route::get('/deploiements', [DeploiementsController::class, 'index'])->name('deploiements.index');
        Route::get('/deploiements/{id}', [DeploiementsController::class, 'show'])->whereNumber('id')->name('deploiements.show');
        Route::get('/sauvegardes', [SauvegardesController::class, 'index'])->name('sauvegardes.index');
        Route::get('/demandes', [DemandesController::class, 'index'])->name('demandes.index');
        Route::get('/demandes/{reference}', [DemandesController::class, 'show'])->name('demandes.show');
        Route::get('/journal', [JournalController::class, 'index'])->name('journal.index');
    });

    Route::middleware(['cli.capacite:' . Cap::OPERER, 'throttle:20,1'])->group(function () {
        Route::post('/ecoles/{code}/sante', [SanteController::class, 'lancer'])->name('sante.lancer');
        Route::post('/ecoles/{code}/stats', [EcolesController::class, 'stats'])->name('ecoles.stats');
        Route::post('/ecoles/{code}/sauvegardes', [SauvegardesController::class, 'store'])->name('sauvegardes.store');
        Route::post('/scanner', [EcolesController::class, 'scanner'])->name('scanner');
    });

    Route::post('/ecoles/{code}/deploiements', [DeploiementsController::class, 'store'])
        ->middleware(['cli.capacite:' . Cap::DEPLOYER, 'throttle:10,1'])
        ->name('deploiements.store');

    Route::post('/sql', SqlController::class)
        ->middleware(['cli.capacite:' . Cap::SQL, 'throttle:30,1'])
        ->name('sql');
});
