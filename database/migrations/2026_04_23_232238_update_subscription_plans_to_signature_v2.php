<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aligne la grille tarifaire codée avec les slides marketing "Formule
 * Signature" (KLASSCI = grandes écoles et universités uniquement ;
 * collèges → klassci-college, autre repo).
 *
 * Décisions produit (session 2026-04-23) :
 *   - 3 tiers seulement (pas de Starter — hors-scope supérieur)
 *   - Essentiel : 988k 1ère année / 700k récurrent (500 élèves max)
 *   - PRO       : 1.3M 1ère année / 1.15M récurrent (2500 élèves max)
 *   - ELITE     : 6M 1ère année / 4.8M récurrent (illimité)
 *   - Monthly +15% cohérent partout (fixe l'incohérence des slides où
 *     Essentiel avait 0% premium et ELITE 20%)
 *   - WhatsApp dans TOUS les tiers (3 / 5 / 6 types)
 *   - SLA chiffrés : J+1 / 4h / 2h
 *   - Nouvelles features dans tous les tiers payants (plus de gating)
 *   - Plan "Free" supprimé — remplacé par free trial 3 mois (feature
 *     applicative, pas un tier persistant)
 *
 * Grandfathering des tenants existants : géré séparément via colonne
 * `grandfathered_until_date` sur `tenants` (à ajouter dans une migration
 * ultérieure). ESBTP Abidjan (Pro) → 24 mois, ESBTP Yakro / ISLG Rostan
 * (mid-tier) → 18 mois, Hetec (SMB) → 12 mois, Presentation (démo) → N/A.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_plans', 'first_year_fee')) {
                $table->unsignedBigInteger('first_year_fee')->default(0)->after('monthly_fee')
                    ->comment('Prix 1ère année (setup + abonnement) en FCFA');
            }
            if (! Schema::hasColumn('subscription_plans', 'annual_fee')) {
                $table->unsignedBigInteger('annual_fee')->default(0)->after('first_year_fee')
                    ->comment('Prix annuel récurrent (à partir de la 2ème année) en FCFA');
            }
            if (! Schema::hasColumn('subscription_plans', 'whatsapp_types')) {
                $table->unsignedSmallInteger('whatsapp_types')->default(0)->after('annual_fee')
                    ->comment('Nombre de types de notifications WhatsApp inclus (0 = pas de WhatsApp)');
            }
            if (! Schema::hasColumn('subscription_plans', 'sla_response_hours')) {
                $table->unsignedSmallInteger('sla_response_hours')->nullable()->after('whatsapp_types')
                    ->comment('SLA réponse support en heures (null = best effort)');
            }
            if (! Schema::hasColumn('subscription_plans', 'target_segment')) {
                $table->string('target_segment', 100)->nullable()->after('description')
                    ->comment('Segment cible (ex: "Supérieur émergent", "Université/école établie")');
            }
        });

        DB::table('subscription_plans')
            ->where('slug', 'free')
            ->update([
                'is_active' => false,
                'description' => 'DÉSACTIVÉ — remplacé par le free trial 3 mois (feature applicative, pas un tier commercial).',
                'updated_at' => now(),
            ]);

        $plans = [
            [
                'slug' => 'essentiel',
                'name' => 'Essentiel',
                'description' => 'Pour les grandes écoles et petites universités qui démarrent leur digitalisation. 500 apprenants max, 30 professeurs, accompagnement mail + WhatsApp.',
                'target_segment' => 'Supérieur émergent (500 élèves max)',
                'monthly_fee' => 67_083,
                'first_year_fee' => 988_000,
                'annual_fee' => 700_000,
                'max_users' => 5,
                'max_staff' => 34,
                'max_students' => 500,
                'max_inscriptions_per_year' => 500,
                'max_storage_mb' => 5_120,
                'whatsapp_types' => 3,
                'sla_response_hours' => 24,
                'features' => json_encode([
                    'inscriptions', 'notes', 'bulletins', 'paiements', 'emploi_temps', 'presences',
                    'notifications_mail', 'whatsapp_3_types', 'personnalisation_design',
                    'maintenance_annuelle', 'mise_a_jour_ergonomie', 'formation_prise_en_main',
                    'nouvelles_features',
                ]),
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'slug' => 'pro',
                'name' => 'PRO',
                'description' => 'Pour les universités et grandes écoles établies. 2500 apprenants, 50 professeurs, 10 comptes éducateurs, plateforme cours en ligne, SLA 4h.',
                'target_segment' => 'Université / école supérieure établie (2500 élèves)',
                'monthly_fee' => 110_208,
                'first_year_fee' => 1_300_000,
                'annual_fee' => 1_150_000,
                'max_users' => 5,
                'max_staff' => 60,
                'max_students' => 2_500,
                'max_inscriptions_per_year' => 2_500,
                'max_storage_mb' => 20_480,
                'whatsapp_types' => 5,
                'sla_response_hours' => 4,
                'features' => json_encode([
                    'inscriptions', 'notes', 'bulletins', 'paiements', 'emploi_temps', 'presences',
                    'notifications_mail', 'whatsapp_5_types', 'plateforme_cours_en_ligne',
                    'personnalisation_design', 'personnalisation_avancee',
                    'maintenance_annuelle', 'mise_a_jour_ergonomie', 'formation_prise_en_main',
                    'api', 'exports', 'nouvelles_features',
                ]),
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'slug' => 'elite',
                'name' => 'ELITE',
                'description' => 'Pour les groupes universitaires et réseaux d\'écoles supérieures. Illimité sur tous les axes, WhatsApp 6 types + 5000 app, SLA 2h 6j/7, CSM dédié, personnalisation continue.',
                'target_segment' => 'Groupe universitaire / enseignement supérieur premium',
                'monthly_fee' => 460_000,
                'first_year_fee' => 6_000_000,
                'annual_fee' => 4_800_000,
                'max_users' => 999_999,
                'max_staff' => 999_999,
                'max_students' => 999_999,
                'max_inscriptions_per_year' => 999_999,
                'max_storage_mb' => 102_400,
                'whatsapp_types' => 6,
                'sla_response_hours' => 2,
                'features' => json_encode([
                    'inscriptions', 'notes', 'bulletins', 'paiements', 'emploi_temps', 'presences',
                    'notifications_mail', 'whatsapp_6_types', 'whatsapp_5000_app',
                    'plateforme_cours_en_ligne', 'chatbot',
                    'personnalisation_continue', 'maintenance_annuelle', 'mise_a_jour_ergonomie',
                    'formation_prise_en_main', 'acces_gratuit_nouvelles_features',
                    'api', 'exports', 'support_prioritaire', 'csm_dedie',
                    'nouvelles_features',
                ]),
                'sort_order' => 4,
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            $existing = DB::table('subscription_plans')->where('slug', $plan['slug'])->first();
            if ($existing) {
                DB::table('subscription_plans')
                    ->where('slug', $plan['slug'])
                    ->update(array_merge($plan, ['updated_at' => now()]));
            } else {
                DB::table('subscription_plans')->insert(array_merge($plan, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        DB::table('subscription_plans')
            ->where('slug', 'professional')
            ->update([
                'is_active' => false,
                'description' => 'DÉSACTIVÉ — renommé "PRO" (slug: pro) dans la grille Signature 2026.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('subscription_plans')
            ->where('slug', 'free')
            ->update([
                'is_active' => true,
                'description' => 'Plan gratuit pour tester la plateforme.',
                'updated_at' => now(),
            ]);

        DB::table('subscription_plans')
            ->where('slug', 'professional')
            ->update([
                'is_active' => true,
                'description' => 'Plan professionnel pour les établissements en croissance.',
                'updated_at' => now(),
            ]);

        DB::table('subscription_plans')->where('slug', 'pro')->delete();

        DB::table('subscription_plans')
            ->where('slug', 'essentiel')
            ->update([
                'monthly_fee' => 100_000,
                'max_users' => 20,
                'max_staff' => 20,
                'max_students' => 700,
                'max_inscriptions_per_year' => 700,
                'max_storage_mb' => 2_048,
                'features' => json_encode(['inscriptions', 'notes', 'bulletins', 'paiements', 'notifications']),
                'updated_at' => now(),
            ]);

        DB::table('subscription_plans')
            ->where('slug', 'elite')
            ->update([
                'monthly_fee' => 400_000,
                'max_storage_mb' => 20_480,
                'features' => json_encode(['inscriptions', 'notes', 'bulletins', 'paiements', 'notifications', 'api', 'exports', 'emploi_temps', 'chatbot', 'support_prioritaire']),
                'updated_at' => now(),
            ]);

        Schema::table('subscription_plans', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_plans', 'target_segment')) {
                $table->dropColumn('target_segment');
            }
            if (Schema::hasColumn('subscription_plans', 'sla_response_hours')) {
                $table->dropColumn('sla_response_hours');
            }
            if (Schema::hasColumn('subscription_plans', 'whatsapp_types')) {
                $table->dropColumn('whatsapp_types');
            }
            if (Schema::hasColumn('subscription_plans', 'annual_fee')) {
                $table->dropColumn('annual_fee');
            }
            if (Schema::hasColumn('subscription_plans', 'first_year_fee')) {
                $table->dropColumn('first_year_fee');
            }
        });
    }
};
