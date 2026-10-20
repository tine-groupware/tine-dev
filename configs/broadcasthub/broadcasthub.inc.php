<?php

return [
    'broadcasthub' => [
        'active' => true,
        'url'    => 'wss://broadcasthub.'.getenv("DOMAIN_SUFFIX"),
        'redis'  => [
            'host' => 'cache',
        ],
    ],
];
