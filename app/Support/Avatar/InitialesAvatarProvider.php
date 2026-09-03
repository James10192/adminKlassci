<?php

namespace App\Support\Avatar;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Les initiales, dessinées ici, sans appeler personne.
 *
 * Filament fournit par défaut UiAvatarsProvider, qui construit une URL vers
 * ui-avatars.com AVEC LE NOM DE LA PERSONNE dedans :
 *
 *     https://ui-avatars.com/api/?name=Marcel+Djedje-li&background=...
 *
 * Le nom de chaque administrateur et de chaque fondateur part donc chez un
 * tiers à chaque affichage de page, pour dessiner deux lettres. Et quand ce
 * tiers est injoignable — pare-feu d'entreprise, coupure, hébergeur qui filtre
 * le sortant — l'avatar se casse sur tout le panel, ce qui est exactement ce
 * qu'on observe.
 *
 * Un SVG en data URI règle les deux : rien ne sort, rien ne casse.
 */
class InitialesAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $nom = trim((string) ($record->getAttribute('name') ?? ''));

        return $this->dataUri($this->initiales($nom), $this->couleur($nom));
    }

    /**
     * Deux lettres au plus : la première du prénom, la première du dernier mot.
     */
    public function initiales(string $nom): string
    {
        $mots = preg_split('/[\s\-]+/u', $nom, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($mots === []) {
            return '?';
        }

        $premiere = mb_strtoupper(mb_substr($mots[0], 0, 1));

        if (count($mots) === 1) {
            return $premiere;
        }

        return $premiere.mb_strtoupper(mb_substr(end($mots), 0, 1));
    }

    /**
     * Une teinte stable par personne, tirée de son nom : deux collaborateurs
     * n'ont pas la même pastille, et la même personne garde la sienne.
     * La saturation et la clarté sont fixes pour rester dans la famille
     * KLASSCI et garder le blanc lisible par-dessus.
     */
    public function couleur(string $nom): string
    {
        if ($nom === '') {
            return '#64748b';
        }

        $teinte = crc32($nom) % 360;

        return sprintf('hsl(%d, 42%%, 38%%)', $teinte);
    }

    private function dataUri(string $initiales, string $fond): string
    {
        $texte = htmlspecialchars($initiales, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96">'
            .'<rect width="96" height="96" rx="48" fill="'.$fond.'"/>'
            .'<text x="48" y="49" fill="#ffffff" font-family="DM Sans, system-ui, sans-serif"'
            .' font-size="38" font-weight="600" letter-spacing="1"'
            .' text-anchor="middle" dominant-baseline="central">'.$texte.'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
