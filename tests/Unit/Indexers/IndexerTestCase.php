<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit\Indexers;

use Carbon\Carbon;
use Codeception\Test\Unit;
use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\errors\InvalidFieldException;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Site;
use Solspace\Calendar\Elements\Event;
use Solspace\Calendar\Models\CalendarModel;
use Tests\Support\UnitTester;
use yii\log\Logger;

abstract class IndexerTestCase extends Unit
{
    protected UnitTester $tester;

    private Logger $originalLogger;

    private Logger $logger;

    protected function _before(): void
    {
        // Codeception's logger doesn't keep messages, so swap in a plain Yii
        // logger (no dispatcher) that does, for asserting warnings
        $this->originalLogger = Craft::getLogger();
        $this->logger = new Logger();
        Craft::setLogger($this->logger);
    }

    protected function _after(): void
    {
        Craft::setLogger($this->originalLogger);
    }

    /**
     * Warnings logged by the plugin ("[ALGOLIA]" prefix) during the test.
     *
     * @return string[]
     */
    protected function algoliaWarnings(): array
    {
        $warnings = array_filter(
            $this->logger->messages,
            fn(array $message) => $message[1] === Logger::LEVEL_WARNING
                && is_string($message[0])
                && str_starts_with($message[0], '[ALGOLIA]'),
        );

        return array_values(array_map(fn(array $message) => $message[0], $warnings));
    }

    /**
     * An entry type whose field layout has the given (layout) handles.
     *
     * @param string[] $fieldHandles
     */
    protected function makeEntryType(string $handle, array $fieldHandles = []): EntryType
    {
        return $this->make(EntryType::class, [
            'handle'         => $handle,
            'getFieldLayout' => $this->makeFieldLayout(false, $this->layoutFieldsFor(array_flip($fieldHandles))),
        ]);
    }

    /**
     * @param array<string, FieldInterface> $fields Fields on the layout, keyed by layout handle
     */
    protected function makeFieldLayout(bool $hasSearchableField = false, array $fields = []): FieldLayout
    {
        return $this->make(FieldLayout::class, [
            'getFieldByHandle' => function(string $handle) use ($hasSearchableField, $fields) {
                if ($handle === 'searchable' && $hasSearchableField) {
                    return new \craft\fields\PlainText();
                }
                return $fields[$handle] ?? null;
            },
        ]);
    }

    /**
     * Layout fields for the given field values: a plain text field per handle,
     * merged with any explicitly provided layout fields.
     *
     * @param array<string, mixed> $fieldValues
     * @param array<string, FieldInterface> $layoutFields
     * @return array<string, FieldInterface>
     */
    protected function layoutFieldsFor(array $fieldValues, array $layoutFields = []): array
    {
        $fields = [];
        foreach (array_keys($fieldValues) as $handle) {
            $fields[$handle] = new \craft\fields\PlainText(['handle' => $handle]);
        }
        return array_merge($fields, $layoutFields);
    }

    /**
     * getFieldValue() stub that behaves like Craft: throws for handles that
     * aren't on the element's field layout.
     *
     * @param array<string, mixed> $fieldValues
     */
    protected function fieldValueGetter(FieldLayout $layout, array $fieldValues): \Closure
    {
        return function(string $handle) use ($layout, $fieldValues) {
            if ($layout->getFieldByHandle($handle) === null) {
                throw new InvalidFieldException($handle);
            }
            return $fieldValues[$handle] ?? null;
        };
    }

    /**
     * A field whose search keywords are fixed, standing in for Matrix/CKEditor
     * fields (which return HTML keywords).
     */
    protected function makeKeywordsField(string $handle, string $keywords): FieldInterface
    {
        return $this->make(\craft\fields\PlainText::class, [
            'handle' => $handle,
            'getSearchKeywords' => $keywords,
        ]);
    }

    protected function makeCalendar(
        string $handle = 'events',
        string $name = 'Events',
        ?string $descriptionFieldHandle = null,
        ?string $locationFieldHandle = null,
    ): CalendarModel {
        $calendar = new CalendarModel();
        $calendar->id = 1;
        $calendar->handle = $handle;
        $calendar->name = $name;
        $calendar->descriptionFieldHandle = $descriptionFieldHandle;
        $calendar->locationFieldHandle = $locationFieldHandle;

        return $calendar;
    }

    /**
     * A Calendar event, built without the Calendar plugin running: the
     * calendar, site, layout and occurrences are stubbed. Dates are UTC.
     *
     * @param string[] $occurrences Start dates of the event's occurrences (any
     *     range); getOccurrences() filters them by its rangeStart/rangeEnd/limit
     *     config the way Calendar does. Ignored unless $freq is set.
     * @param array<string, mixed> $fieldValues
     */
    protected function makeEvent(
        int $id = 1,
        ?CalendarModel $calendar = null,
        bool $hasCalendar = true,
        string $title = 'Test Event',
        ?string $url = 'https://example.com/events/test-event',
        string $siteLanguage = 'en-CA',
        string $status = Element::STATUS_ENABLED,
        string $start = '2026-11-15 18:00:00',
        string $end = '2026-11-15 20:00:00',
        bool $allDay = false,
        ?string $postDate = '2026-10-01 09:00:00',
        ?string $freq = null,
        ?int $count = null,
        ?string $until = null,
        array $occurrences = [],
        array $fieldValues = [],
        bool $hasSearchableField = false,
    ): Event {
        $calendar ??= $this->makeCalendar();

        $site = new Site();
        $site->language = $siteLanguage;

        $layout = $this->makeFieldLayout($hasSearchableField, $this->layoutFieldsFor($fieldValues));

        $startDate = new Carbon($start, 'UTC');
        $duration = $startDate->diffInSeconds(new Carbon($end, 'UTC'));

        $occurrenceEvents = [];
        foreach ($freq === null ? [] : $occurrences as $occurrenceStart) {
            $occurrenceStartDate = new Carbon($occurrenceStart, 'UTC');
            $occurrenceEvents[] = $this->make(Event::class, [
                'id'        => $id,
                'startDate' => $occurrenceStartDate,
                'endDate'   => $occurrenceStartDate->copy()->addSeconds($duration),
            ]);
        }

        return $this->make(Event::class, [
            'id'               => $id,
            'calendarId'       => $hasCalendar ? $calendar->id : null,
            'title'            => $title,
            'startDate'        => $startDate,
            'endDate'          => new Carbon($end, 'UTC'),
            'allDay'           => $allDay,
            'postDate'         => $postDate === null ? null : new Carbon($postDate, 'UTC'),
            'freq'             => $freq,
            'count'            => $count,
            'until'            => $until === null ? null : new Carbon($until, 'UTC'),
            'getCalendar'      => $calendar,
            'getStatus'        => $status,
            'getSite'          => $site,
            'getFieldLayout'   => $layout,
            'getUrl'           => $url,
            'getFieldValue'    => $this->fieldValueGetter($layout, $fieldValues),
            'getOccurrences'   => fn(?array $config = null) => $this->filterOccurrences($occurrenceEvents, $config ?? []),
        ]);
    }

    /**
     * Mirrors Event::getOccurrences(): occurrences starting within
     * [rangeStart, rangeEnd] (whole days, as Calendar's RRULE expansion
     * works on dates), capped at limit.
     *
     * @param Event[] $occurrences
     * @return Event[]
     */
    private function filterOccurrences(array $occurrences, array $config): array
    {
        $rangeStart = isset($config['rangeStart']) ? (new Carbon($config['rangeStart'], 'UTC'))->startOfDay() : null;
        $rangeEnd = isset($config['rangeEnd']) ? (new Carbon($config['rangeEnd'], 'UTC'))->endOfDay() : null;

        $matching = array_values(array_filter(
            $occurrences,
            fn(Event $occurrence) => (!$rangeStart || $occurrence->startDate >= $rangeStart)
                && (!$rangeEnd || $occurrence->startDate <= $rangeEnd),
        ));

        return isset($config['limit']) ? array_slice($matching, 0, $config['limit']) : $matching;
    }
}
