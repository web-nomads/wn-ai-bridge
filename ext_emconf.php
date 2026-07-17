<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Bridge',
    'description' => 'TYPO3 extension for generating llms.txt links based on the llmstxt.org specification to control how Large Language Models crawl and use website content.',
    'category' => 'be',
    'author' => 'Marcel Marty',
    'author_email' => 'contact@marcelmarty.ch',
    'state' => 'stable',
    'version' => '1.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
            'backend' => '13.4.0-14.3.99',
            'extbase' => '13.4.0-14.3.99',
            'fluid' => '13.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
