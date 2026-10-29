<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Saas\SaasCapabilities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only preflight for the active role/capability configuration on the
 * deployed master. Does not print emails, password hashes, keys or tokens.
 */
final class DiagnoseSaasPermissions extends Command
{
    protected $signature = 'saas:permissions:diagnose
        {--admin-id= : Identifiant interne du compte admin master à vérifier, facultatif}';

    protected $description = 'Diagnostic en lecture seule des permissions Groupe et du cache de configuration';

    public function handle(): int
    {
        $cached = app()->configurationIsCached();
        $this->info('Diagnostic des permissions Groupe (lecture seule)');
        $this->line('Configuration Laravel en cache : '.($cached ? 'oui' : 'non'));

        $config = config('saas.capabilities_by_role', []);
        $roles = is_array($config) ? $config : [];

        $this->table(
            ['Rôle configuré', 'Associer établissement', 'Gérer membres'],
            collect($roles)->map(function ($permissions, $role): array {
                $permissions = is_array($permissions) ? $permissions : [];

                return [
                    (string) $role,
                    in_array(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP, $permissions, true) ? 'oui' : 'non',
                    in_array(SaasCapabilities::MANAGE_GROUP_MEMBERS, $permissions, true) ? 'oui' : 'non',
                ];
            })->values()->all()
        );

        $id = $this->option('admin-id');
        if ($id !== null && $id !== '') {
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                $this->error('Identifiant interne invalide.');

                return self::FAILURE;
            }

            $user = User::query()->select(['id', 'role', 'is_active'])->find((int) $id);
            if (! $user) {
                $this->error('Compte introuvable dans saas_admins.');

                return self::FAILURE;
            }

            $this->table(['Indicateur', 'Valeur'], [
                ['ID', $user->id],
                ['Rôle effectif', $user->role],
                ['Compte actif', $user->is_active ? 'oui' : 'non'],
                ['Permission rattachement', Gate::forUser($user)->allows(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP) ? 'accordée' : 'refusée'],
                ['Permission membres', Gate::forUser($user)->allows(SaasCapabilities::MANAGE_GROUP_MEMBERS) ? 'accordée' : 'refusée'],
            ]);
        }

        $this->warn('Si un administrateur habilité a une permission absente : vérifier le code déployé et le cache de configuration Laravel.');
        $this->line('Étapes non destructives : php artisan optimize:clear ; puis php artisan saas:permissions:diagnose');
        $this->line("Ne pas modifier le rôle d'un utilisateur ou élargir les permissions simplement pour corriger l'interface.");

        return self::SUCCESS;
    }
}
