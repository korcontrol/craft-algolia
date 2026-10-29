<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use craft\base\Element;
use Solspace\Calendar\Elements\Event;

/**
 * Base for indexing Solspace Calendar events from one calendar.
 *
 * Calendar is optional: the plugin doesn't require it, and nothing loads this
 * class unless a project extends it (via the `custom` config key), which only
 * makes sense on sites that have Calendar installed.
 */
abstract class BaseSolspaceEventIndexer implements ElementIndexerInterface
{
    abstract public function calendarHandle(): string;

    public function supports(Element $element): bool
    {
        return $element instanceof Event
            && $element->calendarId !== null
            && $element->getCalendar()->handle === $this->calendarHandle();
    }

    public function shouldIndex(Element $element): bool
    {
        return true;
    }

    protected function getStandardEventData(Event $event): array
    {
        return [];
    }
}
