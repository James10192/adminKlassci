<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_error_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('request_id', 64);
            $table->string('fingerprint', 64);
            $table->string('route_name', 160)->nullable();
            $table->string('exception_class', 160);
            $table->string('category', 24);
            $table->string('method', 8);
            $table->unsignedSmallInteger('http_status');
            $table->string('sqlstate', 5)->nullable();
            $table->unsignedSmallInteger('db_errno')->nullable();
            $table->string('status', 20)->default('new');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'request_id']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['fingerprint', 'occurred_at']);
            $table->index(['status', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_error_events');
    }
};
