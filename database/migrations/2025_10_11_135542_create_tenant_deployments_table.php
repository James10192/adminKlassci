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
        Schema::create('tenant_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');

            // Deployment info
            $table->string('git_commit_hash', 40)->comment('Commit hash déployé');
            $table->string('git_branch', 100)->comment('Branche Git déployée');

            // Status
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed', 'rolled_back'])->default('pending');
            $table->text('error_message')->nullable();
            $table->json('error_details')->nullable();

            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable()->comment('Durée du déploiement en secondes');

            // Metadata
            $table->unsignedBigInteger('deployed_by_user_id')->nullable()->comment('ID de l\'admin SaaS qui a lancé le déploiement');
            $table->json('deployment_log')->nullable()->comment('Log complet des étapes du déploiement');

            $table->timestamps();

            // Indexes
            $table->index('tenant_id');
            $table->index('status');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_deployments');
    }
};
