<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une reponse de l'ecole porte la cle d'idempotence de son envoi : un renvoi
     * dont la reponse s'est perdue retrouve son message au lieu d'en doubler un.
     * Les messages du support n'en ont pas (nulle, hors de l'unicite).
     */
    public function up(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->string('client_key', 64)->nullable()->after('body');
            $table->unique(['ticket_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->dropUnique(['ticket_id', 'client_key']);
            $table->dropColumn('client_key');
        });
    }
};
