<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only log of every email dispatched by the group notification
     * pipeline. Serves two purposes:
     *   - Dedup: before sending, check last row per (member, fingerprint) and
     *     skip if within `dedup_hours`.
     *   - Audit: ops can answer "was the founder notified when tenant X
     *     expired?" without grepping mail server logs.
     *
     * Retention is a future concern (30-90 days via cleanup command); the
     * index on (group_member_id, fingerprint, sent_at) keeps lookups fast
     * until then.
     */
    public function up(): void
    {
        Schema::create('group_alert_notifications_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_member_id')
                ->constrained('group_members')
                ->cascadeOnDelete();

            // group_id copied from the member row so dashboards don't need
            // to join group_members just to get the parent group.
            $table->unsignedBigInteger('group_id')->index();

            // Null for group-level alerts that don't target a single tenant.
            $table->string('tenant_code', 50)->nullable()->index();

            $table->string('alert_type', 40);
            $table->string('severity', 20);

            // sha256({group_id, tenant_code, alert_type, severity}) — severity
            // deliberately included so "quota 91% Warning" and "quota 101%
            // Critical" cross into different fingerprints and re-notify.
            $table->string('fingerprint', 64);

            $table->enum('channel', ['immediate', 'digest']);

            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['group_member_id', 'fingerprint', 'sent_at'], 'ganl_dedup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_alert_notifications_log');
    }
};
