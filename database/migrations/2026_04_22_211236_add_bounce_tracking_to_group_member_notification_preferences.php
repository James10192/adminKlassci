<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_member_notification_preferences', function (Blueprint $table) {
            $table->unsignedInteger('bounce_count')->default(0)->after('dedup_hours');
            $table->timestamp('last_bounce_at')->nullable()->after('bounce_count');
            // Last SMTP response code (as string '550', '421', etc.) parsed from
            // the TransportException message at the time of failure. Stored for
            // audit so ops can distinguish provider rate-limits from dead mailboxes.
            $table->string('last_bounce_smtp_code', 8)->nullable()->after('last_bounce_at');
            // 'hard' (5xx, counts toward auto-disable) or 'soft' (4xx, logged only).
            $table->string('last_bounce_type', 8)->nullable()->after('last_bounce_smtp_code');
            $table->boolean('disabled_due_to_bounces')->default(false)->after('last_bounce_type');
        });
    }

    public function down(): void
    {
        Schema::table('group_member_notification_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'bounce_count',
                'last_bounce_at',
                'last_bounce_smtp_code',
                'last_bounce_type',
                'disabled_due_to_bounces',
            ]);
        });
    }
};
