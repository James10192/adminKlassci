<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Doctrine DBAL cannot parse an existing ENUM column — Schema::table with
 * ->enum()->change() hangs or drops the column's data. Use a raw ALTER
 * statement instead on MySQL, which is what production runs.
 *
 * SQLite has no ENUM: Laravel renders it as a varchar with a CHECK
 * constraint, so widening the set means lifting that constraint. The driver
 * guard keeps production byte-identical while letting the test suite run on
 * an in-memory database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE group_members MODIFY COLUMN role ENUM(
                'fondateur',
                'directeur_general',
                'directeur_general_adjoint',
                'directeur_financier'
            ) NOT NULL DEFAULT 'fondateur'");

            return;
        }

        Schema::table('group_members', function (Blueprint $table) {
            $table->string('role')->default('fondateur')->change();
        });
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

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE group_members MODIFY COLUMN role ENUM(
                'fondateur',
                'directeur_general',
                'directeur_financier'
            ) NOT NULL DEFAULT 'fondateur'");
        }
    }
};
