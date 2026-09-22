<?php

return [

    /*
     * La clé qui chiffre les sauvegardes.
     *
     * Délibérément distincte d'`APP_KEY` : celle-ci se change quand on
     * soupçonne une fuite, et changer la clé d'`APP_KEY` rendrait illisibles
     * toutes les sauvegardes prises jusque-là. Une clé qu'on ne peut pas faire
     * tourner sans perdre l'historique n'est pas une clé qu'on fera tourner.
     *
     * Elle doit être conservée AILLEURS que sur le serveur sauvegardé : une clé
     * rangée à côté du coffre ne ferme rien.
     *
     * Absente ou plus courte que 32 caractères, la sauvegarde est prise en
     * clair et la ligne l'enregistre — jamais interrompue.
     */
    'cle' => env('SAUVEGARDE_CLE'),

    /*
     * Le disque où déposer une copie, hors du serveur sauvegardé.
     *
     * Nom d'un disque de `config/filesystems.php` (`s3`, ou tout autre). Vide,
     * les sauvegardes restent sur place : elles protègent alors d'une erreur
     * de manipulation, pas d'une perte du serveur.
     */
    'disque_hors_site' => env('SAUVEGARDE_DISQUE_HORS_SITE'),

    /*
     * La base où la vérification hebdomadaire restaure les sauvegardes.
     *
     * Sur cPanel, l'utilisateur MySQL d'une instance ne peut pas créer de base :
     * celle-ci est créée une fois dans le panneau, avec tous les privilèges
     * pour l'utilisateur des instances, et partagée par toutes — les
     * vérifications se suivent, et la base est vidée avant et après chacune.
     *
     * Son nom doit se terminer par `_verif_restauration` : c'est ce suffixe qui
     * interdit d'écrire dans la base d'un établissement. Vide, chaque instance
     * restaure dans `<sa base>_verif_restauration`, qui doit alors exister.
     */
    'base_essai' => env('SAUVEGARDE_BASE_ESSAI'),

];
