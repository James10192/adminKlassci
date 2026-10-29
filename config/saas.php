<?php

return [
    /*
     * Capacités effectives du panel admin master. Leur affectation dépend de la
     * configuration et non de comparaisons de rôles dans les contrôleurs.
     * Les opérateurs de groupe et les comptes d'école ne sont pas inclus.
     */
    'capabilities_by_role' => [
        'super_admin' => [
            'saas.reconciliation.view',
            'saas.reconciliation.propose',
            'saas.reconciliation.review',
        ],
        'support' => [
            'saas.reconciliation.view',
            'saas.reconciliation.propose',
        ],
        'billing' => [
            'saas.reconciliation.view',
        ],
    ],
];
