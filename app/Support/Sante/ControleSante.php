<?php

namespace App\Support\Sante;

/**
 * Les mots de la santé du parc, en un seul endroit.
 *
 * Ces libellés étaient recopiés dans quatre tableaux et filtres, et avaient
 * divergé : « App Errors » en anglais ici, les statuts abandonnés « warning »
 * et « critical » là — si bien qu'un contrôle critique s'affichait en gris,
 * avec son code brut « unhealthy ».
 */
final class ControleSante
{
    /** Types écrits par tenant:health-check, dans l'ordre de la sonde. */
    public const TYPES = [
        'http_status' => 'Accès web',
        'database_connection' => 'Base de données',
        'disk_space' => 'Disque',
        'ssl_certificate' => 'Certificat SSL',
        'application_errors' => "Erreurs de l'application",
        'queue_workers' => "Files d'attente",
    ];

    /** Statuts écrits par tenant:health-check. */
    public const STATUTS = [
        'healthy' => 'Sain',
        'degraded' => 'Dégradé',
        'unhealthy' => 'Critique',
    ];

    public static function libelleType(?string $type): string
    {
        return self::TYPES[$type] ?? (string) $type;
    }

    public static function libelleStatut(?string $statut): string
    {
        return self::STATUTS[$statut] ?? (string) $statut;
    }

    /** Couleur Filament : le vert, l'orange et le rouge portent un sens, rien d'autre. */
    public static function couleurStatut(?string $statut): string
    {
        return match ($statut) {
            'healthy' => 'success',
            'degraded' => 'warning',
            'unhealthy' => 'danger',
            default => 'gray',
        };
    }

    public static function iconeStatut(?string $statut): string
    {
        return match ($statut) {
            'healthy' => 'heroicon-o-check-circle',
            'degraded' => 'heroicon-o-exclamation-triangle',
            'unhealthy' => 'heroicon-o-x-circle',
            default => 'heroicon-o-question-mark-circle',
        };
    }
}
