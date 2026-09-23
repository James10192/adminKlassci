<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('support_ticket_attachments', function (Blueprint $table) {
            // Empreinte des octets RECUS, pour reconnaitre un renvoi avant tout decodage.
            // `sha256` reste celle du fichier stocke (apres assainissement) : elle
            // dependrait de la version de GD, et un renvoi apres une mise a jour
            // serait pris pour une cle reutilisee.
            $table->char('received_sha256', 64)->nullable()->after('sha256');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_ticket_attachments', function (Blueprint $table) {
            $table->dropColumn('received_sha256');
        });
    }
};
