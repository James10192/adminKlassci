<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('subscription_plan_id')
                ->nullable()
                ->after('plan')
                ->constrained('subscription_plans')
                ->nullOnDelete();
        });

        // Synchroniser les tenants existants avec le bon plan en BDD
        $planMap = DB::table('subscription_plans')->pluck('id', 'slug');

        DB::table('tenants')->get()->each(function ($tenant) use ($planMap) {
            if (isset($planMap[$tenant->plan])) {
                DB::table('tenants')
                    ->where('id', $tenant->id)
                    ->update(['subscription_plan_id' => $planMap[$tenant->plan]]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['subscription_plan_id']);
            $table->dropColumn('subscription_plan_id');
        });
    }
};
