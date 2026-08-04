<?php

return [

    /*
     * Page components live in `resources/js/Pages` (capital P), as the specification
     * lays out the tree. The package default points at a lowercase `js/pages`, which
     * would make `assertInertia` report every existing component as missing.
     */
    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/Pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
