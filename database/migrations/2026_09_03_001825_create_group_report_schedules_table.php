<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_report_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();

            // Clé du rapport dans ReportRegistry. Volontairement une chaîne et
            // non un enum SQL : ajouter un rapport ne doit pas demander une
            // migration sur la base maîtresse.
            $table->string('report_key', 64);

            $table->enum('frequency', ['weekly', 'monthly']);

            // 1 = lundi … 7 = dimanche (ISO). Nul pour un envoi mensuel.
            $table->unsignedTinyInteger('day_of_week')->nullable();

            // 1 à 31, ramené au dernier jour du mois quand il n'existe pas
            // (le 31 en février). Nul pour un envoi hebdomadaire.
            $table->unsignedTinyInteger('day_of_month')->nullable();

            $table->unsignedTinyInteger('hour')->default(7);

            // Identifiants de membres du groupe, jamais des adresses libres :
            // un état financier consolidé ne doit pas pouvoir être programmé
            // vers une adresse extérieure depuis le portail.
            $table->json('recipient_member_ids');

            $table->boolean('is_active')->default(true);

            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('group_members')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // La commande balaie les programmations actives d'un groupe.
            $table->index(['is_active', 'group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_report_schedules');
    }
};
