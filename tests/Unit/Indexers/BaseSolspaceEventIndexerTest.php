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
