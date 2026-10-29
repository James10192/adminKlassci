<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lien optionnel vers les groupes preexistants du portail adminKLASSCI.
     * AUCUN tenant ni groupe historique n'est transforme automatiquement.
     */
    public function up(): void
    {
        Schema::table('saas_organizations', function (Blueprint $table) {
            $table->foreignId('legacy_group_id')
                ->nullable()
                ->unique('saas_org_legacy_group_unique')
                ->constrained('groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('saas_organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legacy_group_id');
        });
    }
};
