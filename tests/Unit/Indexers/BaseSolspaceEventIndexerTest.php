<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit\Indexers;

use craft\base\Element;
use craft\elements\Entry;
use korcontrol\craftalgolia\services\indexers\BaseSolspaceEventIndexer;

class BaseSolspaceEventIndexerTest extends IndexerTestCase
{
    private function indexer(string $calendarHandle = 'events'): BaseSolspaceEventIndexer
    {
        return new TestEventIndexer($calendarHandle);
    }

    // supports()

    public function test_supports_event_in_matching_calendar(): void
    {
        $this->assertTrue($this->indexer('events')->supports($this->makeEvent()));
    }

    public function test_does_not_support_event_in_different_calendar(): void
    {
        $event = $this->makeEvent(calendar: $this->makeCalendar('other', 'Other'));

        $this->assertFalse($this->indexer('events')->supports($event));
    }

    public function test_does_not_support_event_without_calendar(): void
    {
        $this->assertFalse($this->indexer('events')->supports($this->makeEvent(hasCalendar: false)));
    }

    public function test_does_not_support_non_event_element(): void
    {
        $this->assertFalse($this->indexer('events')->supports($this->make(Entry::class)));
    }

    // shouldIndex()

    public function test_should_index_when_enabled_and_no_searchable_field(): void
    {
        $this->assertTrue($this->indexer()->shouldIndex($this->makeEvent()));
    }

    public function test_should_not_index_when_disabled(): void
    {
        // Also covers disabled-for-site: getStatus() reports both as disabled
        $this->assertFalse($this->indexer()->shouldIndex($this->makeEvent(status: Element::STATUS_DISABLED)));
    }

    public function test_should_not_index_when_searchable_is_false(): void
    {
        $event = $this->makeEvent(fieldValues: ['searchable' => false], hasSearchableField: true);

        $this->assertFalse($this->indexer()->shouldIndex($event));
    }

    public function test_should_not_index_when_searchable_is_null(): void
    {
        $event = $this->makeEvent(fieldValues: ['searchable' => null], hasSearchableField: true);

        $this->assertFalse($this->indexer()->shouldIndex($event));
    }

    public function test_should_index_when_searchable_is_true(): void
    {
        $event = $this->makeEvent(fieldValues: ['searchable' => true], hasSearchableField: true);

        $this->assertTrue($this->indexer()->shouldIndex($event));
    }

    public function test_should_not_index_disabled_event_even_when_searchable(): void
    {
        $event = $this->makeEvent(status: Element::STATUS_DISABLED, fieldValues: ['searchable' => true], hasSearchableField: true);

        $this->assertFalse($this->indexer()->shouldIndex($event));
    }

    // getStandardEventData()

    private static function ts(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
    }

    public function test_standard_data_for_single_event(): void
    {
        $data = $this->indexer()->standardData($this->makeEvent(
            id: 42,
            calendar: $this->makeCalendar('events', 'Events'),
            title: 'Evening Talk',
            url: 'https://example.com/events/evening-talk',
            siteLanguage: 'fr-CA',
            start: '2026-11-15 18:00:00',
            end: '2026-11-15 20:00:00',
            postDate: '2026-10-01 09:00:00',
        ));

        $this->assertSame([
            'elementID'      => '42',
            'calendar'       => 'Events',
            'calendarHandle' => 'events',
            'section'        => 'Events',
            'sectionHandle'  => 'events',
            'siteLanguage'   => 'fr-CA',
            'title'          => 'Evening Talk',
            'url'            => 'https://example.com/events/evening-talk',
            'postDate'       => self::ts('2026-10-01 09:00:00'),
            'startDate'      => self::ts('2026-11-15 18:00:00'),
            'endDate'        => self::ts('2026-11-15 20:00:00'),
            'allDay'         => false,
            'filterDate'     => self::ts('2026-11-15 18:00:00'),
            'filterYear'     => 2026,
            'location'       => '',
            'body'           => '',
        ], $this->standardFieldsOf($data));
    }

    public function test_all_day_event(): void
    {
        $data = $this->indexer()->standardData($this->makeEvent(
            start: '2026-12-01 00:00:00',
            end: '2026-12-03 23:59:59',
            allDay: true,
        ));

        $this->assertTrue($data['allDay']);
        $this->assertSame(self::ts('2026-12-03 23:59:59'), $data['endDate']);
    }

    public function test_null_post_date_and_url(): void
    {
        $data = $this->indexer()->standardData($this->makeEvent(url: null, postDate: null));

        $this->assertNull($data['postDate']);
        $this->assertSame('', $data['url']);
    }

    public function test_body_and_location_come_from_calendar_nominated_fields(): void
    {
        $event = $this->makeEvent(
            calendar: $this->makeCalendar(descriptionFieldHandle: 'description', locationFieldHandle: 'venue'),
            fieldValues: [
                'description' => '<h2>Talk</h2><p>An <em>evening</em>&nbsp;talk.</p>',
                'venue'       => 'Main Hall',
            ],
        );

        $data = $this->indexer()->standardData($event);

        $this->assertSame('Talk An evening talk.', $data['body']);
        $this->assertSame('Main Hall', $data['location']);
    }

    public function test_nominated_fields_missing_from_layout_give_empty_strings(): void
    {
        $event = $this->makeEvent(
            calendar: $this->makeCalendar(descriptionFieldHandle: 'description', locationFieldHandle: 'venue'),
        );

        $data = $this->indexer()->standardData($event);

        $this->assertSame('', $data['body']);
        $this->assertSame('', $data['location']);
    }

    /**
     * The non-recurrence part of the record (recurrence fields are tested
     * separately).
     */
    private function standardFieldsOf(array $data): array
    {
        return array_diff_key($data, array_flip(['repeats', 'repeatFrequency', 'occurrenceDates', 'lastDate']));
    }
}

/**
 * Minimal concrete indexer: projects implement getIndexData() themselves.
 */
final class TestEventIndexer extends BaseSolspaceEventIndexer
{
    public function __construct(private string $handle)
    {
    }

    public function calendarHandle(): string
    {
        return $this->handle;
    }

    public function getIndexData(\craft\base\Element $element): array
    {
        return $this->getStandardEventData($element);
    }

    /** Exposes the protected helper to tests. */
    public function standardData(\Solspace\Calendar\Elements\Event $event): array
    {
        return $this->getStandardEventData($event);
    }
}
