<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit\Indexers;

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
