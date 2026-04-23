<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loosen the auth identity on group_members so admins can invite DGAs hired
 * from subsidiaries without company email. One of (email, username) must be
 * present — enforced application-side in the GroupMemberObserver, not at the
 * DB level (MySQL CHECK constraints on nullable unique combos are flaky).
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL needs the raw ALTER when changing unique + nullable on an
        // existing column (Doctrine DBAL's inference drops the unique index).
        DB::statement('ALTER TABLE group_members MODIFY COLUMN email VARCHAR(255) NULL');

        Schema::table('group_members', function (Blueprint $table) {
            // slug-shaped lowercase identifier; unique globally, not scoped
            // per-group — the login flow is cross-group so a collision
            // between two groups would confuse the auth lookup.
            $table->string('username', 80)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table) {
            $table->dropColumn('username');
        });

        // Only reinstate NOT NULL if no rows currently have a null email.
        $nullEmails = DB::table('group_members')->whereNull('email')->count();
        if ($nullEmails > 0) {
            throw new \RuntimeException(
                "Cannot rollback: {$nullEmails} group_member(s) have no email. Backfill them first."
            );
        }

        DB::statement('ALTER TABLE group_members MODIFY COLUMN email VARCHAR(255) NOT NULL');
    }
};
