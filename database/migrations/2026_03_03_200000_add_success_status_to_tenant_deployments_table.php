<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Ajoute la valeur 'success' à l'ENUM status de tenant_deployments.
     * La commande tenant:deploy écrit 'success' mais l'ENUM n'avait que
     * 'pending', 'in_progress', 'completed', 'failed', 'rolled_back'.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE tenant_deployments MODIFY COLUMN status ENUM('pending','in_progress','completed','success','failed','rolled_back') DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Mettre à jour les enregistrements 'success' → 'completed' avant de retirer la valeur
        DB::statement("UPDATE tenant_deployments SET status = 'completed' WHERE status = 'success'");
        DB::statement("ALTER TABLE tenant_deployments MODIFY COLUMN status ENUM('pending','in_progress','completed','failed','rolled_back') DEFAULT 'pending'");
    }
};
