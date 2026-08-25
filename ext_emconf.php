<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Bridge',
    'description' => 'TYPO3 extension for generating llms.txt links based on the llmstxt.org specification and an on-site AI search assistant that helps visitors find information via ke_search and indexed_search.',
    'category' => 'be',
    'author' => 'Marcel Marty',
    'author_email' => 'contact@marcelmarty.ch',
    'state' => 'stable',
    'version' => '1.26.2',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'backend' => '13.4.0-14.99.99',
            'extbase' => '13.4.0-14.99.99',
            'fluid' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'indexed_search' => '',
            'ke_search' => '',
        ],
    ],
];
