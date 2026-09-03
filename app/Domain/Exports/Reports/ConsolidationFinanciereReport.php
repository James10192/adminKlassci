<?php

namespace App\Domain\Exports\Reports;

use App\Domain\Exports\TableauReport;
use App\Support\EtatMesure;

/**
 * Recettes du groupe, établissement par établissement.
 *
 * C'est l'état que le directeur général sort pour un conseil : ce qui était
 * attendu, ce qui est rentré, ce qui manque, et où.
 */
class ConsolidationFinanciereReport extends TableauReport
{
    /**
     * @param  array<string, array<string, mixed>>  $financials  Sortie de GroupFinancialsProvider, par code établissement.
     */
    public function __construct(
        private readonly array $financials,
        private readonly string $nomGroupe,
        private readonly string $periode,
    ) {
    }

    public function title(): string
    {
        return 'Consolidation financière';
    }

    public function subtitle(): ?string
    {
        return $this->nomGroupe;
    }

    public function filters(): array
    {
        $total = count($this->financials);
        $mesures = $this->nombreMesures();

        // « Établissements : 4 » annoncait quatre etablissements CONSOLIDES
        // quand l'un d'eux n'avait pas repondu. Le bandeau du rapport doit dire
        // sur quoi porte le document, pas combien d'ecoles existent.
        return [
            'Période' => $this->periode,
            'Établissements' => $mesures === $total
                ? (string) $total
                : sprintf('%d mesuré%s sur %d', $mesures, $mesures > 1 ? 's' : '', $total),
        ];
    }

    /** Combien d'établissements ont réellement alimenté ce document. */
    private function nombreMesures(): int
    {
        $mesures = 0;

        foreach ($this->financials as $donnees) {
            if (EtatMesure::aUneValeur($donnees['etat'] ?? null)) {
                $mesures++;
            }
        }

        return $mesures;
    }

    public function orientation(): string
    {
        return 'landscape';
    }

    public function colonnes(): array
    {
        return [
            ['label' => 'Établissement', 'format' => self::TEXTE],
            ['label' => 'Attendu', 'format' => self::FCFA],
            ['label' => 'Encaissé', 'format' => self::FCFA],
            ['label' => 'Reste à recouvrer', 'format' => self::FCFA],
            ['label' => 'Taux', 'format' => self::POURCENT],
        ];
    }

    public function lignes(): array
    {
        $lignes = [];

        foreach ($this->financials as $donnees) {
            // Meme patron que MasseSalarialeReport : le nom porte l'etat, les
            // cellules chiffrees passent a `null` — que le formateur PDF rend en
            // tiret et le tableur en case vide. Ce rapport imprimait encore
            // « 0 / 0 / 0 / 0,0 % » pour une ecole injoignable, dans le document
            // qui circule chez un banquier.
            $etat = $donnees['etat'] ?? EtatMesure::MESURE;
            $mesure = EtatMesure::aUneValeur($etat);

            $nom = $donnees['tenant_name'] ?? EtatMesure::TIRET;
            if (! $mesure) {
                $nom .= ' (' . mb_strtolower(EtatMesure::badge($etat, $donnees['motif'] ?? null)) . ')';
            }

            $lignes[] = [
                $nom,
                $mesure ? (float) ($donnees['revenue_expected'] ?? 0) : null,
                $mesure ? (float) ($donnees['revenue_collected'] ?? 0) : null,
                $mesure ? (float) ($donnees['outstanding'] ?? 0) : null,
                // `collection_rate` peut être nul sur une école mesurée dont
                // aucun frais n'est configuré : la cellule reste vide plutôt
                // que d'imprimer un zéro qui se lirait comme un recouvrement.
                $mesure && $donnees['collection_rate'] !== null
                    ? (float) $donnees['collection_rate']
                    : null,
            ];
        }

        return $lignes;
    }

    public function totaux(): ?array
    {
        if (empty($this->financials)) {
            return null;
        }

        $attendu = 0.0;
        $encaisse = 0.0;
        $reste = 0.0;

        // Le total n'additionne que ce qui a ete mesure : une base muette
        // apportait jusqu'ici zero, indiscernable d'une ecole sans recette.
        foreach ($this->financials as $donnees) {
            if (! EtatMesure::aUneValeur($donnees['etat'] ?? null)) {
                continue;
            }

            $attendu += (float) ($donnees['revenue_expected'] ?? 0);
            $encaisse += (float) ($donnees['revenue_collected'] ?? 0);
            $reste += (float) ($donnees['outstanding'] ?? 0);
        }

        $total = count($this->financials);
        $mesures = $this->nombreMesures();

        // Rien de mesure : pas de total. Un « TOTAL GROUPE 0 » sous des lignes
        // vides affirme une consolidation qui n'a pas eu lieu.
        if ($mesures === 0) {
            return ['TOTAL GROUPE — ' . EtatMesure::absenceGroupe(), null, null, null, null];
        }

        // Le taux du groupe se recalcule sur les totaux : faire la moyenne des
        // taux par établissement donnerait le même poids à une école de trente
        // élèves qu'à une de deux mille.
        //
        // Et sans attendu, il n'y a pas de taux : la cellule reste vide. Un
        // « 0,0 % » imprimé sous un total consolidé se lit, chez un banquier,
        // comme un recouvrement nul — alors qu'il ne dit que l'absence de
        // frais configurés.
        $taux = $attendu > 0 ? min(100, round(($encaisse / $attendu) * 100, 1)) : null;

        $mention = EtatMesure::mentionPerimetre($mesures, $total);

        return [
            'TOTAL GROUPE' . ($mention ? ' — ' . $mention : ''),
            $attendu,
            $encaisse,
            $reste,
            $taux,
        ];
    }
}
