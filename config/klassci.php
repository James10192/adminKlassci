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

];
