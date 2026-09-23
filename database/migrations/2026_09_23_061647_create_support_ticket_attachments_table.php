<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les pieces jointes d'une demande. Le fichier vit sur un disque prive
     * (jamais le disque public) ; la table n'en garde que le chemin, l'empreinte
     * et ce qu'il faut pour le servir.
     */
    public function up(): void
    {
        Schema::create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('author_type', 16);
            $table->string('author_ref', 64)->nullable();
            $table->string('author_name', 160)->nullable();
            $table->string('visibility', 32);
            // Nom affiche : celui du fichier envoye, nettoye. Jamais le chemin.
            $table->string('original_name', 160);
            $table->string('mime', 64);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('disk', 32);
            $table->string('path', 255);
            // Empreinte du fichier STOCKE (apres re-encodage) : sert au renvoi idempotent.
            $table->char('sha256', 64);
            $table->string('client_key', 64)->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'visibility']);
            $table->unique(['ticket_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
    }
};
