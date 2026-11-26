<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Algolia\AlgoliaSearch\Configuration\SearchConfig;
use korcontrol\craftalgolia\models\Settings;

final class ClientService
{
    private Settings $settings;
    private ?SearchClient $writeClient = null;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    public function getIndex(): string
    {
        return $this->settings->indexName;
    }

    public function getWriteClient(): SearchClient
    {
        if ($this->writeClient === null) {
            $config = SearchConfig::create($this->settings->appId, $this->settings->writeApiKey);

            // From 4.55.0 the client sleeps out a 429's Retry-After (Algolia
            // sends 3600 for deleteByQuery) up to 3 times, hanging the save
            // that sent the request. Fail at once instead, as earlier clients
            // do, so callers log the error and carry on.
            if (method_exists($config, 'setMaxRateLimitRetries')) {
                $config->setMaxRateLimitRetries(0);
            }

            $this->writeClient = SearchClient::createWithConfig($config);
        }

        return $this->writeClient;
    }
}