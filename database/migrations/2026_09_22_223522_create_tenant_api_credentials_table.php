<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les identifiants qu'une instance presente au Master pour KLASSCI Care.
 *
 * Pourquoi une table a part, et pas `tenants.api_token` : ce jeton-la est
 * stocke en clair, accepte en parametre d'URL, et ne porte aucune portee.
 * Celui-ci n'est jamais stocke en clair (empreinte SHA-256 d'un secret de
 * 40 caracteres aleatoires : un bcrypt n'ajoute rien a un secret de cette
 * entropie), se cherche par `key_id` public, et porte ses portees.
 *
 * Deux identifiants actifs pour une meme instance est normal : c'est la
 * rotation — on emet le nouveau, on le pose dans le .env, on revoque l'ancien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_api_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('key_id', 16)->unique();
            $table->string('secret_hash', 64);
            $table->json('scopes');
            $table->string('label')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_api_credentials');
    }
};
