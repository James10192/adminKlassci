<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copie, au master, des avis 👍 / 👎 donnés aux réponses de Nanan dans chaque
 * école (`assistant_retours`). Sert à juger chaque modèle à ce que les écoles
 * en disent, pas seulement à ce qu'il coûte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_ai_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('source_id')->comment('assistant_retours.id dans la base de l\'école');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('nom_utilisateur')->nullable();
            $table->string('avis', 12);
            $table->string('raison', 20)->nullable();
            $table->string('commentaire', 1000)->nullable();
            $table->string('modele', 64)->nullable();
            $table->string('palier', 16)->nullable();
            $table->string('care_reference', 64)->nullable();
            $table->timestamp('survenue_at')->nullable();
            $table->timestamp('modifie_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_id']);
            $table->index(['modele', 'survenue_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_feedbacks');
    }
};
