<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doctrine DBAL cannot parse an existing ENUM column — Schema::table with
 * ->enum()->change() hangs or drops the column's data. Use a raw ALTER
 * statement instead (MySQL-specific but this project targets MySQL only).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE group_members MODIFY COLUMN role ENUM(
            'fondateur',
            'directeur_general',
            'directeur_general_adjoint',
            'directeur_financier'
        ) NOT NULL DEFAULT 'fondateur'");
    }

    public function down(): void
    {
        // Safety: forbid the downgrade when an existing row already uses the
        // new value, otherwise MySQL silently coerces it to '' and we lose
        // audit history.
        $orphans = DB::table('group_members')
            ->where('role', 'directeur_general_adjoint')
            ->count();

        if ($orphans > 0) {
            throw new \RuntimeException(
                "Cannot rollback: {$orphans} group_member(s) still use the 'directeur_general_adjoint' role. Reassign them first."
            );
        }

        DB::statement("ALTER TABLE group_members MODIFY COLUMN role ENUM(
            'fondateur',
            'directeur_general',
            'directeur_financier'
        ) NOT NULL DEFAULT 'fondateur'");
    }
};
