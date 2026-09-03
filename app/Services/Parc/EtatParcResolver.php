<?php

namespace App\Services\Parc;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Ranger les établissements par ce qu'on SAIT de leur santé.
 *
 * Le tableau de bord comptait jusqu'ici les établissements jamais vérifiés
 * parmi les opérationnels :
 *
 *     $healthyCount = max(0, $healthyCount + $uncheckedCount);
 *
 * Une école dont le serveur est tombé la semaine dernière, et sur laquelle la
 * sonde n'est jamais passée, s'affichait donc en vert. Un écran de supervision
 * qui rassure sur ce qu'il n'a pas mesuré est pire qu'un écran vide : il
 * enlève la raison d'aller voir.
 *
 * D'où quatre états et non trois. « Sans relevé » n'est ni bon ni mauvais,
 * c'est l'aveu qu'on ne sait pas — et c'est une information à part entière,
 * parce qu'elle désigne exactement les établissements à aller vérifier.
 *
 * Un relevé vieilli bascule dans le même état : reconduire indéfiniment un
 * verdict de la semaine dernière reviendrait au même mensonge, en plus discret.
 */
class EtatParcResolver
{
    public const OPERATIONNEL = 'operationnel';
    public const DEGRADE = 'degrade';
    public const CRITIQUE = 'critique';
    public const SANS_RELEVE = 'sans_releve';

    /**
     * @param  array<int, array{statut: ?string, releve_a: CarbonInterface|string|null}>  $relevesParEtablissement
     *         Le dernier relevé connu de chaque établissement actif, indexé par
     *         son identifiant. Un établissement jamais sondé y figure avec un
     *         relevé nul — il compte, il ne disparaît pas.
     * @return array{operationnel: int, degrade: int, critique: int, sans_releve: int, total: int}
     */
    public function repartir(
        array $relevesParEtablissement,
        CarbonInterface $maintenant,
        int $fraicheurMinutes
    ): array {
        $repartition = [
            self::OPERATIONNEL => 0,
            self::DEGRADE => 0,
            self::CRITIQUE => 0,
            self::SANS_RELEVE => 0,
        ];

        $horizon = CarbonImmutable::instance($maintenant)->subMinutes(max(1, $fraicheurMinutes));

        foreach ($relevesParEtablissement as $releve) {
            $repartition[$this->etat($releve['statut'] ?? null, $releve['releve_a'] ?? null, $horizon)]++;
        }

        return $repartition + ['total' => count($relevesParEtablissement)];
    }

    /**
     * L'état d'un établissement au vu de son dernier relevé.
     */
    public function etat(?string $statut, CarbonInterface|string|null $releveA, CarbonInterface $horizon): string
    {
        if ($statut === null || $releveA === null) {
            return self::SANS_RELEVE;
        }

        $date = $releveA instanceof CarbonInterface
            ? CarbonImmutable::instance($releveA)
            : CarbonImmutable::parse($releveA);

        if ($date->lessThan($horizon)) {
            return self::SANS_RELEVE;
        }

        return match ($statut) {
            'unhealthy' => self::CRITIQUE,
            'degraded' => self::DEGRADE,
            'healthy' => self::OPERATIONNEL,
            // Un statut inconnu n'est pas une bonne nouvelle : on ne le
            // range pas parmi les opérationnels par défaut de langage.
            default => self::SANS_RELEVE,
        };
    }
}
