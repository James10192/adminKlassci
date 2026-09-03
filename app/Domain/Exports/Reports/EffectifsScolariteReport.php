<?php

namespace App\Domain\Exports\Reports;

/**
 * Qui est inscrit, où, et depuis quand.
 *
 * Scolarité seule : ni téléphone, ni adresse électronique, ni date de
 * naissance. Le raisonnement est dans `FournisseurEffectifs` — en deux mots,
 * les colonnes absentes sont celles qui ne franchissent pas la frontière de
 * l'école qui a collecté ces données.
 */
class EffectifsScolariteReport extends RapportDetail
{
    public function title(): string
    {
        return 'Effectifs et scolarité';
    }

    public function colonnes(): array
    {
        return [
            ['label' => 'Établissement', 'format' => self::TEXTE, 'largeur' => 14],
            ['label' => 'Matricule', 'format' => self::TEXTE, 'largeur' => 10],
            ['label' => 'Nom', 'format' => self::TEXTE, 'largeur' => 13],
            ['label' => 'Prénoms', 'format' => self::TEXTE, 'largeur' => 13],
            ['label' => 'Sexe', 'format' => self::TEXTE, 'largeur' => 8],
            ['label' => 'Classe', 'format' => self::TEXTE, 'largeur' => 12],
            ['label' => 'Filière', 'format' => self::TEXTE, 'largeur' => 12],
            ['label' => 'Niveau', 'format' => self::TEXTE, 'largeur' => 9],
            ['label' => 'Inscrit le', 'format' => self::TEXTE, 'largeur' => 9],
        ];
    }

    public function lignes(): array
    {
        $donnees = $this->donnees();

        if ($donnees === []) {
            return $this->ligneVide('Aucune inscription sur ce périmètre et cette période.');
        }

        $lignes = [];

        foreach ($donnees as $e) {
            $lignes[] = [
                $e['etablissement'] ?? null,
                $e['matricule'] ?? null,
                $e['nom'] ?? null,
                $e['prenoms'] ?? null,
                $this->sexeCourt($e['sexe'] ?? null),
                $e['classe'] ?? null,
                $e['filiere'] ?? null,
                $e['niveau'] ?? null,
                $this->dateCourte($e['date_inscription'] ?? null),
            ];
        }

        return $lignes;
    }

    /**
     * Le total d'un état d'effectifs, c'est un comptage — et sa répartition.
     *
     * « 812 inscrits » seul se lit mal en bas d'une liste de huit cents lignes ;
     * la répartition par sexe est ce qu'une direction reporte au ministère.
     */
    public function totaux(): ?array
    {
        $donnees = $this->donnees();

        if ($donnees === []) {
            return null;
        }

        $total = count($donnees);
        $hommes = 0;
        $femmes = 0;

        foreach ($donnees as $e) {
            match ($this->sexeCourt($e['sexe'] ?? null)) {
                'M' => $hommes++,
                'F' => $femmes++,
                default => null,
            };
        }

        $indetermine = $total - $hommes - $femmes;

        // On n'écrit la mention « non renseigné » que s'il y en a : une
        // parenthèse « (0 non renseigné) » sur chaque document apprendrait à
        // ne plus la lire.
        $repartition = sprintf('%d H · %d F', $hommes, $femmes)
            . ($indetermine > 0 ? sprintf(' · %d non renseigné%s', $indetermine, $indetermine > 1 ? 's' : '') : '');

        return [
            sprintf('TOTAL — %d inscrit%s', $total, $total > 1 ? 's' : ''),
            null, null, null,
            $repartition,
            null, null, null, null,
        ];
    }

    /** M / F, quelle que soit la façon dont l'école a saisi le champ. */
    private function sexeCourt(mixed $valeur): ?string
    {
        $brut = mb_strtoupper(trim((string) ($valeur ?? '')));

        if ($brut === '') {
            return null;
        }

        return match (mb_substr($brut, 0, 1)) {
            'M' => 'M',
            'F' => 'F',
            default => null,
        };
    }

}
