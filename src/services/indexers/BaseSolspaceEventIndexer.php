<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use Carbon\Carbon;
use craft\base\Element;
use Solspace\Calendar\Elements\Event;

/**
 * Base for indexing Solspace Calendar events from one calendar.
 *
 * Calendar is optional: the plugin doesn't require it, and nothing loads this
 * class unless a project extends it (via the `custom` config key), which only
 * makes sense on sites that have Calendar installed.
 */
abstract class BaseSolspaceEventIndexer implements ElementIndexerInterface, SchedulesReindex
{
    use ChecksLayoutFields;
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
            'elementID'       => (string) $event->id,
            'calendar'        => (string) $calendar->name,
            'calendarHandle'  => (string) $calendar->handle,
            // Same as calendar/calendarHandle, so facets on section work across
            // entries and events
            'section'         => (string) $calendar->name,
            'sectionHandle'   => (string) $calendar->handle,
            'siteLanguage'    => $event->getSite()->language,
            'title'           => (string) $event->title,
            'url'             => (string) $event->getUrl(),
            'postDate'        => $postDate?->getTimestamp(),
            'startDate'       => $startDate->getTimestamp(),
            'endDate'         => $endDate->getTimestamp(),
            'allDay'          => $event->isAllDay(),
            'filterDate'      => $startDate->getTimestamp(),
            'filterYear'      => (int) $startDate->format('Y'),
            'location'        => $this->getNominatedFieldText($event, $calendar->locationFieldHandle),
            // Description goes in body so it's chunked like entry bodies
            'body'            => $this->getNominatedFieldText($event, $calendar->descriptionFieldHandle),
            'repeats'         => $event->repeats(),
            'repeatFrequency' => $event->getFrequency(),
            'occurrenceDates' => $this->getUpcomingOccurrenceDates($event, $startDate),
            'lastDate'        => $this->getLastDate($event, $startDate, $endDate)?->getTimestamp(),
        ];
    }

    /**
     * How far ahead occurrenceDates looks, as a relative date string. The
     * record is rebuilt before its indexed dates run out (see
     * getNextReindexDate()).
     */
    protected function occurrenceWindow(): string
    {
        return '+12 months';
    }

    /**
     * Maximum number of occurrenceDates per record, to keep records small.
     */
    protected function occurrenceLimit(): int
    {
        return 100;
    }

    /**
     * A repeating event's record runs out of upcoming dates as time passes,
     * so rebuild it when its last indexed occurrence starts, as long as more
     * occurrences follow. If none fall inside the window yet, look again when
     * the window has moved on.
     */
    public function getNextReindexDate(Element $element): ?\DateTimeInterface
    {
        /** @var Event $event */
        $event = $element;

        if (!$event->repeats()) {
            return null;
        }

        $startDate = $event->getStartDate();
        $endDate = $event->getEndDate();
        $dates = $this->getUpcomingOccurrenceDates($event, $startDate);
        $lastDate = $this->getLastDate($event, $startDate, $endDate);
        $now = Carbon::now('UTC');

        if ($dates === []) {
            if ($lastDate !== null && $lastDate <= $now) {
                return null;
            }

            return $now->modify($this->occurrenceWindow());
        }

        $lastIndexedStart = Carbon::createFromTimestamp(max($dates), 'UTC');
        $lastIndexedEnd = $lastIndexedStart->copy()->addSeconds($endDate->getTimestamp() - $startDate->getTimestamp());

        // Every remaining occurrence is already indexed
        if ($lastDate !== null && $lastDate <= $lastIndexedEnd) {
            return null;
        }

        return $lastIndexedStart;
    }

    /**
     * Start timestamps of the event's occurrences from today to the end of
     * the window, so front ends can filter on upcoming dates. A non-repeating
     * event has just its own start, past or not.
     *
     * Uses getOccurrences() only: getOccurrenceDatesBetween() resets a
     * select-dates event's startDate to midnight in place.
     *
     * @return int[]
     */
    private function getUpcomingOccurrenceDates(Event $event, \DateTimeInterface $startDate): array
    {
        if (!$event->repeats()) {
            return [$startDate->getTimestamp()];
        }

        $rangeStart = Carbon::now('UTC')->startOfDay();
        $rangeEnd = Carbon::now('UTC')->modify($this->occurrenceWindow());

        $dates = array_map(
            fn(Event $occurrence) => $occurrence->getStartDate()->getTimestamp(),
            $event->getOccurrences([
                'rangeStart' => $rangeStart,
                'rangeEnd'   => $rangeEnd,
                'limit'      => $this->occurrenceLimit(),
            ]),
        );

        // getOccurrences() leaves out a select-dates event's own start
        if ($event->repeatsOnSelectDates() && $startDate >= $rangeStart && $startDate <= $rangeEnd) {
            $dates[] = $startDate->getTimestamp();
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        return array_slice($dates, 0, $this->occurrenceLimit());
    }

    /**
     * When the event's final occurrence ends, or null if it repeats forever.
     */
    private function getLastDate(Event $event, \DateTimeInterface $startDate, \DateTimeInterface $endDate): ?\DateTimeInterface
    {
        if (!$event->repeats()) {
            return $endDate;
        }

        // Checked before the forever case: select-dates events have no
        // count/until, so Calendar reports them as repeating forever
        if (!$event->repeatsOnSelectDates() && $event->getUntilType() === Event::UNTIL_TYPE_FOREVER) {
            return null;
        }

        // With no rangeEnd, a finite event's occurrences run to the end
        $occurrences = $event->getOccurrences([
            'rangeStart' => Carbon::instance($startDate)->startOfDay(),
        ]);
        $last = end($occurrences);

        if ($last === false) {
            return $endDate;
        }

        $lastEnd = $last->getEndDate();

        return $lastEnd > $endDate ? $lastEnd : $endDate;
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
