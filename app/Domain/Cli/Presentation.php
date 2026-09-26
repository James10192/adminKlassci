<?php

namespace App\Domain\Cli;

use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Models\TenantDeployment;
use App\Models\TenantHealthCheck;

/**
 * Ce que le CLI reçoit de chaque objet de la base maître.
 *
 * Une liste blanche : aucun identifiant de base, aucun jeton, aucun chemin
 * d'archive n'en sort. Ajouter un champ ici est un choix, pas un oubli.
 */
final class Presentation
{
    /** @return array<string, mixed> */
    public static function tenant(Tenant $t, bool $detail = false): array
    {
        $base = [
            'code' => $t->code,
            'nom' => $t->name,
            'url' => $t->full_url,
            'statut' => $t->status,
            'plan' => $t->plan,
            'fin_abonnement' => $t->subscription_end_date?->toDateString(),
            'jours_restants' => $t->daysRemaining(),
            'branche' => $t->git_branch,
            'dernier_deploiement' => $t->last_deployed_at?->toIso8601String(),
        ];

        if (! $detail) {
            return $base;
        }

        return $base + [
            'sous_domaine' => $t->subdomain,
            'dossier' => $t->getAttribute('install_directory') ?: $t->code,
            'groupe' => $t->group?->name,
            'commit' => $t->git_commit_hash,
            'jeton_api_emis' => filled($t->api_token),
            'usage' => [
                'utilisateurs' => [$t->current_users, $t->max_users],
                'personnel' => [$t->current_staff, $t->max_staff],
                'etudiants' => [$t->current_students, $t->max_students],
                'inscriptions_annee' => [$t->current_inscriptions_per_year, $t->max_inscriptions_per_year],
                'stockage_mo' => [$t->current_storage_mb, $t->max_storage_mb],
            ],
            'stats_mesurees_le' => $t->stats_measured_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function deploiement(TenantDeployment $d, bool $etapes = false): array
    {
        $base = [
            'id' => $d->id,
            'ecole' => $d->tenant?->code,
            'branche' => $d->git_branch,
            'commit' => $d->git_commit_hash ? substr($d->git_commit_hash, 0, 10) : null,
            'statut' => $d->status,
            'debut' => $d->started_at?->toIso8601String(),
            'fin' => $d->completed_at?->toIso8601String(),
            'duree_s' => $d->duration_seconds,
            'par' => $d->deployedBy?->name,
            'erreur' => self::caviarder($d->error_message),
        ];

        if (! $etapes) {
            return $base;
        }

        return $base + [
            'etapes' => collect($d->deployment_log ?? [])->map(fn ($e) => [
                'etape' => $e['step'] ?? '?',
                'statut' => $e['status'] ?? '?',
                'duree_ms' => $e['duration_ms'] ?? null,
                'sortie' => isset($e['output']) ? mb_substr(self::caviarder((string) $e['output']), 0, 2000) : null,
            ])->values()->all(),
        ];
    }

    /**
     * Le dépôt est privé : l'URL du dépôt sur le serveur peut porter un jeton
     * (https://jeton@github.com/…), et git le recopie dans ses messages
     * d'erreur. Le rôle billing lit les déploiements, pas ce jeton.
     */
    private static function caviarder(?string $texte): ?string
    {
        return $texte === null ? null : preg_replace('#(https?://)[^@\s/]+@#i', '$1***@', $texte);
    }

    /** @return array<string, mixed> */
    public static function releve(TenantHealthCheck $r): array
    {
        return [
            'ecole' => $r->tenant?->code,
            'controle' => $r->check_type,
            'statut' => $r->status,
            'details' => $r->details,
            'temps_ms' => $r->response_time_ms,
            'le' => ($r->checked_at ?? $r->created_at)?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function sauvegarde(TenantBackup $s): array
    {
        return [
            'id' => $s->id,
            'ecole' => $s->tenant?->code,
            'type' => $s->type,
            'statut' => $s->status,
            'taille_octets' => $s->size_bytes,
            'chiffree' => (bool) $s->est_chiffre,
            'scellee' => (bool) $s->est_authentifie,
            'hors_site' => (bool) $s->copie_hors_site,
            'erreur' => $s->error_message,
            'le' => $s->created_at?->toIso8601String(),
            'expire_le' => $s->expires_at?->toIso8601String(),
        ];
    }
}
