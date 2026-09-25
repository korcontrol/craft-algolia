<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services;

use Algolia\AlgoliaSearch\Api\SearchClient;
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
            $this->writeClient = SearchClient::create(
                $this->settings->appId,
                $this->settings->writeApiKey
            );
        }

        return $this->writeClient;
    }
}