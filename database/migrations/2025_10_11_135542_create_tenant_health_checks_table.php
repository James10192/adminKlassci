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
        Schema::create('tenant_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->enum('check_type', ['http_status', 'database_connection', 'disk_space', 'ssl_certificate', 'application_errors', 'queue_workers']);
            $table->enum('status', ['healthy', 'degraded', 'unhealthy']);
            $table->integer('response_time_ms')->nullable();
            $table->text('details')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('checked_at')->useCurrent();
            $table->timestamps();
            $table->index('tenant_id');
            $table->index('check_type');
            $table->index(['tenant_id', 'check_type', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_health_checks');
    }
};
