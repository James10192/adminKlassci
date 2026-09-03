<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Emails destinataires des alertes
    |--------------------------------------------------------------------------
    |
    | Liste des adresses email qui recevront le récapitulatif quotidien des
    | alertes (quota, expiration, santé, backups). Si vide, les emails des
    | admins actifs seront utilisés en fallback.
    |
    */
    'alert_emails' => array_filter(explode(',', env('KLASSCI_ALERT_EMAILS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Fraîcheur d'un relevé de santé
    |--------------------------------------------------------------------------
    |
    | Au-delà de ce délai, un relevé n'est plus considéré comme disant l'état
    | présent d'un établissement : le tableau de bord le range dans « sans
    | relevé récent » plutôt que de reconduire son dernier verdict.
    |
    | La commande tenant:health-check passe toutes les cinq minutes ; quinze
    | minutes laissent donc la place à deux passages manqués avant qu'un
    | établissement ne bascule, ce qui évite de crier au loup sur un simple
    | retard de planificateur.
    |
    */
    'health_freshness_minutes' => (int) env('KLASSCI_HEALTH_FRESHNESS_MINUTES', 15),

];
