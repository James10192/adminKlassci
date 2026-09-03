<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quand les effectifs d'un établissement ont-ils été relevés pour la dernière fois.
 *
 * Le portail groupe affiche `current_students` / `current_staff` quand la base
 * de l'école ne répond pas — un repli légitime, à condition de dire l'âge du
 * chiffre. `updated_at` ne peut pas le dire : il est bousculé par n'importe
 * quelle modification depuis Filament, un changement de plan ou d'adresse
 * suffit à le rafraîchir. L'API le renvoie pourtant déjà aux tenants sous le
 * nom `last_stats_update`, ce qui est faux aujourd'hui.
 *
 * Nullable : les établissements existants n'ont jamais été horodatés, et
 * inventer une date serait exactement le défaut qu'on corrige. `tenant:update-stats`
 * la pose au premier passage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('stats_measured_at')->nullable()->after('current_storage_mb');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('stats_measured_at');
        });
    }
};
