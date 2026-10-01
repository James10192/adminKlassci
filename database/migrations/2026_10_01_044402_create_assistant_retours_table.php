<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KLASSCI Care : les 👍 / 👎 donnés dans les écoles sur les réponses de Nanan.
 *
 * `avis` est une chaîne, pas un ENUM SQL : la valeur vit dans un enum PHP
 * (App\Domain\Care\Retours\Enums\AvisAssistant), comme les statuts des demandes,
 * et les tests tournent sur SQLite.
 *
 * Deux index uniques, deux idempotences : la clé d'envoi (un réseau qui renvoie
 * la même requête), et la personne sur un message (un avis par personne et par
 * réponse, le dernier l'emporte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_retours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('cle', 64);

            $table->string('avis', 16);
            $table->string('raison', 60)->nullable();
            $table->text('commentaire')->nullable();
            $table->text('question');
            $table->text('reponse');
            $table->string('modele', 120)->nullable();
            $table->string('page', 255)->nullable();

            $table->unsignedBigInteger('utilisateur_id_externe');
            $table->string('utilisateur_nom', 160);
            $table->string('utilisateur_role', 64)->nullable();
            $table->string('conversation_ref', 64)->nullable();
            $table->string('message_ref', 64);
            $table->timestamp('donne_le');

            $table->timestamp('traite_le')->nullable();
            $table->unsignedBigInteger('traite_par')->nullable();
            $table->text('note_interne')->nullable();
            $table->foreignId('support_ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'cle']);
            $table->unique(['tenant_id', 'message_ref', 'utilisateur_id_externe'], 'assistant_retours_message_personne_unique');
            $table->index(['tenant_id', 'avis', 'created_at']);
            $table->index(['avis', 'traite_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_retours');
    }
};
