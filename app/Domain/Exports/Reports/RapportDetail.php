<?php

namespace App\Domain\Exports\Reports;

use App\Domain\Exports\TableauReport;
use App\Support\EtatMesure;
use App\Support\Filtres\FiltresRapport;

/**
 * Le socle des états de détail : une ligne par paiement, par étudiant.
 *
 * Il ne porte qu'une chose, et c'est celle qui manquerait si chaque rapport la
 * réécrivait : DIRE QUELLES ÉCOLES ONT RÉPONDU.
 *
 * Dans un état agrégé, une école muette laisse une ligne au tiret — visible.
 * Dans un état de détail, elle ne laisse RIEN : ses lignes sont simplement
 * absentes, et rien dans le document ne distingue « cette école n'a encaissé
 * aucun paiement ce mois-ci » de « cette école n'a pas répondu ». Le premier
 * appelle une relance commerciale, le second un appel à l'hébergeur.
 *
 * Le bandeau porte donc toujours le décompte, et la raison quand elle est
 * commune. C'est la seule place où l'absence peut se voir.
 */
abstract class RapportDetail extends TableauReport
{
    /**
     * @param  array{lignes: array<int, array<string, mixed>>, total: int, repondants: int, manquants: array<string, array{nom: string, motif: string}>}  $resultat
     */
    public function __construct(
        protected readonly array $resultat,
        protected readonly string $nomGroupe,
        protected readonly FiltresRapport $filtres,
        /** @var array<int, string> Noms des écoles retenues, pour le bandeau. */
        protected readonly array $nomsEtablissements = [],
    ) {
    }

    public function subtitle(): ?string
    {
        return $this->nomGroupe;
    }

    public function orientation(): string
    {
        return 'landscape';
    }

    public function filters(): array
    {
        $libelles = $this->filtres->libelles($this->nomsEtablissements);

        $total = (int) ($this->resultat['total'] ?? 0);
        $repondants = (int) ($this->resultat['repondants'] ?? 0);
        $manquants = $this->resultat['manquants'] ?? [];

        // Périmètre complet : on se tait. Le portail ne commente que ce qui
        // manque — annoncer « 4 sur 4 » à chaque document apprendrait au
        // lecteur à ne plus lire cette ligne, et c'est précisément la ligne
        // qu'il devra lire le jour où une école tombe.
        if ($manquants === []) {
            $libelles['Périmètre'] = sprintf('%d établissement%s', $total, $total > 1 ? 's' : '');

            return $libelles;
        }

        // `raisonCommune()` ne rend null que sur un tableau vide, écarté juste
        // au-dessus : la raison est donc toujours là, et un repli « n'ont pas
        // répondu » serait du code que rien ne peut atteindre.
        $libelles['Périmètre'] = sprintf(
            '%d établissement%s sur %d — %s (%s)',
            $repondants,
            $repondants > 1 ? 's' : '',
            $total,
            implode(', ', array_column($manquants, 'nom')),
            EtatMesure::raisonCommune($manquants),
        );

        return $libelles;
    }

    /** Les lignes brutes remontées par le fournisseur. @return array<int, array<string, mixed>> */
    protected function donnees(): array
    {
        return $this->resultat['lignes'] ?? [];
    }

    /**
     * Une date de base rendue en JJ/MM/AAAA.
     *
     * Sans supposer que le pilote renvoie un objet date : selon la connexion et
     * la version du serveur, la même colonne arrive en chaîne ou en objet, et
     * un rapport ne doit pas dépendre de ce hasard. Une valeur illisible est
     * rendue telle quelle plutôt que masquée — mieux vaut une date étrange
     * qu'une case vide dont personne ne saura qu'elle cache quelque chose.
     */
    protected function dateCourte(mixed $valeur): ?string
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $valeur)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $valeur;
        }
    }

    /**
     * Ce qu'on écrit quand le document est vide.
     *
     * Un tableau sans ligne ne dit pas s'il est vide parce qu'il n'y a rien à
     * montrer ou parce que le filtre est trop serré. La ligne unique le dit.
     *
     * @return array<int, array<int, string|null>>
     */
    protected function ligneVide(string $phrase): array
    {
        $vide = array_fill(0, count($this->colonnes()) - 1, null);

        return [array_merge([$phrase], $vide)];
    }
}
