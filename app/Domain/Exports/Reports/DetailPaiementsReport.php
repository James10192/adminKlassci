<?php

namespace App\Domain\Exports\Reports;

use App\Support\Filtres\FiltresRapport;

/**
 * Le journal des encaissements du groupe, une ligne par paiement.
 *
 * C'est le document qu'un comptable rapproche d'un relevé bancaire, et celui
 * qu'un fondateur ouvre quand un chiffre consolidé le surprend : il descend du
 * total à la pièce.
 */
class DetailPaiementsReport extends RapportDetail
{
    public function title(): string
    {
        return 'Détail des paiements';
    }

    public function colonnes(): array
    {
        return [
            ['label' => 'Date', 'format' => self::TEXTE, 'largeur' => 8],
            ['label' => 'Établissement', 'format' => self::TEXTE, 'largeur' => 14],
            ['label' => 'Matricule', 'format' => self::TEXTE, 'largeur' => 10],
            ['label' => 'Étudiant', 'format' => self::TEXTE, 'largeur' => 17],
            ['label' => 'Classe', 'format' => self::TEXTE, 'largeur' => 11],
            ['label' => 'Type', 'format' => self::TEXTE, 'largeur' => 10],
            ['label' => 'Mode', 'format' => self::TEXTE, 'largeur' => 9],
            ['label' => 'Montant', 'format' => self::FCFA, 'largeur' => 10],
            ['label' => 'Référence', 'format' => self::TEXTE, 'largeur' => 11],
        ];
    }

    public function lignes(): array
    {
        $donnees = $this->donnees();

        if ($donnees === []) {
            return $this->ligneVide('Aucun paiement sur ce périmètre et cette période.');
        }

        $lignes = [];

        foreach ($donnees as $p) {
            $lignes[] = [
                $this->dateCourte($p['date'] ?? null),
                $p['etablissement'] ?? null,
                $p['matricule'] ?? null,
                $p['etudiant'] ?? null,
                $p['classe'] ?? null,
                $p['type'] ?? null,
                $p['mode'] ?? null,
                (float) ($p['montant'] ?? 0),
                $p['reference'] ?? null,
            ];
        }

        return $lignes;
    }

    /**
     * Le total n'a de sens que sur un statut unique.
     *
     * Additionner un paiement validé et un paiement rejeté donnerait un
     * « total encaissé » qui ne l'est pas. Quand le document mêle les statuts,
     * il ne totalise pas et le dit — plutôt que d'imprimer une somme qu'un
     * lecteur pressé prendrait pour une recette.
     */
    public function totaux(): ?array
    {
        $donnees = $this->donnees();

        if ($donnees === []) {
            return null;
        }

        // Le libellé se répartit sur DEUX cellules : « TOTAL » dans la colonne
        // Date, le reste dans Établissement. Tout mettre dans la première le
        // faisait casser sur quatre lignes — c'est la plus étroite du tableau,
        // et un total illisible dévalue le document entier.
        $vides = array_fill(0, 4, null);

        if ($this->filtres->statutPaiement === null) {
            return array_merge(
                ['TOTAL', 'statuts mêlés, non totalisé'],
                $vides,
                [null, null, null],
            );
        }

        $somme = array_sum(array_map(static fn (array $p): float => (float) ($p['montant'] ?? 0), $donnees));

        $mention = sprintf(
            '%s — %d paiement%s',
            FiltresRapport::libelleStatutPaiement($this->filtres->statutPaiement),
            count($donnees),
            count($donnees) > 1 ? 's' : '',
        );

        return array_merge(['TOTAL', $mention], $vides, [null, $somme, null]);
    }

}
