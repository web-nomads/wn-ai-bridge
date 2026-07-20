<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Bridge',
    'description' => 'TYPO3 extension for generating llms.txt links based on the llmstxt.org specification and an on-site AI search assistant that helps visitors find information via ke_search and indexed_search.',
    'category' => 'be',
    'author' => 'Marcel Marty',
    'author_email' => 'contact@marcelmarty.ch',
    'state' => 'stable',
    'version' => '1.13.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
            'backend' => '13.4.0-14.3.99',
            'extbase' => '13.4.0-14.3.99',
            'fluid' => '13.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'indexed_search' => '',
            'ke_search' => '',
        ],
    ],
];
