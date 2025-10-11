<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('api_token', 80)->unique()->nullable()->after('metadata')
                  ->comment('API token for accessing Master API from tenant');
            $table->timestamp('api_token_created_at')->nullable()->after('api_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['api_token', 'api_token_created_at']);
        });
    }
};
