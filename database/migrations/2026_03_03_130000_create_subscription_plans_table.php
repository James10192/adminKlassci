<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('monthly_fee')->default(0)->comment('En FCFA');
            $table->integer('max_users')->default(5);
            $table->integer('max_staff')->default(5);
            $table->integer('max_students')->default(50);
            $table->integer('max_inscriptions_per_year')->default(50);
            $table->integer('max_storage_mb')->default(512);
            $table->json('features')->nullable()->comment('Fonctionnalités incluses dans le plan');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed des 4 plans existants
        DB::table('subscription_plans')->insert([
            [
                'name'                       => 'Free',
                'slug'                       => 'free',
                'description'               => 'Plan gratuit pour tester la plateforme.',
                'monthly_fee'               => 0,
                'max_users'                 => 5,
                'max_staff'                 => 5,
                'max_students'              => 50,
                'max_inscriptions_per_year' => 50,
                'max_storage_mb'            => 512,
                'features'                  => json_encode(['inscriptions', 'notes', 'bulletins']),
                'is_active'                 => true,
                'sort_order'                => 1,
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
            [
                'name'                       => 'Essentiel',
                'slug'                       => 'essentiel',
                'description'               => 'Plan essentiel pour les petits établissements.',
                'monthly_fee'               => 100000,
                'max_users'                 => 20,
                'max_staff'                 => 20,
                'max_students'              => 700,
                'max_inscriptions_per_year' => 700,
                'max_storage_mb'            => 2048,
                'features'                  => json_encode(['inscriptions', 'notes', 'bulletins', 'paiements', 'notifications']),
                'is_active'                 => true,
                'sort_order'                => 2,
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
            [
                'name'                       => 'Professional',
                'slug'                       => 'professional',
                'description'               => 'Plan professionnel pour les établissements en croissance.',
                'monthly_fee'               => 200000,
                'max_users'                 => 30,
                'max_staff'                 => 30,
                'max_students'              => 3000,
                'max_inscriptions_per_year' => 3000,
                'max_storage_mb'            => 5120,
                'features'                  => json_encode(['inscriptions', 'notes', 'bulletins', 'paiements', 'notifications', 'api', 'exports', 'emploi_temps']),
                'is_active'                 => true,
                'sort_order'                => 3,
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
            [
                'name'                       => 'Elite',
                'slug'                       => 'elite',
                'description'               => 'Plan élite sans limites pour les grands établissements.',
                'monthly_fee'               => 400000,
                'max_users'                 => 999999,
                'max_staff'                 => 999999,
                'max_students'              => 999999,
                'max_inscriptions_per_year' => 999999,
                'max_storage_mb'            => 20480,
                'features'                  => json_encode(['inscriptions', 'notes', 'bulletins', 'paiements', 'notifications', 'api', 'exports', 'emploi_temps', 'chatbot', 'support_prioritaire']),
                'is_active'                 => true,
                'sort_order'                => 4,
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
