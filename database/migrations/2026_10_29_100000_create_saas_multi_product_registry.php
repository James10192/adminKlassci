<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables additives. Aucun tenant KLASSCIv2 existant n'est modifie :
     * les instances College restent independantes du modele Tenant historique.
     */
    public function up(): void
    {
        Schema::create('saas_organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country_code', 2)->nullable();
            $table->timestamps();
        });

        Schema::create('saas_establishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('saas_organizations')->restrictOnDelete();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->string('contact_email')->nullable();
            $table->timestamps();
        });

        Schema::create('saas_products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('adapter_key', 64);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('saas_product_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id')->constrained('saas_establishments')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('saas_products')->restrictOnDelete();
            // Optionnel : la cle historique de la base de controle KLASSCIv2.
            // Les College ne possedent pas de ligne dans tenants.
            $table->foreignId('legacy_tenant_id')->nullable()->unique()->constrained('tenants')->nullOnDelete();
            $table->string('external_tenant_code', 100);
            $table->string('base_url', 2048)->nullable();
            $table->string('status', 32)->default('registered');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Un meme slug peut exister dans 2 produits, mais ne doit pas etre
            // duplique pour le MEME produit ; evite l'usurpation cross-product.
            $table->unique(['product_id', 'external_tenant_code'], 'saas_product_instance_external_unique');
            $table->unique(['establishment_id', 'product_id'], 'saas_establishment_product_unique');
            $table->index(['status', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_product_instances');
        Schema::dropIfExists('saas_products');
        Schema::dropIfExists('saas_establishments');
        Schema::dropIfExists('saas_organizations');
    }
};
