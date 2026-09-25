<?php
/**
 * Craft Algolia plugin configuration.
 *
 * Copy this file to your project's config/ directory as algolia.php
 * Values can use environment variables via App::env().
 *
 * @see \korcontrol\craftalgolia\models\Settings
 */

use craft\helpers\App;

return [
    'appId' => App::env('ALGOLIA_APP_ID') ?? '',
    'writeApiKey' => App::env('ALGOLIA_WRITE_API_KEY') ?? '',
    'indexName' => App::env('ALGOLIA_INDEX_NAME') ?? '',
];