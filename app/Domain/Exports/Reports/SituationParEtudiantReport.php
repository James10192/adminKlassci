<?php

namespace App\Domain\Exports\Reports;

use App\Support\EtatMesure;

/**
 * Qui doit combien, étudiant par étudiant, du plus gros reste au plus petit.
 *
 * Le document du recouvrement. Il répond à la question qu'un fondateur pose
 * après avoir lu la consolidation : « d'accord, il manque quarante millions —
 * chez qui ? »
 */
class SituationParEtudiantReport extends RapportDetail
{
    public function title(): string
    {
        return 'Situation par étudiant';
    }

    public function colonnes(): array
    {
        return [
            ['label' => 'Établissement', 'format' => self::TEXTE, 'largeur' => 15],
            ['label' => 'Matricule', 'format' => self::TEXTE, 'largeur' => 11],
            ['label' => 'Étudiant', 'format' => self::TEXTE, 'largeur' => 19],
            ['label' => 'Classe', 'format' => self::TEXTE, 'largeur' => 12],
            ['label' => 'Attendu', 'format' => self::FCFA, 'largeur' => 11],
            ['label' => 'Encaissé', 'format' => self::FCFA, 'largeur' => 11],
            ['label' => 'Reste', 'format' => self::FCFA, 'largeur' => 11],
            ['label' => 'Dernier versement', 'format' => self::TEXTE, 'largeur' => 10],
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
            $attendu = (float) ($e['attendu'] ?? 0);

            $lignes[] = [
                $e['etablissement'] ?? null,
                $e['matricule'] ?? null,
                $e['etudiant'] ?? null,
                $e['classe'] ?? null,
                // Sans attendu, l'école n'a pas configuré ses frais pour ce
                // dossier. Les trois colonnes chiffrées passent au tiret plutôt
                // qu'à des zéros, qui se liraient comme « rien à payer, rien
                // payé, rien dû » — trois affirmations qu'on ne peut pas faire.
                $attendu > 0 ? $attendu : null,
                $attendu > 0 ? (float) ($e['encaisse'] ?? 0) : null,
                $attendu > 0 ? (float) ($e['reste'] ?? 0) : null,
                $this->dateCourte($e['dernier_paiement'] ?? null),
            ];
        }

        return $lignes;
    }

    public function totaux(): ?array
    {
        $donnees = $this->donnees();

        if ($donnees === []) {
            return null;
        }

        $attendu = 0.0;
        $encaisse = 0.0;
        $reste = 0.0;
        $chiffres = 0;

        foreach ($donnees as $e) {
            if ((float) ($e['attendu'] ?? 0) <= 0) {
                continue; // Un dossier sans barème n'entre pas dans le total.
            }

            $chiffres++;
            $attendu += (float) $e['attendu'];
            $encaisse += (float) ($e['encaisse'] ?? 0);
            $reste += (float) ($e['reste'] ?? 0);
        }

        if ($chiffres === 0) {
            return ['TOTAL — ' . EtatMesure::absenceGroupe(), null, null, null, null, null, null, null];
        }

        $total = count($donnees);

        // Le libellé dit sur combien de dossiers porte le total. Sans cette
        // mention, un total portant sur la moitié des lignes se lit comme
        // portant sur toutes.
        $libelle = $chiffres === $total
            ? sprintf('TOTAL — %d étudiant%s', $total, $total > 1 ? 's' : '')
            : sprintf('TOTAL — %d dossier%s chiffré%s sur %d', $chiffres, $chiffres > 1 ? 's' : '', $chiffres > 1 ? 's' : '', $total);

        return [$libelle, null, null, null, $attendu, $encaisse, $reste, null];
    }

}
