<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'jquery' => [
        'version' => '3.7.1',
    ],
    '@kurkle/color' => [
        'version' => '0.3.4',
    ],
    '@fortawesome/fontawesome-free/css/fontawesome.min.css' => [
        'version' => '6.7.2',
        'type' => 'css',
    ],
    'foundation-sites' => [
        'version' => '6.9.0',
    ],
    '@fortawesome/fontawesome-free' => [
        'version' => '6.7.2',
    ],
    'chart.js' => [
        'version' => '4.4.8',
    ],
    'moment' => [
        'version' => '2.30.1',
    ],
    'chartjs-adapter-moment' => [
        'version' => '1.0.1',
    ],
    'foundation-sites/dist/css/foundation-float.min.css' => [
        'version' => '6.9.0',
        'type' => 'css',
    ],
    '@fortawesome/fontawesome-free/css/all.min.css' => [
        'version' => '6.7.2',
        'type' => 'css',
    ],
];
