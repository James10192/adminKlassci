<?php

namespace App\Domain\Care\Tickets\DTO;

use App\Domain\Care\Tickets\Enums\CategorieClient;

/**
 * Ce qu'une instance transmet pour creer une demande, deja valide.
 *
 * L'instance n'y figure pas : elle est deduite de l'identifiant presente, jamais
 * de ce que la requete dit d'elle-meme.
 */
final class SoumissionTicket
{
    /**
     * @param  list<string>  $rolesRapporteur
     * @param  array<string, mixed>  $contexte  cles deja filtrees par la liste blanche
     */
    public function __construct(
        public readonly CategorieClient $categorie,
        public readonly string $description,
        public readonly ?string $titre,
        public readonly int $rapporteurId,
        public readonly ?string $rapporteurNom,
        public readonly ?string $rapporteurEmail,
        public readonly array $rolesRapporteur,
        public readonly array $contexte,
    ) {
    }

    public static function depuisRequete(array $v): self
    {
        return new self(
            categorie: CategorieClient::from($v['report']['category']),
            description: trim($v['report']['description']),
            titre: isset($v['report']['title']) ? trim($v['report']['title']) : null,
            rapporteurId: (int) $v['reporter']['external_id'],
            rapporteurNom: $v['reporter']['name'] ?? null,
            rapporteurEmail: $v['reporter']['email'] ?? null,
            rolesRapporteur: array_values($v['reporter']['roles'] ?? []),
            contexte: $v['context'] ?? [],
        );
    }

    /** Le titre affiche : celui fourni, sinon le debut de la description. */
    public function titreEffectif(): string
    {
        $source = $this->titre !== null && $this->titre !== '' ? $this->titre : $this->description;
        $ligne = preg_replace('/\s+/u', ' ', $source);

        return mb_strlen($ligne) > 120 ? rtrim(mb_substr($ligne, 0, 117)).'…' : $ligne;
    }

    /**
     * Empreinte de ce que la personne a signale, et de qui.
     *
     * Deux envois de la meme cle doivent porter la meme empreinte, sinon la cle
     * a ete reutilisee pour autre chose. Le contexte en est volontairement
     * exclu : il est recapte a chaque envoi (taille de fenetre, identifiants de
     * requete), et un renvoi legitime apres coupure finirait sinon en refus
     * definitif au lieu de retrouver sa demande.
     */
    public function empreinte(): string
    {
        return hash('sha256', json_encode([
            'categorie' => $this->categorie->value,
            'description' => $this->description,
            'titre' => $this->titre,
            'rapporteur' => $this->rapporteurId,
        ], JSON_UNESCAPED_UNICODE));
    }
}
