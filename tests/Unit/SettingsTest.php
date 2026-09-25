<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit;

use Codeception\Test\Unit;
use korcontrol\craftalgolia\models\Settings;
use Tests\Support\UnitTester;

final class SettingsTest extends Unit
{
    protected UnitTester $tester;

    public function test_is_configured_returns_true_when_all_credentials_set(): void
    {
        $settings = new Settings();
        $settings->appId = 'test-app-id';
        $settings->writeApiKey = 'test-write-key';
        $settings->indexName = 'test-index';

        $this->assertTrue($settings->isConfigured());
    }

    public function test_is_configured_returns_false_when_app_id_missing(): void
    {
        $settings = new Settings();
        $settings->writeApiKey = 'test-write-key';
        $settings->indexName = 'test-index';

        $this->assertFalse($settings->isConfigured());
    }

    public function test_is_configured_returns_false_when_write_api_key_missing(): void
    {
        $settings = new Settings();
        $settings->appId = 'test-app-id';
        $settings->indexName = 'test-index';

        $this->assertFalse($settings->isConfigured());
    }

    public function test_is_configured_returns_false_when_index_name_missing(): void
    {
        $settings = new Settings();
        $settings->appId = 'test-app-id';
        $settings->writeApiKey = 'test-write-key';

        $this->assertFalse($settings->isConfigured());
    }

    // Tests covering Plugin::init() behaviour — credentials must come from config/algolia.php
    // via setAttributes(), not from hardcoded env var fallbacks in Settings::init().

    public function test_set_attributes_maps_config_file_values_to_settings(): void
    {
        $config = [
            'appId'       => 'config-app-id',
            'writeApiKey' => 'config-write-key',
            'indexName'   => 'config-index',
        ];

        $settings = new Settings();
        $settings->setAttributes($config, false);

        $this->assertSame('config-app-id', $settings->appId);
        $this->assertSame('config-write-key', $settings->writeApiKey);
        $this->assertSame('config-index', $settings->indexName);
    }

    public function test_set_attributes_from_config_results_in_configured_settings(): void
    {
        $config = [
            'appId'       => 'config-app-id',
            'writeApiKey' => 'config-write-key',
            'indexName'   => 'config-index',
        ];

        $settings = new Settings();
        $settings->setAttributes($config, false);

        $this->assertTrue($settings->isConfigured());
    }

    public function test_set_attributes_with_custom_env_var_name_configures_correctly(): void
    {
        // Simulates a project that maps a non-standard env var name in config/algolia.php,
        // e.g. 'writeApiKey' => App::env('ALGOLIA_ADMIN_API_KEY')
        // The resolved value should still be accepted regardless of the original env var name.
        $config = [
            'appId'       => 'my-app-id',
            'writeApiKey' => 'resolved-from-admin-api-key',
            'indexName'   => 'my-index',
        ];

        $settings = new Settings();
        $settings->setAttributes($config, false);

        $this->assertTrue($settings->isConfigured());
        $this->assertSame('resolved-from-admin-api-key', $settings->writeApiKey);
    }

    public function test_set_attributes_with_empty_values_results_in_not_configured(): void
    {
        $config = [
            'appId'       => '',
            'writeApiKey' => '',
            'indexName'   => '',
        ];

        $settings = new Settings();
        $settings->setAttributes($config, false);

        $this->assertFalse($settings->isConfigured());
    }
}