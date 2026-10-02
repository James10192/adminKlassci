<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le contrôle « Actions lentes » lit désormais la table de l'école par la
 * connexion de base que la console ouvre déjà : le jeton de lecture CLI par
 * école n'a plus d'usage. Le jeton ne servait qu'à ce contrôle.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tenants', 'cli_lecture_token')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('cli_lecture_token');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tenants', 'cli_lecture_token')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->text('cli_lecture_token')->nullable()->after('api_token_created_at');
            });
        }
    }
};
