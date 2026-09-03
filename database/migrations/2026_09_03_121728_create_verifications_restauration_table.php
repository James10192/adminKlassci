<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le registre des restaurations réellement essayées.
 *
 * Une sauvegarde jamais restaurée n'est pas une sauvegarde : c'est un fichier
 * dont on espère qu'il contient quelque chose. Tant qu'on ne l'a pas relu, on
 * ne sait ni si la clé est la bonne, ni si l'archive est complète, ni si le
 * dump contient les tables qu'on croit.
 *
 * Cette table existe pour qu'une date puisse être citée. « Nos sauvegardes
 * sont testées » sans date ne veut rien dire ; « dernière restauration
 * vérifiée le 3 septembre, 187 tables, 2 148 étudiants relus » se vérifie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifications_restauration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_backup_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('verdict', ['reussie', 'echouee']);
            $table->string('raison')->nullable();

            // Ce qu'on a relu. Une restauration qui aboutit sur une base vide
            // est une restauration échouée qui s'ignore.
            $table->unsignedInteger('tables_restaurees')->default(0);
            $table->json('lignes_par_table')->nullable();

            $table->unsignedInteger('duree_secondes')->nullable();
            $table->timestamp('verifiee_at');
            $table->timestamps();

            $table->index(['tenant_id', 'verifiee_at']);
            $table->index('verdict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications_restauration');
    }
};
