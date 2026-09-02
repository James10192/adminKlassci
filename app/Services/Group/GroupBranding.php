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
