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
        ?array $layoutFields = null,
    ): Entry {
        $section = new Section();
        $section->handle = $sectionHandle;
        $section->name = $sectionName;

        $entryType = new EntryType();
        $entryType->handle = $entryTypeHandle;

        $site = new Site();
        $site->language = $siteLanguage;

        // By default the layout has a field for every value given
        $layout = $this->makeFieldLayout(
            $hasSearchableField,
            $layoutFields ?? $this->layoutFieldsFor($fieldValues),
        );

        return $this->make(Entry::class, [
            'id'             => $id,
            'title'          => $title,
            'postDate'       => $postDate ?? new \DateTime('2026-01-15'),
            'getStatus'      => Entry::STATUS_LIVE,
            'getSection'     => $section,
            'getType'        => $entryType,
            'getSite'        => $site,
            'getFieldLayout' => $layout,
            'getUrl'         => $url,
            'getFieldValue'  => $this->fieldValueGetter($layout, $fieldValues),
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

    public function test_custom_date_field_missing_from_layout_gives_null_filter_date(): void
    {
        $entry = $this->makeEntry(layoutFields: []);

        $data = (new ConfigDrivenEntryIndexer('news', [
            'dateField' => 'eventDate',
        ]))->getIndexData($entry);

        $this->assertNull($data['filterDate']);
        $this->assertNull($data['filterYear']);
    }

    // Body fields (search mode) — layout handles

    public function test_body_uses_layout_handle_override(): void
    {
        // An `editorComponents` field added to the layout with the handle `body`:
        // only the layout handle resolves on the entry.
        $entry = $this->makeEntry(
            fieldValues: ['body' => 'value'],
            layoutFields: ['body' => $this->makeKeywordsField('body', 'Body keywords')],
        );

        $data = (new ConfigDrivenEntryIndexer('news', [
            'bodyFields' => ['body'],
        ]))->getIndexData($entry);

        $this->assertSame('Body keywords', $data['body']);
    }

    public function test_body_field_not_on_layout_is_skipped_without_error(): void
    {
        // The global handle of an overridden field isn't on the layout, so
        // getFieldValue() would throw; it must be skipped instead.
        $entry = $this->makeEntry(
            fieldValues: ['body' => 'value'],
            layoutFields: ['body' => $this->makeKeywordsField('body', 'Body keywords')],
        );

        $data = (new ConfigDrivenEntryIndexer('news', [
            'bodyFields' => ['editorComponents', 'body'],
        ]))->getIndexData($entry);

        $this->assertSame('Body keywords', $data['body']);
    }

    // Body fields (plaintext mode)

    public function test_plaintext_body_field_not_on_layout_is_skipped_without_error(): void
    {
        $entry = $this->makeEntry(fieldValues: ['summary' => 'A summary']);

        $data = (new ConfigDrivenEntryIndexer('news', [
            'bodyFields' => ['missing', 'summary'],
            'bodyMode'   => 'plaintext',
        ]))->getIndexData($entry);

        $this->assertSame('A summary', $data['body']);
    }

    // Extra fields

    public function test_extra_field_not_on_layout_is_skipped_without_error(): void
    {
        $entry = $this->makeEntry(fieldValues: ['cisionId' => '12345']);

        $data = (new ConfigDrivenEntryIndexer('news', [
            'extraFields' => [
                'cisionId' => 'cisionId',
                'missing'  => 'missing',
            ],
        ]))->getIndexData($entry);

        $this->assertSame('12345', $data['cisionId']);
        $this->assertArrayNotHasKey('missing', $data);
    }

    // Plain text conversion

    public function test_body_text_from_adjacent_elements_does_not_run_together(): void
    {
        $entry = $this->makeEntry(
            fieldValues: ['body' => 'value'],
            layoutFields: [
                'body' => $this->makeKeywordsField('body', '<h2>Headline 1</h2><h2>Headline 2</h2><p>First.</p><p>Second</p>'),
            ],
        );

        $data = (new ConfigDrivenEntryIndexer('news', [
            'bodyFields' => ['body'],
        ]))->getIndexData($entry);

        $this->assertSame('Headline 1 Headline 2 First. Second', $data['body']);
    }

    public function test_body_strips_soft_hyphens_and_normalises_spaces(): void
    {
        $entry = $this->makeEntry(
            fieldValues: ['body' => 'value'],
            layoutFields: [
                'body' => $this->makeKeywordsField('body', "<p>Cus\u{00AD}to\u{00AD}di\u{00AD}an&nbsp;euismod &amp; more</p>"),
            ],
        );

        $data = (new ConfigDrivenEntryIndexer('news', [
            'bodyFields' => ['body'],
        ]))->getIndexData($entry);

        $this->assertSame('Custodian euismod & more', $data['body']);
    }
}
