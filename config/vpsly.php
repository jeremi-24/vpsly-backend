<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VPSly Plans & Quotas Configuration
    |--------------------------------------------------------------------------
    |
    | Ici sont définies les limites pour chaque plan tarifaire.
    | Un quota à -1 signifie "Illimité".
    |
    */

    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'max_servers' => 1,
            'max_apps' => 1,
            'max_databases' => 1,
            'max_team_members' => 1,
            'features' => [
                'custom_domains' => false,
                'auto_backups' => false,
                'whatsapp_alerts' => false,
                'github_webhooks' => false,
            ],
        ],

        'solo' => [
            'name' => 'Solo',
            'max_servers' => 2,
            'max_apps' => -1,
            'max_databases' => -1,
            'max_team_members' => 2,
            'features' => [
                'custom_domains' => true,
                'auto_backups' => false,
                'whatsapp_alerts' => false,
                'github_webhooks' => false,
            ],
        ],

        'pro' => [
            'name' => 'Pro',
            'max_servers' => -1,
            'max_apps' => -1,
            'max_databases' => -1,
            'max_team_members' => 5,
            'features' => [
                'custom_domains' => true,
                'auto_backups' => true,
                'whatsapp_alerts' => true,
                'github_webhooks' => true,
            ],
        ],
    ],
];
