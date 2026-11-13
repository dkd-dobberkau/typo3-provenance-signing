<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Content Provenance: Signing',
    'description' => 'Ed25519 signatures for published content, with key generation and rotation.',
    'category' => 'fe',
    'state' => 'experimental',
    'version' => '0.0.0',
    'constraints' => [
        'depends' => [
        'typo3' => '13.4.0-14.99.99',
        'php' => '8.2.0-8.4.99',
        'provenance' => '0.1.0-0.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
