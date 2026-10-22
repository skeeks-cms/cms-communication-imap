<?php

return [
    'components' => [
        'communication' => [
            'drivers' => [
                'imap' => \skeeks\cms\communication\imap\ImapDriver::class,
            ],
        ],
    ],
];
