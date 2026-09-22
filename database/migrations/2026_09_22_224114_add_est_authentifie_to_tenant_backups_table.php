<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retient qu'une sauvegarde a été scellée à sa création.
 *
 * Le sceau vit dans un fichier à côté de l'archive. Sans cette colonne,
 * supprimer le fichier suffirait à faire passer une archive modifiée pour une
 * archive ancienne, prise avant le scellement, qu'on accepte encore de relire.
 * La colonne ferme ce détour : une sauvegarde marquée scellée dont le sceau a
 * disparu est refusée.
 *
 * `nullable`, comme `est_chiffre` : les sauvegardes déjà prises n'ont pas été
 * scellées, et nul ne peut dire aujourd'hui si elles sont restées intactes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_backups', function (Blueprint $table) {
            $table->boolean('est_authentifie')->nullable()->after('est_chiffre');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_backups', function (Blueprint $table) {
            $table->dropColumn('est_authentifie');
        });
    }
};
