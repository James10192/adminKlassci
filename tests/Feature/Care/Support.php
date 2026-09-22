<?php

namespace Tests\Feature\Care;

use App\Domain\Care\Acces\Models\TenantApiCredential;
use App\Models\Tenant;

/** Fabriques partagees par les tests KLASSCI Care (le depot n'a pas de factories). */
final class Support
{
    public static function instance(string $code, array $attrs = []): Tenant
    {
        return Tenant::create($attrs + [
            'code' => $code,
            'name' => strtoupper($code),
            'subdomain' => $code,
            'database_name' => "klassci_{$code}",
            'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y'],
            'git_branch' => 'presentation',
            'git_commit_hash' => str_repeat('a', 40),
            'status' => 'active',
            'plan' => 'elite',
        ]);
    }

    /** @return string le jeton en clair */
    public static function jeton(Tenant $tenant, array $portees = ['support:create', 'support:read']): string
    {
        return TenantApiCredential::emettre($tenant, $portees)[1];
    }

    public static function soumission(array $surcharge = []): array
    {
        return array_replace_recursive([
            'api_version' => 1,
            'report' => ['category' => 'PROBLEME', 'description' => 'La moyenne de la classe ne s’affiche plus après validation.'],
            'reporter' => ['external_id' => 42, 'name' => 'Awa Koné', 'email' => 'awa@ecole.ci', 'roles' => ['secretaire']],
            'context' => [
                'route_name' => 'esbtp.notes.index',
                'url_path' => '/esbtp/notes',
                'module' => 'notes_evaluations',
                'entity' => ['type' => 'evaluation', 'id' => 622],
                'browser' => ['family' => 'Chrome', 'version' => '128'],
                'device' => 'mobile',
                'viewport' => '390x844',
                'request_ids' => ['01J8ZQ4Y5K3M2N1P0QRSTVWXYZ'],
            ],
        ], $surcharge);
    }
}
