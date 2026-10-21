<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit\Indexers;

use craft\elements\Asset;
use craft\elements\Entry;
use craft\models\Site;
use craft\models\Volume;
use korcontrol\craftalgolia\services\indexers\ConfigDrivenAssetIndexer;

class ConfigDrivenAssetIndexerTest extends IndexerTestCase
{
    private function makeAsset(
        int $id = 1,
        string $volumeHandle = 'documents',
        string $volumeName = 'Documents',
        string $title = 'Test Asset',
        string $url = 'https://example.com/test.pdf',
        string $siteLanguage = 'en-CA',
        ?\DateTime $dateUpdated = null,
        array $fieldValues = [],
        bool $hasSearchableField = false,
    ): Asset {
        $volume = new Volume();
        $volume->handle = $volumeHandle;
        $volume->name = $volumeName;

        $site = new Site();
        $site->language = $siteLanguage;

        // The layout has a field for every value given
        $layout = $this->makeFieldLayout($hasSearchableField, $this->layoutFieldsFor($fieldValues));

        return $this->make(Asset::class, [
            'id'             => $id,
            'title'          => $title,
            'dateUpdated'    => $dateUpdated ?? new \DateTime('2026-01-15'),
            'getVolume'      => $volume,
            'getSite'        => $site,
            'getFieldLayout' => $layout,
            'getUrl'         => $url,
            'getFieldValue'  => $this->fieldValueGetter($layout, $fieldValues),
        ]);
    }

    // supports()

    public function test_supports_matching_volume(): void
    {
        $this->assertTrue(
            (new ConfigDrivenAssetIndexer('documents'))->supports($this->makeAsset(volumeHandle: 'documents'))
        );
    }

    public function test_does_not_support_different_volume(): void
    {
        $this->assertFalse(
            (new ConfigDrivenAssetIndexer('documents'))->supports($this->makeAsset(volumeHandle: 'images'))
        );
    }

    public function test_does_not_support_non_asset_element(): void
    {
        $entry = $this->make(Entry::class, [
            'getStatus'      => Entry::STATUS_LIVE,
            'getFieldLayout' => $this->makeFieldLayout(),
            'getSection'     => null,
        ]);

        $this->assertFalse((new ConfigDrivenAssetIndexer('documents'))->supports($entry));
    }

    // shouldIndex()

    public function test_should_index_when_no_searchable_field(): void
    {
        $this->assertTrue(
            (new ConfigDrivenAssetIndexer('documents'))->shouldIndex($this->makeAsset())
        );
    }

    public function test_should_not_index_when_searchable_field_is_null(): void
    {
        $asset = $this->makeAsset(
            hasSearchableField: true,
            fieldValues: ['searchable' => null]
        );

        $this->assertFalse((new ConfigDrivenAssetIndexer('documents'))->shouldIndex($asset));
    }

    public function test_should_not_index_when_searchable_is_false(): void
    {
        $asset = $this->makeAsset(hasSearchableField: true, fieldValues: ['searchable' => false]);

        $this->assertFalse((new ConfigDrivenAssetIndexer('documents'))->shouldIndex($asset));
    }

    public function test_should_index_when_searchable_is_true(): void
    {
        $asset = $this->makeAsset(hasSearchableField: true, fieldValues: ['searchable' => true]);

        $this->assertTrue((new ConfigDrivenAssetIndexer('documents'))->shouldIndex($asset));
    }

    // getIndexData()

    public function test_get_index_data_returns_standard_fields(): void
    {
        $dateUpdated = new \DateTime('2026-03-01');
        $asset = $this->makeAsset(
            id: 7,
            title: 'My Doc',
            url: 'https://example.com/doc.pdf',
            dateUpdated: $dateUpdated,
            siteLanguage: 'en-CA',
        );

        $data = (new ConfigDrivenAssetIndexer('documents', []))->getIndexData($asset);

        $this->assertSame('7', $data['objectID']);
        $this->assertSame('7', $data['elementID']);
        $this->assertSame('My Doc', $data['title']);
        $this->assertSame('https://example.com/doc.pdf', $data['url']);
        $this->assertSame('Documents', $data['section']);
        $this->assertSame('documents', $data['sectionHandle']);
        $this->assertSame('en-CA', $data['siteLanguage']);
        $this->assertSame($dateUpdated->getTimestamp(), $data['updatedDate']);
        $this->assertSame($dateUpdated->getTimestamp(), $data['filterDate']);
        $this->assertSame(2026, $data['filterYear']);
    }

    public function test_extra_fields_are_included(): void
    {
        $asset = $this->makeAsset(fieldValues: ['documentType' => 'report']);

        $data = (new ConfigDrivenAssetIndexer('documents', [
            'extraFields' => ['documentType' => 'documentType'],
        ]))->getIndexData($asset);

        $this->assertSame('report', $data['documentType']);
    }

    public function test_extra_fields_do_not_overwrite_standard_fields(): void
    {
        $asset = $this->makeAsset(id: 7, fieldValues: ['objectID' => 'hacked']);

        $data = (new ConfigDrivenAssetIndexer('documents', [
            'extraFields' => ['objectID' => 'objectID'],
        ]))->getIndexData($asset);

        $this->assertSame('7', $data['objectID']);
    }

    public function test_extra_field_not_on_layout_is_skipped_without_error(): void
    {
        $asset = $this->makeAsset(fieldValues: ['documentType' => 'report']);

        $data = (new ConfigDrivenAssetIndexer('documents', [
            'extraFields' => [
                'documentType' => 'documentType',
                'missing'      => 'missing',
            ],
        ]))->getIndexData($asset);

        $this->assertSame('report', $data['documentType']);
        $this->assertArrayNotHasKey('missing', $data);
    }

    public function test_warns_when_extra_field_is_not_on_volume_layout(): void
    {
        $asset = $this->makeAsset(fieldValues: ['documentType' => 'report']);

        (new ConfigDrivenAssetIndexer('documents', [
            'extraFields' => [
                'documentType' => 'documentType',
                'missing'      => 'missing',
            ],
        ]))->getIndexData($asset);

        $warnings = $this->algoliaWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('"missing" (extraFields)', $warnings[0]);
        $this->assertStringContainsString('volume "documents"', $warnings[0]);
    }
}
