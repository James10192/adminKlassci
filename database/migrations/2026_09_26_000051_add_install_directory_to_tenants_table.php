<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le dossier d'une école sur le serveur ne porte pas toujours son code :
     * ISLG vit dans « islg-rostan », Imertel dans « imertel ». Tant que ce nom
     * était deviné à partir du code, la santé, les sauvegardes et le
     * déploiement cherchaient un dossier qui n'existe pas.
     *
     * Nul veut dire « même nom que le code », ce qui reste vrai pour la
     * plupart des écoles.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Unique : deux écoles sur un même dossier se sauvegarderaient
            // et se déploieraient l'une l'autre. Plusieurs NULL restent admis.
            $table->string('install_directory', 100)->nullable()->unique()->after('subdomain');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['install_directory']);
            $table->dropColumn('install_directory');
        });
    }
};
