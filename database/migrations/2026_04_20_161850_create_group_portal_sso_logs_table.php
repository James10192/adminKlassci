<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_portal_sso_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_member_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_code', 64);
            $table->string('user_email_impersonated');
            $table->string('redirect_to')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->boolean('success')->default(true);
            $table->string('error_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('tenant_code');
            $table->index('group_member_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_portal_sso_logs');
    }
};
