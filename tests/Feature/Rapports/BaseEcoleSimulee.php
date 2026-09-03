<?php

namespace Tests\Feature\Rapports;

use App\Models\Group;
use App\Models\Tenant;
use App\Services\TenantConnectionManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une vraie base d'école, en mémoire.
 *
 * Les tests existants du portail court-circuitent les bases d'établissement :
 * ils injectent des indicateurs déjà calculés. C'est légitime pour vérifier une
 * agrégation, mais cela laisse le SQL entièrement non testé — or dans un état
 * de DÉTAIL, le SQL EST la fonctionnalité. Une jointure fautive, un nom de
 * colonne inventé, un `deleted_at` oublié : rien de tout cela n'échouerait
 * dans un test qui ne touche pas de base.
 *
 * Le risque est réel et documenté ici même : `esbtp_paiements` a été créée deux
 * fois dans l'historique de KLASSCIv2, une fois avec `status` et une fois avec
 * `statut`. Seule la première s'applique — la seconde est gardée par un
 * `hasTable`. Un rapport écrit sur la mauvaise colonne se compilerait, passerait
 * tous les tests unitaires, et ne renverrait jamais une ligne en production.
 *
 * Ce double monte donc le schéma réel en SQLite et le remplit. Le nom de
 * connexion suit exactement celui que produit `TenantConnectionManager`, et la
 * fermeture est neutralisée : purger la connexion détruirait une base tenue en
 * mémoire.
 */
class BaseEcoleSimulee extends TenantConnectionManager
{
    public function createConnection(Tenant $tenant): string
    {
        return self::nom($tenant->code);
    }

    public function closeConnection(string $connectionName): void
    {
        // Une base « :memory: » meurt avec sa connexion. Ne rien purger.
    }

    public static function nom(string $code): string
    {
        return "tenant_{$code}";
    }

    /**
     * Une école du groupe, avec ou sans base qui répond.
     *
     * Vit ici plutôt que dans un fichier de test : une fonction d'aide déclarée
     * dans un fichier et appelée depuis trois autres passe tant qu'on lance la
     * suite entière, et disparaît dès qu'on lance un seul fichier — c'est-à-dire
     * exactement quand on débogue.
     */
    public static function ecole(Group $groupe, string $code, bool $repond = true): Tenant
    {
        $etablissement = Tenant::create([
            'group_id' => $groupe->id,
            'code' => $code,
            'name' => mb_strtoupper($code),
            'subdomain' => $code,
            'database_name' => "klassci_{$code}",
            'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y'],
            'git_branch' => 'main',
            'status' => 'active',
            'plan' => 'elite',
        ]);

        if ($repond) {
            self::remplir(self::monter($code));
        }

        return $etablissement;
    }

    /**
     * Monte le schéma d'une école et l'ouvre pour écriture.
     *
     * Ne crée que les tables que les états de détail interrogent, avec les
     * colonnes réellement présentes dans KLASSCIv2 — pas une approximation.
     */
    public static function monter(string $code): string
    {
        $nom = self::nom($code);

        Config::set("database.connections.{$nom}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $schema = Schema::connection($nom);

        $schema->create('esbtp_annee_universitaires', function ($t): void {
            $t->id();
            $t->string('name');
            $t->boolean('is_current')->default(false);
        });

        $schema->create('esbtp_filieres', function ($t): void {
            $t->id();
            $t->string('name');
        });

        $schema->create('esbtp_niveau_etudes', function ($t): void {
            $t->id();
            $t->string('name');
        });

        $schema->create('esbtp_classes', function ($t): void {
            $t->id();
            $t->string('name');
        });

        $schema->create('esbtp_etudiants', function ($t): void {
            $t->id();
            $t->string('matricule')->nullable();
            $t->string('nom')->nullable();
            $t->string('prenoms')->nullable();
            $t->string('sexe')->nullable();
        });

        $schema->create('esbtp_inscriptions', function ($t): void {
            $t->id();
            $t->unsignedBigInteger('etudiant_id');
            $t->unsignedBigInteger('annee_universitaire_id');
            $t->unsignedBigInteger('filiere_id')->nullable();
            $t->unsignedBigInteger('niveau_id')->nullable();
            $t->unsignedBigInteger('classe_id')->nullable();
            $t->date('date_inscription')->nullable();
            $t->string('type_inscription')->nullable();
            $t->string('status')->default('active');
            $t->string('affectation_status')->nullable();
            $t->string('workflow_step')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $schema->create('esbtp_paiements', function ($t): void {
            $t->id();
            $t->unsignedBigInteger('etudiant_id');
            $t->unsignedBigInteger('inscription_id')->nullable();
            $t->unsignedBigInteger('annee_universitaire_id');
            $t->string('type_paiement')->nullable();
            $t->decimal('montant', 12, 2)->default(0);
            $t->date('date_paiement')->nullable();
            $t->string('mode_paiement')->nullable();
            $t->string('reference_paiement')->nullable();
            $t->string('numero_recu')->nullable();
            $t->string('tranche')->nullable();
            // La colonne canonique s'appelle `status`, pas `statut`.
            $t->string('status')->default('en_attente');
            $t->softDeletes();
        });

        $schema->create('esbtp_frais_categories', function ($t): void {
            $t->id();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_mandatory')->default(true);
            $t->decimal('default_amount', 12, 2)->default(0);
        });

        $schema->create('esbtp_frais_subscriptions', function ($t): void {
            $t->id();
            $t->unsignedBigInteger('inscription_id');
            $t->unsignedBigInteger('frais_category_id');
            $t->decimal('amount', 12, 2)->default(0);
            $t->boolean('is_active')->default(true);
        });

        $schema->create('esbtp_frais_configurations', function ($t): void {
            $t->id();
            $t->unsignedBigInteger('frais_category_id');
            $t->unsignedBigInteger('filiere_id')->nullable();
            $t->unsignedBigInteger('niveau_id')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->decimal('amount_affecte', 12, 2)->nullable();
            $t->decimal('amount_reaffecte', 12, 2)->nullable();
            $t->decimal('amount_non_affecte', 12, 2)->nullable();
            $t->boolean('is_active')->default(true);
        });

        return $nom;
    }

    /** Une école qui a répondu, avec une année ouverte et de quoi produire des lignes. */
    public static function remplir(string $nom, array $options = []): void
    {
        $c = DB::connection($nom);

        $c->table('esbtp_annee_universitaires')->insert(['id' => 1, 'name' => '2025-2026', 'is_current' => true]);
        $c->table('esbtp_filieres')->insert(['id' => 1, 'name' => 'Génie civil']);
        $c->table('esbtp_niveau_etudes')->insert(['id' => 1, 'name' => 'Licence 1']);
        $c->table('esbtp_classes')->insert(['id' => 1, 'name' => 'L1 GC A']);

        $c->table('esbtp_etudiants')->insert([
            ['id' => 1, 'matricule' => 'M001', 'nom' => 'KOUAME', 'prenoms' => 'Awa', 'sexe' => 'F'],
            ['id' => 2, 'matricule' => 'M002', 'nom' => 'BAMBA', 'prenoms' => 'Ibrahim', 'sexe' => 'M'],
        ]);

        $c->table('esbtp_inscriptions')->insert([
            ['id' => 1, 'etudiant_id' => 1, 'annee_universitaire_id' => 1, 'filiere_id' => 1, 'niveau_id' => 1,
                'classe_id' => 1, 'date_inscription' => '2025-10-01', 'type_inscription' => 'première_inscription',
                'status' => 'active', 'affectation_status' => 'affecté'],
            ['id' => 2, 'etudiant_id' => 2, 'annee_universitaire_id' => 1, 'filiere_id' => 1, 'niveau_id' => 1,
                'classe_id' => 1, 'date_inscription' => '2025-10-02', 'type_inscription' => 'réinscription',
                'status' => 'active', 'affectation_status' => 'affecté'],
        ]);

        // Un barème unique de 500 000, obligatoire, sans souscription individuelle.
        $c->table('esbtp_frais_categories')->insert(['id' => 1, 'is_active' => true, 'is_mandatory' => true, 'default_amount' => 500000]);
        $c->table('esbtp_frais_configurations')->insert([
            'id' => 1, 'frais_category_id' => 1, 'filiere_id' => 1, 'niveau_id' => 1,
            'amount' => 500000, 'amount_affecte' => 500000, 'is_active' => true,
        ]);

        // Toutes les lignes portent EXACTEMENT les mêmes clés : une insertion
        // en masse l'exige, et un jeu de données bancal ferait échouer le test
        // pour une raison qui n'a rien à voir avec ce qu'il vérifie.
        $gabarit = [
            'etudiant_id' => 1, 'inscription_id' => 1, 'annee_universitaire_id' => 1,
            'type_paiement' => 'Scolarité', 'montant' => 0, 'date_paiement' => null,
            'mode_paiement' => null, 'reference_paiement' => null, 'numero_recu' => null,
            'tranche' => null, 'status' => 'en_attente', 'deleted_at' => null,
        ];

        $paiements = $options['paiements'] ?? [
            ['id' => 1, 'etudiant_id' => 1, 'inscription_id' => 1, 'montant' => 200000,
                'date_paiement' => '2026-09-02', 'mode_paiement' => 'Espèces',
                'reference_paiement' => 'REF-1', 'status' => 'validé'],

            // Sans référence : le rapport doit retomber sur le numéro de reçu.
            ['id' => 2, 'etudiant_id' => 2, 'inscription_id' => 2, 'montant' => 500000,
                'date_paiement' => '2026-09-03', 'mode_paiement' => 'Wave',
                'numero_recu' => 'RECU-9', 'status' => 'validé'],

            // En attente : ne doit PAS compter comme encaissé.
            ['id' => 3, 'etudiant_id' => 1, 'inscription_id' => 1, 'montant' => 300000,
                'date_paiement' => '2026-09-04', 'mode_paiement' => 'Espèces',
                'reference_paiement' => 'REF-3', 'status' => 'en_attente'],

            // Supprimé : ne doit apparaître nulle part.
            ['id' => 4, 'etudiant_id' => 2, 'inscription_id' => 2, 'montant' => 999999,
                'date_paiement' => '2026-09-05', 'mode_paiement' => 'Espèces',
                'reference_paiement' => 'ANNULE', 'status' => 'validé',
                'deleted_at' => '2026-09-06 10:00:00'],
        ];

        $paiements = array_map(
            static fn (array $p): array => array_merge($gabarit, $p),
            $paiements,
        );

        $c->table('esbtp_paiements')->insert($paiements);
    }
}
