<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registre additif : aucun tenant KLASSCIv2 historique n'est modifie.
     *
     * Une organisation (ex. groupe Rostan) peut regrouper des etablissements
     * DISTINCTS. Un etablissement a une seule categorie et un seul produit.
     */
    public function up(): void
    {
        Schema::create('saas_organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country_code', 2)->nullable();
            $table->timestamps();
        });

        // Plusieurs produits commerciaux peuvent partager un adaptateur :
        // Universite LMD et Grande ecole BTS utilisent le moteur KLASSCIv2.
        Schema::create('saas_products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('establishment_type', 64)->unique();
            $table->string('adapter_key', 64);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('saas_establishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('saas_organizations')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('saas_products')->restrictOnDelete();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->string('contact_email')->nullable();
            $table->timestamps();

            // Necessaire a la FK composite dans la table d'instances pour
            // interdire une instance College sur un etablissement LMD/BTS.
            $table->unique(['id', 'product_id'], 'saas_establishment_id_product_unique');
        });

        Schema::create('saas_product_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id');
            $table->foreignId('product_id')->constrained('saas_products')->restrictOnDelete();
            $table->foreign(
                ['establishment_id', 'product_id'],
                'saas_instance_establishment_product_fk'
            )->references(['id', 'product_id'])->on('saas_establishments')->restrictOnDelete();
            // Le lien historique ne concerne que KLASSCIv2 ; pas de conversion.
            $table->foreignId('legacy_tenant_id')->nullable()->unique()->constrained('tenants')->nullOnDelete();
            $table->string('external_tenant_code', 100);
            $table->string('base_url', 2048)->nullable();
            $table->string('status', 32)->default('registered');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Une instance maximum par etablissement, meme si le produit varie.
            $table->unique('establishment_id', 'saas_instance_one_per_establishment');
            $table->unique(['product_id', 'external_tenant_code'], 'saas_product_instance_external_unique');
            $table->index(['status', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_product_instances');
        Schema::dropIfExists('saas_establishments');
        Schema::dropIfExists('saas_products');
        Schema::dropIfExists('saas_organizations');
    }
};
