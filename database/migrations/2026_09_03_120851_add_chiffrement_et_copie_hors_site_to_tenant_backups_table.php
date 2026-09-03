<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace ce qu'il est advenu de chaque sauvegarde : est-elle chiffrée, et
 * a-t-elle quitté le serveur qu'elle protège ?
 *
 * Sans ces deux colonnes, on ne peut pas répondre à la question qu'une
 * direction d'établissement pose par écrit — et on ne peut pas non plus la
 * mesurer soi-même. Une sauvegarde restée en clair à côté de la base qu'elle
 * sauvegarde ne protège que d'une suppression accidentelle ; ni d'une perte
 * du serveur, ni d'une lecture par un tiers.
 *
 * `nullable` à dessein : les sauvegardes déjà prises n'ont jamais été ni
 * chiffrées ni copiées, et leur mentir en les marquant `false` serait aussi
 * faux que de les marquer `true`. Nul ne sait, et la colonne le dit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_backups', function (Blueprint $table) {
            $table->boolean('est_chiffre')->nullable()->after('size_bytes');
            $table->string('copie_hors_site')->nullable()->after('est_chiffre');
            $table->timestamp('copie_hors_site_at')->nullable()->after('copie_hors_site');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_backups', function (Blueprint $table) {
            $table->dropColumn(['est_chiffre', 'copie_hors_site', 'copie_hors_site_at']);
        });
    }
};
