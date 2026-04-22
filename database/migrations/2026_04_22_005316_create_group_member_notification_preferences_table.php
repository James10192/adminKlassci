<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preferences per `GroupMember` — one row per member (unique constraint).
     * Row is auto-created with defaults on first read so the UI never needs a
     * "enable notifications" onboarding step; opt-out is explicit.
     */
    public function up(): void
    {
        Schema::create('group_member_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_member_id')
                ->constrained('group_members')
                ->cascadeOnDelete();

            $table->boolean('email_enabled')->default(true);
            $table->boolean('immediate_critical')->default(true);
            $table->boolean('daily_digest_warnings')->default(true);

            // HH:MM in the app timezone — Africa/Abidjan per config/app.php.
            $table->string('digest_time', 5)->default('08:00');

            // Minimum time between two sends of the same fingerprint. 24h
            // matches the existing GroupAlertCheck::checkGroup dedup window.
            $table->unsignedSmallInteger('dedup_hours')->default(24);

            $table->timestamp('last_digest_sent_at')->nullable();

            // JSON list of AlertType::value strings the member has opted out
            // of. Kept as JSON (not columns) so adding new alert types in the
            // enum doesn't require a migration here.
            $table->json('disabled_alert_types')->nullable();

            $table->timestamps();

            $table->unique('group_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_member_notification_preferences');
    }
};
