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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            // Identification
            $table->string('code', 50)->unique()->comment('Code unique du tenant (ex: esbtp-abidjan)');
            $table->string('name')->comment('Nom de l\'établissement');
            $table->string('subdomain', 100)->unique()->comment('Sous-domaine (ex: esbtp-abidjan)');

            // Database info
            $table->string('database_name', 100)->comment('Nom de la base de données (ex: c2569688c_esbtp_abidjan)');
            $table->json('database_credentials')->comment('Identifiants DB (host, port, username, password cryptés)');

            // Git info
            $table->string('git_branch', 100)->comment('Branche Git du tenant');
            $table->string('git_commit_hash', 40)->nullable()->comment('Dernier commit hash déployé');
            $table->timestamp('last_deployed_at')->nullable()->comment('Date du dernier déploiement');

            // Status
            $table->enum('status', ['active', 'suspended', 'maintenance', 'cancelled'])->default('active');

            // Subscription & Billing
            $table->enum('plan', ['free', 'essentiel', 'professional', 'elite'])->default('essentiel');
            $table->decimal('monthly_fee', 10, 2)->default(0)->comment('Frais mensuel en FCFA');
            $table->date('subscription_start_date')->nullable();
            $table->date('subscription_end_date')->nullable();

            // Limites par plan
            $table->integer('max_users')->default(5)->comment('Limite utilisateurs total');
            $table->integer('max_staff')->default(5)->comment('Limite personnel (enseignants + coordinateurs + secrétaires)');
            $table->integer('max_students')->default(50)->comment('Limite étudiants');
            $table->integer('max_inscriptions_per_year')->default(50)->comment('Limite inscriptions par année universitaire');
            $table->integer('max_storage_mb')->default(512)->comment('Limite stockage en MB');

            // Usage actuel (mis à jour par le scheduler)
            $table->integer('current_users')->default(0);
            $table->integer('current_staff')->default(0);
            $table->integer('current_students')->default(0);
            $table->integer('current_storage_mb')->default(0);

            // Contacts
            $table->string('admin_name')->nullable();
            $table->string('admin_email')->nullable();
            $table->string('support_email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();

            // Metadata
            $table->json('metadata')->nullable()->comment('Données additionnelles flexibles');

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('status');
            $table->index('plan');
            $table->index('subscription_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
