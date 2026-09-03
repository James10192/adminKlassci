<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute la valeur 'success' à l'ENUM status de tenant_deployments.
     * La commande tenant:deploy écrit 'success' mais l'ENUM n'avait que
     * 'pending', 'in_progress', 'completed', 'failed', 'rolled_back'.
     *
     * Élargir un ENUM n'a pas de forme portable : MySQL le fait par un ALTER
     * brut, SQLite n'a pas d'ENUM du tout (Laravel le rend en varchar + une
     * contrainte CHECK, qu'on lève en repassant la colonne en chaîne libre).
     * Le garde-fou sur le pilote garde la production identique tout en
     * laissant la suite de tests tourner sur SQLite en mémoire.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tenant_deployments MODIFY COLUMN status ENUM('pending','in_progress','completed','success','failed','rolled_back') DEFAULT 'pending'");

            return;
        }

        Schema::table('tenant_deployments', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Mettre à jour les enregistrements 'success' → 'completed' avant de retirer la valeur
        DB::table('tenant_deployments')->where('status', 'success')->update(['status' => 'completed']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tenant_deployments MODIFY COLUMN status ENUM('pending','in_progress','completed','failed','rolled_back') DEFAULT 'pending'");
        }
    }
};
