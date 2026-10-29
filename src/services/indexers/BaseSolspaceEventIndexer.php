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
        /** @var Event $event */
        $event = $element;

        // Events are only ever enabled or disabled (no pending/expired), and
        // getStatus() also reports disabled-for-site as disabled. postDate
        // doesn't hide events in Calendar, so it doesn't gate indexing either.
        if ($event->getStatus() !== Element::STATUS_ENABLED) {
            return false;
        }

        if ($event->getFieldLayout()?->getFieldByHandle('searchable') === null) {
            return true;
        }

        $searchable = $event->getFieldValue('searchable');

        if ($searchable === null) {
            return false;
        }

        return (bool) $searchable;
    }

    protected function getStandardEventData(Event $event): array
    {
        return [];
    }
}
