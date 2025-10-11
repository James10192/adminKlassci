<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use Carbon\Carbon;

class PresentationTenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Vérifier si le tenant existe déjà
        $existingTenant = Tenant::where('code', 'presentation')->first();

        if ($existingTenant) {
            $this->command->info('Le tenant "presentation" existe déjà.');
            return;
        }

        // Créer le tenant presentation
        $tenant = Tenant::create([
            // Informations générales
            'code' => 'presentation',
            'name' => 'Test Présentation',
            'subdomain' => 'presentation',

            // Configuration de base de données (production)
            'database_name' => 'c2569688c_test_klassci',
            'database_credentials' => [
                'host' => 'localhost',
                'port' => 3306,
                'username' => 'c2569688c_Marcel',
                'password' => 'LeVraiMD@123',
            ],

            // Configuration Git
            'git_branch' => 'presentation',
            'git_commit_hash' => null, // Sera rempli lors du premier déploiement
            'last_deployed_at' => null,

            // Statut et plan
            'status' => 'active',
            'plan' => 'free',
            'monthly_fee' => 0,

            // Période d'abonnement
            'subscription_start_date' => Carbon::now(),
            'subscription_end_date' => Carbon::now()->addYear(), // 1 an d'accès

            // Limites (plan Free)
            'max_users' => 5,
            'max_staff' => 5,
            'max_students' => 50,
            'max_inscriptions_per_year' => 50,
            'max_storage_mb' => 512,

            // Utilisation actuelle (sera calculé automatiquement)
            'current_users' => 0,
            'current_staff' => 0,
            'current_students' => 0,
            'current_storage_mb' => 0,

            // Contacts
            'admin_name' => 'Admin Présentation',
            'admin_email' => 'admin@presentation.klassci.com',
            'support_email' => 'support@klassci.com',
            'phone' => '+225 07 77 12 34 56',
            'address' => 'Abidjan, Côte d\'Ivoire',

            // Métadonnées
            'metadata' => [
                'type' => 'test',
                'environment' => 'local',
                'description' => 'Tenant de test pour présentation et démonstration',
                'created_by' => 'PresentationTenantSeeder',
            ],
        ]);

        $this->command->info('✅ Tenant "presentation" créé avec succès!');
        $this->command->info('   - Code: ' . $tenant->code);
        $this->command->info('   - URL: https://' . $tenant->subdomain . '.klassci.com');
        $this->command->info('   - Base de données: ' . $tenant->database_name);
        $this->command->info('   - Plan: ' . $tenant->plan);
        $this->command->info('   - Statut: ' . $tenant->status);
    }
}
