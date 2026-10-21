<?php

namespace App\Services\Parc;

/**
 * Agrège les contrôles de disponibilité d'un établissement.
 *
 * L'absence de mesure et un statut inconnu ne sont jamais un succès.
 * Une mesure dégradée/critique connue reste visible même si une autre
 * sonde n'a pas rendu de relevé récent.
 */
final class EtatSanteDetailResolver
{
    /**
     * @param array<int, string|null> $statuts Fraîcheur déjà contrôlée par l'appelant.
     * @return 'healthy'|'degraded'|'unhealthy'|'unknown'
     */
    public function resoudre(array $statuts): string
    {
        if (in_array('unhealthy', $statuts, true)) {
            return 'unhealthy';
        }

        if (in_array('degraded', $statuts, true)) {
            return 'degraded';
        }

        if ($statuts === [] || count(array_filter($statuts, fn ($s) => $s === 'healthy')) !== count($statuts)) {
            return 'unknown';
        }

        return 'healthy';
    }
}
