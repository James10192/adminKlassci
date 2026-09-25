<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consommation d'IA des écoles, rapatriée par tenant:sync-ai-usage : une ligne
 * par ligne `assistant_consommations` de l'école (source_id = son identifiant là-bas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_ai_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('source_id');
            $table->timestamp('survenue_at')->nullable();
            // Identifiant et nom de la personne dans l'école : il n'y a pas d'utilisateur ici.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('nom_utilisateur')->nullable();
            $table->string('fonction', 20);
            $table->string('modele', 60);
            $table->string('fournisseur', 30);
            $table->string('identifiant_modele', 120);
            $table->string('palier', 20)->nullable();
            $table->unsignedInteger('appels')->default(1);
            $table->unsignedInteger('tokens_entree')->default(0);
            $table->unsignedInteger('tokens_sortie')->default(0);
            $table->unsignedInteger('tokens_cache')->default(0);
            $table->decimal('cout_usd', 12, 6)->default(0);
            $table->decimal('cout_fcfa', 12, 2)->default(0);
            $table->boolean('cout_exact')->default(false);
            $table->string('statut', 20)->default('ok');
            $table->timestamps();

            $table->unique(['tenant_id', 'source_id']);
            $table->index(['tenant_id', 'survenue_at']);
            $table->index('survenue_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_usages');
    }
};
