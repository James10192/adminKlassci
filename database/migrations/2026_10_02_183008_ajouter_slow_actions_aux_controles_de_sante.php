<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Septième contrôle de santé : les actions lentes relevées par l'école
 * elle-même (GET /api/cli/traces/lentes).
 *
 * Même geste que pour le rôle DGA des groupes : un ALTER brut en MySQL, qui
 * ne touche aucune ligne, et la contrainte levée en SQLite, où l'énumération
 * n'est qu'un CHECK.
 */
return new class extends Migration
{
    private const AVANT = ['http_status', 'database_connection', 'disk_space', 'ssl_certificate', 'application_errors', 'queue_workers'];

    public function up(): void
    {
        $this->poser([...self::AVANT, 'slow_actions']);
    }

    public function down(): void
    {
        // Revenir en arrière avec des lignes « slow_actions » les ferait
        // devenir '' en MySQL : on les retire d'abord, elles se recalculent.
        DB::table('tenant_health_checks')->where('check_type', 'slow_actions')->delete();
        $this->poser(self::AVANT);
    }

    private function poser(array $types): void
    {
        if (DB::getDriverName() === 'mysql') {
            $liste = implode(', ', array_map(fn ($t) => "'{$t}'", $types));
            DB::statement("ALTER TABLE tenant_health_checks MODIFY COLUMN check_type ENUM({$liste}) NOT NULL");

            return;
        }

        Schema::table('tenant_health_checks', function (Blueprint $table) {
            $table->string('check_type')->change();
        });
    }
};
