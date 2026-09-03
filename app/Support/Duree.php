<?php

namespace App\Support;

/**
 * Les durées, écrites comme on les lit.
 *
 * Une seule règle, mais qui manquait partout : « expire dans 1 jours »,
 * « expiré depuis 1 jours ». Sur l'alerte la plus urgente qu'un fondateur
 * verra — celle qui lui dit que l'abonnement d'une de ses écoles se termine —
 * un pluriel fautif fait douter du reste du tableau.
 *
 * La classe est délibérément minuscule : le jour où une deuxième unité
 * apparaît, elle vient ici, et non dans un troisième `sprintf` recopié.
 */
class Duree
{
    /** « 1 jour », « 12 jours ». */
    public static function jours(int $nombre): string
    {
        return $nombre . ' jour' . (abs($nombre) > 1 ? 's' : '');
    }

    /** « 1 mois » — invariable, mais on ne veut pas d'un `sprintf` de plus. */
    public static function mois(int $nombre): string
    {
        return $nombre . ' mois';
    }
}
