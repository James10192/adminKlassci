<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Un groupe complet, pour REGARDER le portail en local.
 *
 * Le portail groupe ne se vérifie pas en lisant du Blade : il faut l'ouvrir.
 * Or l'ouvrir demande un groupe, des établissements et un membre pour s'y
 * connecter — sans quoi chaque écran affiche son état vide. Ce jeu de données
 * existe pour ça, et pour rien d'autre.
 *
 *     php artisan db:seed --class=GroupPortalDemoSeeder
 *     # puis : dg@rostan.test / demo1234
 *
 * Les établissements pointent vers des bases qui n'existent pas : c'est
 * voulu. Les agrégations qui interrogent les bases des écoles retournent donc
 * leur état « injoignable », ce qui est précisément le cas à vérifier — un
 * portail qui n'est beau que quand tout répond ne sert à rien.
 */
class GroupPortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $groupe = Group::updateOrCreate(
            ['code' => 'rostan-demo'],
            [
                'name' => 'Groupe ROSTAN',
                'description' => 'Jeu de démonstration local du portail groupe.',
                'founded_year' => 2011,
                'address' => 'Cocody, Abidjan',
                'phone' => '+225 07 07 12 34 56',
                'email' => 'contact@rostan.test',
                'status' => 'active',
            ]
        );

        // Le dernier champ dit depuis quand `tenant:update-stats` est passe.
        // Trois des quatre ecoles ont eu des nouvelles recemment, la quatrieme
        // jamais — de quoi verifier que l'infobulle des cartes dit bien depuis
        // quand la maitresse est sans nouvelles, sans jamais dater les chiffres
        // eux-memes, qui ne sont pas mesures.
        $etablissements = [
            ['islg-rostan', 'ISLG Rostan', 'elite', 400000, 620, 800, 34, 40, 12, '+18 months', '-47 minutes'],
            ['rostan-yopougon', 'Rostan Yopougon', 'professional', 200000, 2140, 3000, 28, 30, 21, '+3 months', '-2 hours'],
            ['rostan-bouake', 'Rostan Bouaké', 'essentiel', 100000, 690, 700, 19, 20, 9, '+11 days', '-3 days'],
            ['rostan-daloa', 'Rostan Daloa', 'free', 0, 44, 50, 4, 5, 2, null, null],
        ];

        foreach ($etablissements as [$code, $nom, $plan, $frais, $etudiants, $maxEtudiants, $users, $maxUsers, $staff, $fin, $releve]) {
            Tenant::updateOrCreate(
                ['code' => $code],
                [
                    'group_id' => $groupe->id,
                    'name' => $nom,
                    'subdomain' => $code,
                    'database_name' => 'klassci_'.str_replace('-', '_', $code),
                    // Volontairement injoignables : voir le docblock.
                    'database_credentials' => [
                        'host' => '127.0.0.1',
                        'port' => 3306,
                        'username' => 'demo',
                        'password' => 'demo',
                    ],
                    'git_branch' => $code,
                    'status' => 'active',
                    'plan' => $plan,
                    'monthly_fee' => $frais,
                    'subscription_start_date' => now()->subYear(),
                    'subscription_end_date' => $fin ? now()->modify($fin) : null,
                    'max_users' => $maxUsers,
                    'max_staff' => 40,
                    'max_students' => $maxEtudiants,
                    'max_inscriptions_per_year' => $maxEtudiants,
                    'max_storage_mb' => 5120,
                    'current_users' => $users,
                    'current_staff' => $staff,
                    'current_students' => $etudiants,
                    'current_inscriptions_per_year' => $etudiants,
                    'current_storage_mb' => 1240,
                    'stats_measured_at' => $releve ? now()->modify($releve) : null,
                    'admin_name' => 'Direction '.$nom,
                    'admin_email' => 'direction@'.$code.'.test',
                ]
            );
        }

        foreach ([
            ['dg@rostan.test', 'Marcel Djedje-li', 'directeur_general'],
            ['dga@rostan.test', 'Issouf Ouédraogo', 'directeur_general_adjoint'],
        ] as [$email, $nom, $role]) {
            GroupMember::updateOrCreate(
                ['email' => $email],
                [
                    'group_id' => $groupe->id,
                    'name' => $nom,
                    'password' => Hash::make('demo1234'),
                    'password_changed_at' => now(),
                    'role' => $role,
                    'is_active' => true,
                ]
            );
        }

        $this->command?->info("Groupe « {$groupe->name} » prêt — dg@rostan.test / demo1234");
    }
}
