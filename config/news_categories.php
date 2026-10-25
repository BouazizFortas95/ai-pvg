<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Dynamic category discovery
    |--------------------------------------------------------------------------
    |
    | RSS items may carry explicit category tags, feed-level categories, or
    | only article text. Existing categories are matched case-insensitively
    | by name or slug; aliases collapse common variants into one first-class
    | category. Unknown categories are created automatically.
    |
    */

    'fallback' => env('NEWS_DEFAULT_CATEGORY', 'Uncategorized'),

    // Minimum weighted keyword score before content-only detection is
    // trusted. Explicit RSS categories bypass this threshold.
    'detection_min_score' => (float) env('NEWS_CATEGORY_DETECTION_MIN_SCORE', 2.0),

    'aliases' => [
        'ai' => 'Artificial Intelligence',
        'artificial-intelligence' => 'Artificial Intelligence',
        'artificial intelligence' => 'Artificial Intelligence',
        'machine learning' => 'Artificial Intelligence',
        'machine-learning' => 'Artificial Intelligence',
        'ml' => 'Artificial Intelligence',
        'llm' => 'Artificial Intelligence',
        'large language models' => 'Artificial Intelligence',
        'generative ai' => 'Artificial Intelligence',
        'cybersecurity' => 'Security',
        'cyber security' => 'Security',
        'infosec' => 'Security',
        'tech' => 'Technology',
        'tech news' => 'Technology',
        'technology news' => 'Technology',
        'startup' => 'Startups',
        'startups' => 'Startups',
        'venture capital' => 'Startups',
        'vc' => 'Startups',
        'crypto' => 'Crypto',
        'cryptocurrency' => 'Crypto',
        'blockchain' => 'Crypto',
        'gaming' => 'Gaming',
        'video games' => 'Gaming',
        'open-source' => 'Open Source',
        'developer tools' => 'Developer Tools',
        'dev tools' => 'Developer Tools',
        'policy' => 'Policy',
        'regulation' => 'Policy',
        'php' => 'PHP',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content detectors
    |--------------------------------------------------------------------------
    |
    | Used only when an article has no usable category tag and no feed-level
    | category. Title matches are weighted twice; body matches are capped to
    | avoid one repeated word dominating the result.
    |
    */

    'detectors' => [
        'Artificial Intelligence' => [
            'artificial intelligence' => 3.0,
            'machine learning' => 3.0,
            'generative ai' => 3.0,
            'large language model' => 3.0,
            'deep learning' => 3.0,
            'neural network' => 3.0,
            'openai' => 3.0,
            'anthropic' => 3.0,
            'chatgpt' => 3.0,
            'llm' => 2.0,
            'ai model' => 3.0,
            'ai' => 1.5,
        ],
        'Security' => [
            'cybersecurity' => 3.0,
            'ransomware' => 3.0,
            'malware' => 3.0,
            'phishing' => 3.0,
            'zero-day' => 3.0,
            'zero day' => 3.0,
            'data breach' => 3.0,
            'vulnerability' => 2.0,
            'breach' => 2.0,
            'exploit' => 2.0,
            'encryption' => 2.0,
            'cve' => 2.0,
            'security' => 1.5,
        ],
        'Startups' => [
            'startup' => 3.0,
            'startups' => 3.0,
            'seed round' => 3.0,
            'series a' => 3.0,
            'series b' => 3.0,
            'series c' => 3.0,
            'venture capital' => 3.0,
            'funding round' => 3.0,
            'valuation' => 2.0,
            'accelerator' => 2.0,
            'founder' => 1.5,
        ],
        'Business' => [
            'quarterly results' => 3.0,
            'ipo' => 3.0,
            'earnings' => 2.0,
            'revenue' => 2.0,
            'acquisition' => 2.0,
            'acquires' => 2.0,
            'merger' => 2.0,
            'layoffs' => 2.0,
            'antitrust' => 2.0,
            'market cap' => 2.0,
        ],
        'Hardware' => [
            'semiconductor' => 3.0,
            'chipmaker' => 3.0,
            'gpu' => 2.0,
            'processor' => 2.0,
            'laptop' => 2.0,
            'smartphone' => 2.0,
            'wearable' => 2.0,
            'hardware' => 2.0,
            'ssd' => 2.0,
            'data center' => 2.0,
        ],
        'Software' => [
            'open source' => 2.0,
            'software' => 2.0,
            'sdk' => 2.0,
            'programming' => 2.0,
            'developer' => 1.5,
            'api' => 1.5,
            'framework' => 1.5,
            'release' => 0.75,
            'version' => 0.75,
        ],
        'Policy' => [
            'gdpr' => 3.0,
            'regulation' => 2.0,
            'regulator' => 2.0,
            'lawsuit' => 2.0,
            'legislation' => 2.0,
            'antitrust' => 2.0,
            'court' => 1.5,
            'government' => 1.5,
            'privacy' => 1.5,
        ],
        'Crypto' => [
            'cryptocurrency' => 3.0,
            'bitcoin' => 3.0,
            'ethereum' => 3.0,
            'blockchain' => 3.0,
            'stablecoin' => 3.0,
            'web3' => 3.0,
            'defi' => 3.0,
            'nft' => 2.0,
        ],
        'Science' => [
            'spacecraft' => 3.0,
            'physics' => 3.0,
            'genome' => 3.0,
            'nasa' => 3.0,
            'scientists' => 2.0,
            'climate' => 2.0,
            'vaccine' => 2.0,
            'research' => 1.0,
        ],
        'Gaming' => [
            'video game' => 3.0,
            'playstation' => 3.0,
            'nintendo' => 3.0,
            'xbox' => 3.0,
            'steam deck' => 3.0,
            'gaming' => 2.0,
        ],
        'Mobile' => [
            'iphone' => 3.0,
            'android' => 3.0,
            'smartphone' => 2.0,
            'ipad' => 3.0,
            'mobile app' => 2.0,
            'pixel phone' => 3.0,
        ],
        'Cloud' => [
            'cloud computing' => 3.0,
            'kubernetes' => 3.0,
            'serverless' => 3.0,
            'google cloud' => 3.0,
            'aws' => 3.0,
            'azure' => 3.0,
            'data center' => 2.0,
        ],
        'Developer Tools' => [
            'developer tools' => 3.0,
            'programming language' => 2.0,
            'debugger' => 2.0,
            'compiler' => 2.0,
            'github' => 2.0,
            'ide' => 2.0,
        ],
        'Open Source' => [
            'open source' => 3.0,
            'open-source' => 3.0,
            'linux' => 2.0,
            'apache' => 2.0,
            'maintainer' => 2.0,
            'mit license' => 2.0,
        ],
    ],
];
