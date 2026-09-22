<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KLASSCI Care, tranche 1 : la demande, son contexte, ses messages et son
 * journal.
 *
 * Les colonnes de statut sont des chaines, pas des ENUM SQL : les valeurs
 * vivent dans des enums PHP (App\Domain\Care\Tickets\Enums), les tests
 * tournent sur SQLite, et ajouter un statut ne doit couter aucun ALTER.
 *
 * L'idempotence tient dans la table des demandes elle-meme — une cle unique
 * par instance — plutot que dans une table de cles a part : la seule
 * ecriture qui en a besoin aujourd'hui est la creation d'une demande, et
 * rejouer une cle revient a relire la demande qu'elle a creee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->nullable()->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('idempotency_key', 64);
            $table->string('request_hash', 64);

            $table->unsignedBigInteger('reporter_external_id');
            $table->string('reporter_name_snapshot')->nullable();
            $table->string('reporter_email_snapshot')->nullable();
            $table->json('reporter_roles_snapshot')->nullable();

            $table->string('channel', 32)->default('IN_APP');
            $table->string('customer_category', 32);
            $table->string('internal_category', 32)->nullable();
            $table->string('title', 160);
            $table->text('description');

            $table->string('status', 40);
            $table->string('severity', 8)->nullable();
            $table->string('priority', 4)->nullable();
            $table->string('product_area', 64)->nullable();

            $table->unsignedBigInteger('assigned_admin_id')->nullable();
            $table->boolean('is_security_restricted')->default(false);

            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('triaged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'reporter_external_id']);
            $table->index(['status', 'severity']);
            $table->index('assigned_admin_id');
            $table->index('created_at');
        });

        Schema::create('support_ticket_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained('support_tickets')->cascadeOnDelete();
            $table->string('route_name', 160)->nullable();
            $table->string('url_path', 255)->nullable();
            $table->string('module', 64)->nullable();
            $table->string('page_title', 160)->nullable();
            $table->string('entity_type', 32)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('class_id')->nullable();

            // Pose par le Master a la reception, jamais par l'instance :
            // c'est lui qui sait ce qui a ete deploye.
            $table->string('app_commit_sha', 40)->nullable();
            $table->string('git_branch', 100)->nullable();
            $table->unsignedBigInteger('deployment_id')->nullable();

            $table->string('browser_family', 32)->nullable();
            $table->string('browser_version', 16)->nullable();
            $table->string('os_family', 32)->nullable();
            $table->string('device_type', 16)->nullable();
            $table->string('viewport', 16)->nullable();
            $table->string('locale', 12)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->json('request_ids')->nullable();
            $table->json('extras')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index('module');
            $table->index('route_name');
            $table->index('app_commit_sha');
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('author_type', 16);
            $table->string('author_ref', 64)->nullable();
            $table->string('author_name', 160)->nullable();
            $table->string('visibility', 32);
            $table->text('body');
            $table->timestamps();

            $table->index(['ticket_id', 'visibility']);
        });

        // Journal immuable : pas d'updated_at, le modele refuse toute
        // modification et toute suppression.
        Schema::create('support_ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('type', 48);
            $table->string('actor_type', 16);
            $table->string('actor_ref', 64)->nullable();
            $table->string('from_value', 64)->nullable();
            $table->string('to_value', 64)->nullable();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable(); // pose par Eloquent, pas par MySQL (fuseau)

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_events');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_ticket_contexts');
        Schema::dropIfExists('support_tickets');
    }
};
