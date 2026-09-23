<?php

namespace App\Domain\Care\Tickets\Enums;

/**
 * Les mots de l'ecole, pas ceux du support.
 *
 * Personne ne doit avoir a choisir entre « bug », « incident » et « demande
 * d'evolution » pour signaler quelque chose : la qualification interne vient
 * apres, et c'est le support qui la pose (CategorieInterne).
 */
enum CategorieClient: string
{
    case Probleme = 'PROBLEME';
    case InformationIncorrecte = 'INFORMATION_INCORRECTE';
    case Question = 'QUESTION';
    case Suggestion = 'SUGGESTION';
    case Bloque = 'BLOQUE';
    case Autre = 'AUTRE';

    public function libelle(): string
    {
        return match ($this) {
            self::Probleme => 'Quelque chose ne fonctionne pas',
            self::InformationIncorrecte => 'Une information semble incorrecte',
            self::Question => 'Je ne comprends pas comment faire',
            self::Suggestion => "J'aimerais pouvoir faire quelque chose",
            self::Bloque => 'Mon travail est bloqué',
            self::Autre => 'Autre',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
