<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit;

use Codeception\Test\Unit;
use korcontrol\craftalgolia\models\Settings;
use korcontrol\craftalgolia\services\ClientService;
use Tests\Support\UnitTester;

final class ClientServiceTest extends Unit
{
    protected UnitTester $tester;

    private function makeConfiguredSettings(): Settings
    {
        $settings = new Settings();
        $settings->appId = 'test-app-id';
        $settings->writeApiKey = 'test-write-key';
        $settings->indexName = 'test-index';

        return $settings;
    }

    public function test_is_configured_delegates_to_settings(): void
    {
        $service = new ClientService($this->makeConfiguredSettings());

        $this->assertTrue($service->isConfigured());
    }

    public function test_is_configured_returns_false_when_settings_incomplete(): void
    {
        $settings = new Settings();
        $settings->appId = '';
        $settings->writeApiKey = '';
        $settings->indexName = '';

        $this->assertFalse((new ClientService($settings))->isConfigured());
    }

    public function test_get_index_returns_index_name_from_settings(): void
    {
        $service = new ClientService($this->makeConfiguredSettings());

        $this->assertSame('test-index', $service->getIndex());
    }

    public function test_get_write_client_returns_search_client(): void
    {
        $service = new ClientService($this->makeConfiguredSettings());

        $this->assertInstanceOf(
            \Algolia\AlgoliaSearch\Api\SearchClient::class,
            $service->getWriteClient()
        );
    }

    public function test_write_client_fails_on_the_first_rate_limit_response(): void
    {
        // Client 4.55+ otherwise sleeps out Retry-After (up to 3600s) on a 429,
        // up to 3 times, which hangs the save that triggered the request
        $client = (new ClientService($this->makeConfiguredSettings()))->getWriteClient();

        $this->assertSame(0, $client->getClientConfig()->getMaxRateLimitRetries());
    }
}
