<?php

use App\Domain\Exports\Reports\ConsolidationFinanciereReport;
use App\Domain\Exports\Reports\DetailPaiementsReport;
use App\Domain\Exports\Reports\EffectifsScolariteReport;
use App\Domain\Exports\Reports\EtatEtablissementsReport;
use App\Domain\Exports\Reports\MasseSalarialeReport;
use App\Domain\Exports\Reports\SanteAbonnementsReport;
use App\Domain\Exports\Reports\SituationParEtudiantReport;

/**
 * Un en-tete de colonne qui ne tient pas dans sa colonne casse en plein mot.
 *
 * Le gabarit pose `word-wrap: break-word` sur les cellules d'un tableau a
 * largeurs declarees — c'est la bonne protection pour une DONNEE : un nom
 * d'etablissement plus long que prevu doit passer a la ligne plutot que
 * deborder en silence sur la colonne voisine. Applique a un EN-TETE, dont le
 * libelle est court et connu a l'avance, la meme regle produit « FIN
 * D'ABONNEME / NT » — vu le 03/09/2026 sur le rapport Sante et abonnements,
 * ou « D'ABONNEMENT » reclamait 72pt pour 70pt disponibles.
 *
 * Deux points a retenir sur ce defaut. Il est SILENCIEUX : le PDF sort, il
 * pese son poids normal, aucun test ne rougit, et personne ne le voit avant
 * qu'un fondateur ne pose le tirage sur la table d'un conseil. Et il est
 * INVISIBLE A LA RELECTURE du code : les largeurs somment a 100, les libelles
 * sont corrects, rien dans le fichier ne signale la collision.
 *
 * D'ou ce controle, qui mesure ce que la relecture ne peut pas voir.
 *
 * L'estimation de largeur est volontairement PRUDENTE : elle surestime
 * legerement la place occupee, de sorte qu'un libelle limite soit signale
 * plutot qu'accepte de justesse. Un faux positif coute deux pourcents de
 * largeur ; un faux negatif coute un document mal imprime.
 */

/** Largeur utile d'une page A4, marges laterales du gabarit deduites (34px x 2). */
function largeurUtile(string $orientation): float
{
    return ($orientation === 'landscape' ? 842.0 : 595.0) - 51.0;
}

/**
 * Largeur estimee d'un mot en majuscules a 8pt, interlettrage compris.
 *
 * Le gabarit rend les en-tetes en `text-transform: uppercase`, `font-size: 8pt`
 * et `letter-spacing: .3px`. On majore a 0.72em par caractere : au-dessus de la
 * moyenne d'une DejaVu Sans en capitales, ce qui donne la marge voulue.
 */
function largeurMotMajuscule(string $mot): float
{
    return mb_strlen($mot) * 8.0 * 0.72 + mb_strlen($mot) * 0.22;
}

dataset('rapports a largeurs declarees', [
    'santé et abonnements' => [SanteAbonnementsReport::class],
    'état des établissements' => [EtatEtablissementsReport::class],
    'consolidation financière' => [ConsolidationFinanciereReport::class],
    'masse salariale' => [MasseSalarialeReport::class],
    'détail des paiements' => [DetailPaiementsReport::class],
    'situation par étudiant' => [SituationParEtudiantReport::class],
    'effectifs et scolarité' => [EffectifsScolariteReport::class],
]);

it('ne laisse aucun en-tete casser en plein mot', function (string $classe) {
    $reflet = new ReflectionClass($classe);

    if (! $reflet->hasMethod('colonnes')) {
        expect(true)->toBeTrue(); // Disposition automatique : rien a verifier.

        return;
    }

    $rapport = $reflet->newInstanceWithoutConstructor();
    $colonnes = $reflet->getMethod('colonnes')->invoke($rapport);

    // Les rapports en disposition automatique ne declarent pas de largeur :
    // DomPDF repartit lui-meme et n'a alors aucune contrainte a violer.
    $declarees = array_filter($colonnes, fn ($c) => isset($c['largeur']));
    if ($declarees === []) {
        expect(true)->toBeTrue();

        return;
    }

    // Une largeur declaree n'a de sens que si l'ensemble couvre la page.
    expect(array_sum(array_column($declarees, 'largeur')))
        ->toBe(100, 'les largeurs declarees doivent sommer a 100 %');

    $utile = largeurUtile($rapport->orientation());

    foreach ($declarees as $colonne) {
        $disponible = $utile * ($colonne['largeur'] / 100) - 9.0; // padding 6px x 2

        $plusLong = 0.0;
        foreach (preg_split('/\s+/', mb_strtoupper($colonne['label'])) as $mot) {
            $plusLong = max($plusLong, largeurMotMajuscule($mot));
        }

        expect($plusLong)->toBeLessThanOrEqual(
            $disponible,
            sprintf(
                'L\'en-tete « %s » reclame %.0fpt et sa colonne n\'en offre que %.0fpt '
                .'(%d %%). Il cassera en plein mot dans le PDF. Elargissez la colonne '
                .'en prenant sur une voisine, ou raccourcissez le libelle.',
                $colonne['label'],
                $plusLong,
                $disponible,
                $colonne['largeur'],
            ),
        );
    }
})->with('rapports a largeurs declarees');
