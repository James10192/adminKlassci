<?php

namespace App\Domain\Care\Acces\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un identifiant qu'une instance presente au Master pour KLASSCI Care.
 *
 * Le jeton complet a la forme `kc_<key_id>_<secret>`. Seul `key_id` est
 * stocke en clair, pour retrouver la ligne sans parcourir la table ; le
 * secret n'existe qu'en empreinte. Le jeton n'est donc lisible qu'une fois,
 * a l'emission.
 */
class TenantApiCredential extends Model
{
    public const PREFIXE = 'kc_';

    /** Les portees connues. Une portee absente de cette liste est refusee a l'emission. */
    public const PORTEES = [
        'support:create',
        'support:read',
        'support:update',
        'telemetry:send',
        'health:read',
    ];

    protected $table = 'tenant_api_credentials';

    protected $fillable = ['tenant_id', 'key_id', 'secret_hash', 'scopes', 'label', 'expires_at'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Emet un identifiant et rend le jeton en clair — la seule fois ou il existe.
     *
     * @param  list<string>  $scopes
     * @return array{0: self, 1: string}
     */
    public static function emettre(Tenant $tenant, array $scopes, ?string $label = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $inconnues = array_diff($scopes, self::PORTEES);
        if ($scopes === [] || $inconnues !== []) {
            throw new \InvalidArgumentException('Portées invalides : '.implode(', ', $inconnues ?: ['(aucune)']));
        }

        $keyId = Str::lower(Str::random(12));
        $secret = Str::random(40);

        $credential = self::create([
            'tenant_id' => $tenant->id,
            'key_id' => $keyId,
            'secret_hash' => hash('sha256', $secret),
            'scopes' => array_values(array_unique($scopes)),
            'label' => $label,
            'expires_at' => $expiresAt,
        ]);

        return [$credential, self::PREFIXE.$keyId.'_'.$secret];
    }

    /** Decoupe `kc_<key_id>_<secret>`. Rend null pour tout autre format. */
    public static function decouper(string $jeton): ?array
    {
        if (! preg_match('/^kc_([a-z0-9]{12})_([A-Za-z0-9]{40})$/', $jeton, $m)) {
            return null;
        }

        return ['key_id' => $m[1], 'secret' => $m[2]];
    }

    public function verifierSecret(string $secret): bool
    {
        return hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    public function estUtilisable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function accorde(string $portee): bool
    {
        return in_array($portee, $this->scopes ?? [], true);
    }

    public function revoquer(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }
}
