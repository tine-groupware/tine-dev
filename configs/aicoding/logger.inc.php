<?php
/**
 * additional tine log for the AI coding clients (compose/aicoding.yml): one JSON object per line with message,
 * priority, user, request_id, transaction_id, ... - the clients read it read-only at /workspace/logs/tine20.jsonl.
 * The normal log (php://stdout, docker logs) is unchanged. The file is emptied by docker:up -d / webpackrestart.
 */
return [
    'logger' => [
        'additionalWriters' => [
            // named key: conf.d files are deep merged, a numeric key could replace another writer
            'aicoding' => [
                'active' => true,
                'filename' => '/var/log/tine20/tine20.jsonl',
                'priority' => 7,
                'formatter' => 'json',
            ],
        ],
    ],
];
