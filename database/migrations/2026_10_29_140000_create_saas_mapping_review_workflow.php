<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registre de décisions séparé des données historiques et du SaaS actif.
        Schema::create('saas_mapping_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legacy_tenant_id')->unique()->constrained('tenants')->restrictOnDelete();
            $table->foreignId('proposed_group_id')->nullable()->constrained('groups')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('saas_products')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('state', 32)->default('draft');
            $table->string('evidence_reference', 255);
            $table->foreignId('created_by')->constrained('saas_admins')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('saas_admins')->restrictOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['state', 'updated_at']);
        });

        Schema::create('saas_mapping_review_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saas_mapping_review_id')
                ->constrained('saas_mapping_reviews')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('saas_admins')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['saas_mapping_review_id', 'created_at'], 'saas_review_event_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_mapping_review_events');
        Schema::dropIfExists('saas_mapping_reviews');
    }
};
