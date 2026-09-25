<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit\Indexers;

use craft\elements\Asset;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use korcontrol\craftalgolia\services\indexers\ConfigDrivenEntryIndexer;

class ConfigDrivenEntryIndexerTest extends IndexerTestCase
{
    private function makeEntry(
        int $id = 1,
        string $sectionHandle = 'news',
        string $sectionName = 'News',
        string $entryTypeHandle = 'news',
        string $title = 'Test Entry',
        string $url = 'https://example.com/test',
        string $siteLanguage = 'en-CA',
        ?\DateTime $postDate = null,
        array $fieldValues = [],
        bool $hasSearchableField = false,
    ): Entry {
        $section = new Section();
        $section->handle = $sectionHandle;
        $section->name = $sectionName;

        $entryType = new EntryType();
        $entryType->handle = $entryTypeHandle;

        $site = new Site();
        $site->language = $siteLanguage;

        return $this->make(Entry::class, [
            'id'             => $id,
            'title'          => $title,
            'postDate'       => $postDate ?? new \DateTime('2026-01-15'),
            'getStatus'      => Entry::STATUS_LIVE,
            'getSection'     => $section,
            'getType'        => $entryType,
            'getSite'        => $site,
            'getFieldLayout' => $this->makeFieldLayout($hasSearchableField),
            'getUrl'         => $url,
            'getFieldValue'  => fn(string $handle) => $fieldValues[$handle] ?? null,
        ]);
    }

    // supports()

    public function test_supports_matching_section(): void
    {
        $this->assertTrue(
            (new ConfigDrivenEntryIndexer('news'))->supports($this->makeEntry(sectionHandle: 'news'))
        );
    }

    public function test_does_not_support_different_section(): void
    {
        $this->assertFalse(
            (new ConfigDrivenEntryIndexer('news'))->supports($this->makeEntry(sectionHandle: 'pages'))
        );
    }

    public function test_does_not_support_non_entry_element(): void
    {
        $asset = $this->make(Asset::class, [
            'getFieldLayout' => $this->makeFieldLayout(),
            'getVolume'      => null,
        ]);

        $this->assertFalse((new ConfigDrivenEntryIndexer('news'))->supports($asset));
    }

    // shouldIndex()

    public function test_should_not_index_when_not_live(): void
    {
        $entry = $this->make(Entry::class, [
            'getStatus'      => 'disabled',
            'getFieldLayout' => $this->makeFieldLayout(),
        ]);

        $this->assertFalse((new ConfigDrivenEntryIndexer('news'))->shouldIndex($entry));
    }

    public function test_should_index_when_live_and_no_searchable_field(): void
    {
        $this->assertTrue(
            (new ConfigDrivenEntryIndexer('news'))->shouldIndex($this->makeEntry())
        );
    }

    public function test_should_not_index_when_searchable_field_is_null(): void
    {
        $entry = $this->makeEntry(
            hasSearchableField: true,
            fieldValues: ['searchable' => null]
        );

        $this->assertFalse((new ConfigDrivenEntryIndexer('news'))->shouldIndex($entry));
    }

    public function test_should_not_index_when_searchable_is_false(): void
    {
        $entry = $this->makeEntry(hasSearchableField: true, fieldValues: ['searchable' => false]);

        $this->assertFalse((new ConfigDrivenEntryIndexer('news'))->shouldIndex($entry));
    }

    public function test_should_index_when_searchable_is_true(): void
    {
        $entry = $this->makeEntry(hasSearchableField: true, fieldValues: ['searchable' => true]);

        $this->assertTrue((new ConfigDrivenEntryIndexer('news'))->shouldIndex($entry));
    }

    // getIndexData()

    public function test_get_index_data_returns_standard_fields(): void
    {
        $postDate = new \DateTime('2026-01-15');
        $entry = $this->makeEntry(
            id: 42,
            title: 'Hello World',
            url: 'https://example.com/hello',
            postDate: $postDate,
            entryTypeHandle: 'news',
            siteLanguage: 'en-CA',
        );

        $data = (new ConfigDrivenEntryIndexer('news', []))->getIndexData($entry);

        $this->assertSame('42', $data['objectID']);
        $this->assertSame('42', $data['elementID']);
        $this->assertSame('Hello World', $data['title']);
        $this->assertSame('https://example.com/hello', $data['url']);
        $this->assertSame('News', $data['section']);
        $this->assertSame('news', $data['sectionHandle']);
        $this->assertSame('news', $data['entryType']);
        $this->assertSame('en-CA', $data['siteLanguage']);
        $this->assertSame($postDate->getTimestamp(), $data['postDate']);
        $this->assertSame($postDate->getTimestamp(), $data['filterDate']);
        $this->assertSame(2026, $data['filterYear']);
        $this->assertArrayHasKey('body', $data);
    }

    public function test_body_is_empty_when_no_body_fields_configured(): void
    {
        $entry = $this->makeEntry();
        $data = (new ConfigDrivenEntryIndexer('news', ['bodyFields' => []]))->getIndexData($entry);

        $this->assertSame('', $data['body']);
    }

    public function test_extra_fields_are_included(): void
    {
        $entry = $this->makeEntry(fieldValues: [
            'cisionId' => '12345',
            'pdfLink'  => 'https://example.com/doc.pdf',
        ]);

        $data = (new ConfigDrivenEntryIndexer('news', [
            'extraFields' => [
                'cisionId' => 'cisionId',
                'pdfLink'  => 'pdfLink',
            ],
        ]))->getIndexData($entry);

        $this->assertSame('12345', $data['cisionId']);
        $this->assertSame('https://example.com/doc.pdf', $data['pdfLink']);
    }

    public function test_extra_fields_do_not_overwrite_standard_fields(): void
    {
        $entry = $this->makeEntry(id: 42, fieldValues: ['objectID' => 'hacked']);

        $data = (new ConfigDrivenEntryIndexer('news', [
            'extraFields' => ['objectID' => 'objectID'],
        ]))->getIndexData($entry);

        $this->assertSame('42', $data['objectID']);
    }

    public function test_custom_date_field_is_used_for_filter_date(): void
    {
        $customDate = new \DateTime('2025-06-01');
        $entry = $this->makeEntry(fieldValues: ['eventDate' => $customDate]);

        $data = (new ConfigDrivenEntryIndexer('news', [
            'dateField' => 'eventDate',
        ]))->getIndexData($entry);

        $this->assertSame($customDate->getTimestamp(), $data['filterDate']);
        $this->assertSame(2025, $data['filterYear']);
    }
}
