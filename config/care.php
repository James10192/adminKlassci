<?php

/*
|--------------------------------------------------------------------------
| KLASSCI Care — support, incidents, retours produit
|--------------------------------------------------------------------------
|
| Voir docs/support/KLASSCI_CARE_BLUEPRINT.md.
|
*/

return [

    // Version du contrat /api/v1/support. Ajouts seulement ; une rupture ouvre /v2.
    'api_version' => 1,

    /*
    | Capacites du personnel KLASSCI. Aucune regle n'est ecrite contre un nom
    | de role : le code demande une capacite (Gate), et c'est ce tableau qui dit
    | quel role la porte. Changer l'organisation du support = modifier ici.
    */
    'capacites' => [
        'support.tickets.view' => 'Voir les demandes de support',
        'support.tickets.manage' => 'Traiter les demandes (statut, classement, assignation)',
        'support.tickets.reply' => "Répondre à l'école",
        'support.internal_notes' => 'Lire et écrire les notes internes',
        'support.security.view' => 'Voir les demandes restreintes (sécurité)',
    ],

    'capacites_par_role' => [
        'super_admin' => ['*'],
        'support' => [
            'support.tickets.view',
            'support.tickets.manage',
            'support.tickets.reply',
            'support.internal_notes',
        ],
        'billing' => ['support.tickets.view'],
    ],

    /*
    | Fonctionnalites activables par instance (table tenant_features). Absentes
    | = desactivees : le deploiement est progressif, ecole par ecole.
    */
    'fonctionnalites' => [
        'support_widget',
        'support_customer_portal',
        // Capture d'ecran annotee depuis la fenetre de signalement. Passe par les
        // pieces jointes : sans support_customer_portal, elle ne s'affiche pas.
        'support_screenshot',
    ],

    'limites' => [
        'description_min' => 10,
        // Une reponse peut etre courte (« Oui, en 2A. ») : pas le minimum d'un signalement.
        'reponse_min' => 2,
        // Motif ecrit exige pour rejeter, marquer en doublon, ou changer une
        // severite ou une priorite deja posee.
        'motif_min' => 10,
        'description_max' => 5000,
        'titre_max' => 160,
        'request_ids_max' => 10,
        'extras_octets_max' => 4096,
        'tickets_par_minute' => 30,
        'lectures_par_minute' => 120,
    ],

    /*
    | Pieces jointes. Pas d'antivirus sur l'hebergement mutualise : la defense
    | est une liste blanche de types verifies sur le CONTENU, et le
    | re-encodage des images, qui retire les metadonnees (position GPS d'une
    | photo prise au telephone) et tout ce qui n'est pas des pixels.
    */
    'pieces_jointes' => [
        'disque' => env('CARE_PIECES_DISQUE', 'local'),
        'octets_max' => 5 * 1024 * 1024,
        'par_demande_max' => 10,
        'par_minute' => 20,
        // Au-dela, l'image est reduite : une capture d'ecran n'a pas besoin de plus.
        'cote_max_px' => 2400,
        'types' => [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ],
    ],

    /*
    | Liste blanche du contexte. Ce qui n'y figure pas est ignore, pas rejete :
    | une instance plus recente que le Master ne doit pas voir ses signalements
    | refuses parce qu'elle envoie une cle de plus.
    */
    'contexte' => [
        'types_entite' => [
            'etudiant', 'inscription', 'paiement', 'evaluation', 'note', 'classe',
            'matiere', 'seance', 'bulletin', 'jury', 'enseignant', 'frais', 'emploi_temps',
        ],
        'extras_autorises' => ['semestre', 'etat_affiche', 'composant', 'periode'],
    ],
];
