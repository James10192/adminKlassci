<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le jeton avec lequel la console lit l'API CLI de l'école (capacité
 * cli:read seulement). Chiffré par le modèle : text, parce qu'un jeton
 * chiffré dépasse largement 255 caractères.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('cli_lecture_token')->nullable()->after('api_token_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('cli_lecture_token');
        });
    }
};
