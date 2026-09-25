<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Settings model for the Craft Algolia plugin.
 *
 * Stores configuration for Algolia connection and validates completeness.
 * Values can be set via config/algolia.php or environment variables.
 */
final class Settings extends Model
{
    /**
     * Algolia application ID
     */
    public string $appId = '';

    /**
     * Algolia write API key (admin key with write permissions)
     */
    public string $writeApiKey = '';

    /**
     * Algolia index name where documents will be stored
     */
    public string $indexName = '';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // Fall back to environment variables if not explicitly set
        if ($this->appId === '') {
            $this->appId = (string) App::env('ALGOLIA_APP_ID');
        }

        if ($this->writeApiKey === '') {
            $this->writeApiKey = (string) App::env('ALGOLIA_WRITE_API_KEY');
        }

        if ($this->indexName === '') {
            $this->indexName = (string) App::env('ALGOLIA_INDEX_NAME');
        }
    }

    /**
     * Check if all required Algolia credentials are configured.
     *
     * @return bool True if appId, writeApiKey, and indexName are all non-empty strings
     */
    public function isConfigured(): bool
    {
        return $this->appId !== ''
            && $this->writeApiKey !== ''
            && $this->indexName !== '';
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['appId', 'writeApiKey', 'indexName'], 'required'],
            [['appId', 'writeApiKey', 'indexName'], 'string'],
        ];
    }
}