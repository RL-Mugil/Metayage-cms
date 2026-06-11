<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Page components live in resources/js/pages (lowercase), not the package
    | default resources/js/Pages. Linux CI is case-sensitive, so the testing
    | view-finder must be pointed at the real directory.
    |
    */

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [
            resource_path('js/pages'),
        ],
        'page_extensions' => [
            'vue', 'svelte', 'js', 'jsx', 'ts', 'tsx',
        ],
    ],

];
