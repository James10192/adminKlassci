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
    | Connexion de klassci admin:sql
    |--------------------------------------------------------------------------
    |
    | Nom d'une connexion de config/database.php dont l'utilisateur MySQL n'a
    | que SELECT sur la base maître, sans les colonnes d'identifiants ni les
    | tables sessions/cache/jobs (connexion « lecture », DB_LECTURE_*).
    | Tant qu'elle n'est pas renseignée, la lecture SQL reste fermée : aucun
    | filtre sur le texte d'une requête ne vaut ces droits côté base.
    |
    */
    'cli_sql_connexion' => env('CLI_SQL_CONNEXION'),

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

    /*
    |--------------------------------------------------------------------------
    | Actions lentes (contrôle slow_actions)
    |--------------------------------------------------------------------------
    |
    | Lu sur les dernières 24 heures de GET /api/cli/traces/lentes, l'école
    | ayant déjà filtré ce qui passe sous SES seuils (durée, requêtes SQL).
    |
    | - fois_par_jour : au-delà, une action lente n'est plus un accident mais
    |   une habitude, et l'école passe en « dégradé ».
    | - p95_critique_ms : une action dont le 95e centile dépasse ce temps fait
    |   attendre quelqu'un plus de dix secondes une fois sur vingt : critique.
    |
    | Un travail ou une commande en échec rend aussi l'école critique.
    |
    */
    'actions_lentes' => [
        'fois_par_jour' => (int) env('KLASSCI_LENTES_FOIS_PAR_JOUR', 10),
        'p95_critique_ms' => (int) env('KLASSCI_LENTES_P95_CRITIQUE_MS', 10000),
        // Un travail (envoi, tâche, commande) en échec : orange dès le premier,
        // rouge à partir de ce nombre d'échecs dans les 24 heures.
        'echecs_critiques' => (int) env('KLASSCI_LENTES_ECHECS_CRITIQUES', 3),
        // Au-delà, le dernier relevé n'est plus montré : il ne dit plus l'état.
        'fraicheur_minutes' => (int) env('KLASSCI_LENTES_FRAICHEUR_MINUTES', 180),
    ],

];
