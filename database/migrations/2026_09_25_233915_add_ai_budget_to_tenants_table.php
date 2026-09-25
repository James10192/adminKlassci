<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Budget mensuel d'IA de l'école, en FCFA. Null = le master ne fixe rien
            // (l'école garde son propre réglage) ; 0 = sans limite, imposé par le master.
            $table->decimal('ai_monthly_budget_fcfa', 12, 2)->nullable();
            $table->timestamp('ai_usage_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['ai_monthly_budget_fcfa', 'ai_usage_synced_at']);
        });
    }
};
