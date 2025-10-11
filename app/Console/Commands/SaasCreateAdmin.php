<?php

namespace App\Console\Commands;

use App\Models\SaasAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class SaasCreateAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'saas:create-admin
                            {--name= : Nom de l\'administrateur}
                            {--email= : Email de l\'administrateur}
                            {--password= : Mot de passe (si omis, sera généré)}
                            {--role=support : Rôle (super_admin, support, billing)}
                            {--phone= : Numéro de téléphone}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Créer un nouvel administrateur SaaS';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔧 Création d\'un nouvel administrateur SaaS');
        $this->newLine();

        // Collecter les données
        $name = $this->option('name') ?: $this->ask('Nom complet');
        $email = $this->option('email') ?: $this->ask('Email');
        $role = $this->option('role') ?: $this->choice(
            'Rôle',
            ['super_admin', 'support', 'billing'],
            1 // default: support
        );

        // Si --phone fourni, l'utiliser ; sinon demander seulement en mode interactif
        $phone = $this->option('phone');
        if ($phone === null && !$this->option('name')) {
            // Mode interactif : demander le téléphone
            $phone = $this->ask('Téléphone (optionnel)', null);
        }

        // Vérifier si l'email existe déjà
        if (SaasAdmin::where('email', $email)->exists()) {
            $this->error("❌ Un administrateur avec l'email {$email} existe déjà.");
            return 1;
        }

        // Mot de passe
        $password = $this->option('password');
        if (!$password) {
            $password = $this->secret('Mot de passe');
            $passwordConfirm = $this->secret('Confirmer le mot de passe');

            if ($password !== $passwordConfirm) {
                $this->error('❌ Les mots de passe ne correspondent pas.');
                return 1;
            }
        }

        // Valider les données
        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ], [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:saas_admins',
            'password' => 'required|min:8',
            'role' => 'required|in:super_admin,support,billing',
        ]);

        if ($validator->fails()) {
            $this->error('❌ Erreurs de validation :');
            foreach ($validator->errors()->all() as $error) {
                $this->line("  - {$error}");
            }
            return 1;
        }

        // Créer l'administrateur
        try {
            $admin = SaasAdmin::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => $role,
                'phone' => $phone,
                'is_active' => true,
            ]);

            $this->newLine();
            $this->info('✅ Administrateur créé avec succès !');
            $this->newLine();

            $this->table(
                ['Champ', 'Valeur'],
                [
                    ['ID', $admin->id],
                    ['Nom', $admin->name],
                    ['Email', $admin->email],
                    ['Rôle', $admin->role],
                    ['Téléphone', $admin->phone ?: 'N/A'],
                    ['Actif', $admin->is_active ? 'Oui' : 'Non'],
                ]
            );

            return 0;
        } catch (\Exception $e) {
            $this->error("❌ Erreur lors de la création : {$e->getMessage()}");
            return 1;
        }
    }
}
