<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_members', function (Blueprint $table) {
            // Null = user has never logged in with a password they set themselves
            // (admin-invited + not yet activated). Filled timestamp = the member
            // has rotated their password at least once. Chosen over a boolean
            // because it gives audit info for free (last rotation date).
            $table->timestamp('password_changed_at')->nullable()->after('password');
            // Signed URL carries the raw token; the DB column holds the sha256
            // hash so a leaked DB dump can't be replayed. Unique index supports
            // O(1) lookup from the activation landing page.
            $table->string('invitation_token', 64)->nullable()->unique()->after('password_changed_at');
            $table->timestamp('invitation_sent_at')->nullable()->after('invitation_token');
        });

        // Grandfather existing members — they already control their password
        // because an admin set it manually at creation. Without this backfill
        // flipping the flag on would lock them out at deploy time.
        DB::statement('UPDATE group_members SET password_changed_at = COALESCE(password_changed_at, created_at)');
    }

    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table) {
            $table->dropColumn(['password_changed_at', 'invitation_token', 'invitation_sent_at']);
        });
    }
};
