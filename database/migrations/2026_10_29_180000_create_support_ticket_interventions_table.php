<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('idempotency_key', 64);
            $table->char('request_hash', 64);
            $table->string('title', 160);
            $table->text('instructions');
            // The master does NOT decide which user has this permission.
            // The school must resolve it using effective authorization.
            $table->string('required_permission', 160);
            $table->unsignedBigInteger('target_external_user_id')->nullable();
            $table->string('status', 32)->default('PENDING_ROUTING');
            // Les comptes admin sont sur une connexion potentiellement distincte :
            // meme convention que support_tickets.assigned_admin_id.
            $table->unsignedBigInteger('created_by_admin_id');
            $table->index('created_by_admin_id');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('routed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['ticket_id', 'idempotency_key'], 'care_intervention_idempotent');
            $table->index(['tenant_id', 'status', 'created_at'], 'care_intervention_tenant_status');
            $table->index(['ticket_id', 'status'], 'care_intervention_ticket_status');
        });

        // Append-only trace; do not delete or edit an action event through UI.
        Schema::create('support_ticket_intervention_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervention_id')->constrained('support_ticket_interventions')->cascadeOnDelete();
            $table->string('event', 48);
            $table->string('actor_type', 24);
            $table->string('actor_ref', 64);
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['intervention_id', 'created_at'], 'care_intervention_event_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_intervention_events');
        Schema::dropIfExists('support_ticket_interventions');
    }
};
