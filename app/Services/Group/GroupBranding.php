<?php

namespace App\Services\Group;

use App\Models\Group;
use Illuminate\Support\Facades\Storage;

/**
 * Résout l'identité visuelle affichée dans le portail groupe.
 *
 * Deux étages aujourd'hui, du plus spécifique au plus générique :
 *
 *   1. le groupe connecté   (groups.logo_path, groups.metadata->branding)
 *   2. la marque KLASSCI    (config/group_portal.branding)
 *
 * Le premier qui répond gagne. Aucune valeur n'est écrite en dur dans le
 * PanelProvider : un groupe qui dépose son logo doit voir son portail changer
 * sans redéploiement.
 *
 * Un étage établissement viendra s'intercaler, mais pas encore, et pour deux
 * raisons qu'il vaut mieux écrire que redécouvrir :
 *
 *  - Le logo d'une école vit dans SA base, sous le réglage `school_logo`, et
 *    le chemin y est écrit de trois façons selon l'ancienneté du tenant —
 *    avec le préfixe `storage/`, sans, ou réduit au seul nom de fichier.
 *    Côté KLASSCIv2, SettingsHelper::candidatsLogo() tranche en essayant les
 *    candidats contre son disque local. Depuis le master on ne peut pas
 *    vérifier qu'un fichier existe sur la machine d'en face : construire
 *    l'URL à l'aveugle mettrait une image cassée en haut de page.
 *  - Le rabattre côté master (tenants.metadata->branding) supposerait
 *    d'ajouter un champ imbriqué au formulaire établissement, où un Textarea
 *    lié à `metadata` entier écrase déjà tout à l'enregistrement. Il faut
 *    d'abord démêler ce champ.
 *
 * Toutes les méthodes sont sûres hors requête authentifiée (page de
 * connexion, console, tests) : elles retombent alors sur la marque KLASSCI.
 */
class GroupBranding
{
    /**
     * Le groupe de la session courante, ou null hors authentification.
     *
     * Volontairement tolérant : ce service est appelé depuis des closures de
     * configuration Filament qui s'exécutent aussi sur /groupe/login, où
     * aucun garde n'est encore résolu.
     */
    public function currentGroup(): ?Group
    {
        $user = auth('group')->user();

        return $user?->group;
    }

    /** Nom affiché dans la barre latérale et l'onglet du navigateur. */
    public function name(?Group $group = null): string
    {
        $group ??= $this->currentGroup();

        return $group?->name
            ?: (string) config('group_portal.branding.name', 'KLASSCI Groupe');
    }

    /** URL absolue du logo — celui du groupe s'il en a déposé un. */
    public function logoUrl(?Group $group = null): string
    {
        $group ??= $this->currentGroup();

        $path = $group?->logo_path;

        if (filled($path)) {
            // Un chemin déjà absolu (CDN, URL signée) est utilisé tel quel.
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }

            // Sinon on n'affiche le fichier que s'il existe réellement : un
            // logo_path pointant dans le vide donnerait une image cassée en
            // haut de chaque page, ce qui est pire que le logo KLASSCI.
            if (Storage::disk('public')->exists($path)) {
                return Storage::disk('public')->url($path);
            }
        }

        return asset((string) config('group_portal.branding.logo', 'images/LOGO-KLASSCI-PNG.png'));
    }

    /**
     * Logo encodé en data URI, pour les documents générés.
     *
     * DomPDF ne va pas chercher d'image distante sans qu'on lui ouvre le
     * réseau, ce qu'on ne fait pas : un générateur de PDF qui suit des URL
     * arbitraires est une porte ouverte. On lit donc le fichier sur le
     * disque et on l'embarque dans le document.
     *
     * Retourne null si aucun fichier lisible : l'en-tête préfère se passer
     * de logo plutôt que d'afficher un cadre vide.
     */
    public function logoDataUri(?Group $group = null): ?string
    {
        $chemin = $this->logoPath($group);

        if ($chemin === null) {
            return null;
        }

        $type = match (strtolower(pathinfo($chemin, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $octets = @file_get_contents($chemin);

        if ($octets === false || $octets === '') {
            return null;
        }

        return 'data:' . $type . ';base64,' . base64_encode($octets);
    }

    /**
     * Le logo sur le disque, ou null si aucun fichier lisible.
     *
     * Extrait de `logoDataUri()`, qui reste son seul usage historique : le
     * tableur ne peut pas consommer une data URI — PhpSpreadsheet veut un
     * chemin — et ecrire une seconde resolution de candidats aurait garanti
     * qu'un jour les deux exports affichent deux logos differents.
     */
    public function logoPath(?Group $group = null): ?string
    {
        $source = $this->logoSourcePath($group);

        if ($source === null) {
            return null;
        }

        return $this->miniature($source) ?? $source;
    }

    /** Le fichier deposé, sans redimensionnement. */
    private function logoSourcePath(?Group $group = null): ?string
    {
        $group ??= $this->currentGroup();

        $candidats = [];

        if (filled($group?->logo_path) && ! str_starts_with((string) $group->logo_path, 'http')) {
            $candidats[] = Storage::disk('public')->path($group->logo_path);
        }

        $candidats[] = public_path((string) config('group_portal.branding.logo', 'images/LOGO-KLASSCI-PNG.png'));

        foreach ($candidats as $chemin) {
            if (is_file($chemin) && is_readable($chemin)) {
                return $chemin;
            }
        }

        return null;
    }

    /**
     * Une copie réduite du logo, mise en cache — ou null s'il n'en faut pas.
     *
     * Le logo KLASSCI fait 1080 × 1080 pour 126 Ko. Les documents l'affichent
     * à 40 points dans le PDF, 52 pixels dans le tableur. DomPDF, lui, décode
     * l'image et la ré-encode telle quelle : un état de QUATRE lignes pesait
     * ainsi 994 Ko, dont 99 % de logo — et cet état part en pièce jointe à
     * chaque destinataire, chaque semaine ou chaque mois.
     *
     * Le seuil est configurable : un groupe qui déposerait un logo très large
     * en tirerait le même bénéfice sans qu'on touche à son fichier d'origine.
     *
     * Tout échec — GD absent, format exotique, disque en lecture seule —
     * retombe silencieusement sur la source : un document un peu lourd vaut
     * mieux qu'un document sans logo.
     */
    private function miniature(string $source): ?string
    {
        $max = (int) config('group_portal.branding.logo_max_px', 320);

        if ($max < 16 || ! extension_loaded('gd')) {
            return null;
        }

        $taille = @getimagesize($source);

        if ($taille === false) {
            return null; // SVG, WebP exotique : on ne touche pas.
        }

        [$largeur, $hauteur] = $taille;

        if ($largeur <= $max && $hauteur <= $max) {
            return null; // Déjà raisonnable.
        }

        // La clé porte mtime ET taille : un logo remplacé par un autre de même
        // date ne doit pas servir l'ancienne vignette.
        $cle = sha1($source . '|' . @filemtime($source) . '|' . @filesize($source) . '|' . $max);
        $cible = storage_path('app/branding/' . $cle . '.png');

        if (is_file($cible)) {
            return $cible;
        }

        $ratio = $max / max($largeur, $hauteur);
        $nouvelleLargeur = max(1, (int) round($largeur * $ratio));
        $nouvelleHauteur = max(1, (int) round($hauteur * $ratio));

        $origine = match ($taille[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            default => null,
        };

        if (! $origine) {
            return null;
        }

        try {
            $reduit = imagecreatetruecolor($nouvelleLargeur, $nouvelleHauteur);

            // Sans ces deux lignes, un logo à fond transparent ressort sur un
            // aplat noir — au milieu du bandeau de couleur du groupe.
            imagealphablending($reduit, false);
            imagesavealpha($reduit, true);
            imagecopyresampled($reduit, $origine, 0, 0, 0, 0, $nouvelleLargeur, $nouvelleHauteur, $largeur, $hauteur);

            if (! is_dir(dirname($cible)) && ! @mkdir(dirname($cible), 0755, true) && ! is_dir(dirname($cible))) {
                return null;
            }

            $ecrit = @imagepng($reduit, $cible, 9);
        } finally {
            imagedestroy($origine);
            if (isset($reduit) && $reduit) {
                imagedestroy($reduit);
            }
        }

        return $ecrit ? $cible : null;
    }

    /** Hauteur CSS du logo dans la barre latérale. */
    public function logoHeight(): string
    {
        return (string) config('group_portal.branding.logo_height', '2.5rem');
    }

    /** URL du favicon — suit le logo du groupe quand il existe. */
    public function faviconUrl(?Group $group = null): string
    {
        $group ??= $this->currentGroup();

        if (filled($group?->logo_path)) {
            return $this->logoUrl($group);
        }

        return asset((string) config('group_portal.branding.favicon', 'images/LOGO-KLASSCI-PNG.png'));
    }

    /**
     * Couleur primaire au format hexadécimal.
     *
     * Stockée dans groups.metadata->branding->primary, ce qui évite une
     * migration pour une donnée que peu de groupes personnaliseront. Une
     * valeur non hexadécimale est ignorée plutôt que propagée : elle
     * casserait la feuille de style de toutes les pages.
     */
    public function primaryHex(?Group $group = null): string
    {
        $group ??= $this->currentGroup();

        $candidate = data_get($group?->metadata, 'branding.primary');

        if (is_string($candidate) && preg_match('/^#[0-9a-fA-F]{6}$/', $candidate) === 1) {
            return $candidate;
        }

        return (string) config('group_portal.branding.primary', '#0453cb');
    }
}
