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
    use ConvertsToPlainText;

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

    /**
     * The fields every event record should have. Projects merge their own
     * fields into this in getIndexData(). Dates are Unix timestamps (UTC).
     */
    protected function getStandardEventData(Event $event): array
    {
        $calendar = $event->getCalendar();

        // Read dates before anything that computes occurrences: some Calendar
        // occurrence methods modify the event's startDate in place
        $startDate = $event->getStartDate();
        $endDate = $event->getEndDate();
        $postDate = $event->getPostDate();

        return [
            'elementID'      => (string) $event->id,
            'calendar'       => (string) $calendar->name,
            'calendarHandle' => (string) $calendar->handle,
            // Same as calendar/calendarHandle, so facets on section work across
            // entries and events
            'section'        => (string) $calendar->name,
            'sectionHandle'  => (string) $calendar->handle,
            'siteLanguage'   => $event->getSite()->language,
            'title'          => (string) $event->title,
            'url'            => (string) $event->getUrl(),
            'postDate'       => $postDate?->getTimestamp(),
            'startDate'      => $startDate->getTimestamp(),
            'endDate'        => $endDate->getTimestamp(),
            'allDay'         => $event->isAllDay(),
            'filterDate'     => $startDate->getTimestamp(),
            'filterYear'     => (int) $startDate->format('Y'),
            'location'       => $this->getNominatedFieldText($event, $calendar->locationFieldHandle),
            // Description goes in body so it's chunked like entry bodies
            'body'           => $this->getNominatedFieldText($event, $calendar->descriptionFieldHandle),
        ];
    }

    /**
     * Plain text of a field the calendar nominates (description, location),
     * or '' when the calendar doesn't nominate one or it isn't on the layout.
     */
    private function getNominatedFieldText(Event $event, ?string $fieldHandle): string
    {
        if (!$fieldHandle || $event->getFieldLayout()?->getFieldByHandle($fieldHandle) === null) {
            return '';
        }

        $value = $event->getFieldValue($fieldHandle);

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        return $this->toPlainText((string) $value);
    }
}
