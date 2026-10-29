<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit\Indexers;

use Carbon\Carbon;
use craft\base\Element;
use craft\elements\Entry;
use korcontrol\craftalgolia\services\indexers\BaseSolspaceEventIndexer;

class BaseSolspaceEventIndexerTest extends IndexerTestCase
{
    protected function _before(): void
    {
        parent::_before();
        Carbon::setTestNow(new Carbon('2026-10-29 12:00:00', 'UTC'));
    }

    protected function _after(): void
    {
        Carbon::setTestNow();
        parent::_after();
    }

    private function indexer(string $calendarHandle = 'events', ?int $occurrenceLimit = null): BaseSolspaceEventIndexer
    {
        return new TestEventIndexer($calendarHandle, $occurrenceLimit);
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

    // Recurrence fields

    /**
     * @return string[] Monthly start dates on the 10th at 09:00
     */
    private static function monthlyOn10th(string $from, int $months): array
    {
        $dates = [];
        $date = new Carbon($from . ' 09:00:00', 'UTC');
        for ($i = 0; $i < $months; $i++) {
            $dates[] = $date->copy()->addMonthsNoOverflow($i)->format('Y-m-d H:i:s');
        }
        return $dates;
    }

    public function test_non_repeating_event_recurrence_fields(): void
    {
        $data = $this->indexer()->standardData($this->makeEvent(start: '2026-11-15 18:00:00', end: '2026-11-15 20:00:00'));

        $this->assertFalse($data['repeats']);
        $this->assertNull($data['repeatFrequency']);
        $this->assertSame([self::ts('2026-11-15 18:00:00')], $data['occurrenceDates']);
        $this->assertSame(self::ts('2026-11-15 20:00:00'), $data['lastDate']);
    }

    public function test_weekly_event_with_count(): void
    {
        $data = $this->indexer()->standardData($this->makeEvent(
            start: '2026-11-03 10:00:00',
            end: '2026-11-03 11:00:00',
            freq: 'WEEKLY',
            count: 6,
            occurrences: ['2026-11-03 10:00:00', '2026-11-10 10:00:00', '2026-11-17 10:00:00', '2026-11-24 10:00:00', '2026-12-01 10:00:00', '2026-12-08 10:00:00'],
        ));

        $this->assertTrue($data['repeats']);
        $this->assertSame('WEEKLY', $data['repeatFrequency']);
        $this->assertSame(
            array_map(self::ts(...), ['2026-11-03 10:00:00', '2026-11-10 10:00:00', '2026-11-17 10:00:00', '2026-11-24 10:00:00', '2026-12-01 10:00:00', '2026-12-08 10:00:00']),
            $data['occurrenceDates'],
        );
        $this->assertSame(self::ts('2026-12-08 11:00:00'), $data['lastDate']);
    }

    public function test_until_event_last_date_is_end_of_last_occurrence_not_until(): void
    {
        // Calendar has already dropped the 2026-12-24 exception from its occurrences
        $data = $this->indexer()->standardData($this->makeEvent(
            start: '2026-12-10 14:00:00',
            end: '2026-12-10 15:00:00',
            freq: 'WEEKLY',
            until: '2027-01-31 23:59:59',
            occurrences: ['2026-12-10 14:00:00', '2026-12-17 14:00:00', '2026-12-31 14:00:00', '2027-01-07 14:00:00', '2027-01-28 14:00:00'],
        ));

        $this->assertSame(self::ts('2027-01-28 15:00:00'), $data['lastDate']);
        $this->assertNotContains(self::ts('2026-12-24 14:00:00'), $data['occurrenceDates']);
    }

    public function test_forever_event_has_no_last_date_and_a_twelve_month_window(): void
    {
        $data = $this->indexer()->standardData($this->makeEvent(
            start: '2026-09-10 09:00:00',
            end: '2026-09-10 10:00:00',
            freq: 'MONTHLY',
            occurrences: self::monthlyOn10th('2026-09-10', 60),
        ));

        $this->assertNull($data['lastDate']);
        // Past occurrences (Sep and Oct 2026) are left out; the window runs
        // from today to 12 months ahead
        $this->assertSame(array_map(self::ts(...), self::monthlyOn10th('2026-11-10', 12)), $data['occurrenceDates']);
    }

    public function test_occurrence_dates_are_capped_at_the_limit(): void
    {
        $data = $this->indexer(occurrenceLimit: 3)->standardData($this->makeEvent(
            start: '2026-09-10 09:00:00',
            end: '2026-09-10 10:00:00',
            freq: 'MONTHLY',
            occurrences: self::monthlyOn10th('2026-09-10', 60),
        ));

        $this->assertSame(array_map(self::ts(...), self::monthlyOn10th('2026-11-10', 3)), $data['occurrenceDates']);
    }

    public function test_select_dates_event_includes_its_original_start(): void
    {
        // Calendar's getOccurrences() returns only the select dates, not the
        // event's own start
        $data = $this->indexer()->standardData($this->makeEvent(
            start: '2026-11-20 19:00:00',
            end: '2026-11-20 21:00:00',
            freq: 'SELECT_DATES',
            occurrences: ['2026-12-11 19:00:00', '2027-01-15 19:00:00'],
        ));

        $this->assertTrue($data['repeats']);
        $this->assertSame('SELECT_DATES', $data['repeatFrequency']);
        $this->assertSame(
            array_map(self::ts(...), ['2026-11-20 19:00:00', '2026-12-11 19:00:00', '2027-01-15 19:00:00']),
            $data['occurrenceDates'],
        );
        $this->assertSame(self::ts('2027-01-15 21:00:00'), $data['lastDate']);
    }

    public function test_select_dates_event_whose_select_dates_are_all_past(): void
    {
        $data = $this->indexer()->standardData($this->makeEvent(
            start: '2026-12-20 19:00:00',
            end: '2026-12-20 21:00:00',
            freq: 'SELECT_DATES',
            occurrences: ['2026-08-01 19:00:00'],
        ));

        $this->assertSame([self::ts('2026-12-20 19:00:00')], $data['occurrenceDates']);
        $this->assertSame(self::ts('2026-12-20 21:00:00'), $data['lastDate']);
    }

    // getNextReindexDate()

    /**
     * @return string[] Start dates every $days days at $time from $from
     */
    private static function every(int $days, string $from, int $count, string $time): array
    {
        $dates = [];
        $date = new Carbon("{$from} {$time}", 'UTC');
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $date->copy()->addDays($i * $days)->format('Y-m-d H:i:s');
        }
        return $dates;
    }

    public function test_non_repeating_event_needs_no_reindex(): void
    {
        $this->assertNull($this->indexer()->getNextReindexDate($this->makeEvent()));
    }

    public function test_finite_event_fully_inside_the_window_needs_no_reindex(): void
    {
        $event = $this->makeEvent(
            start: '2026-11-03 10:00:00',
            end: '2026-11-03 11:00:00',
            freq: 'WEEKLY',
            count: 6,
            occurrences: self::every(7, '2026-11-03', 6, '10:00:00'),
        );

        $this->assertNull($this->indexer()->getNextReindexDate($event));
    }

    public function test_forever_event_reindexes_at_its_last_indexed_occurrence(): void
    {
        $event = $this->makeEvent(
            start: '2026-09-10 09:00:00',
            end: '2026-09-10 10:00:00',
            freq: 'MONTHLY',
            occurrences: self::monthlyOn10th('2026-09-10', 60),
        );

        // 12 upcoming occurrences (Nov 2026 - Oct 2027); the last is 2027-10-10
        $this->assertSame(self::ts('2027-10-10 09:00:00'), $this->indexer()->getNextReindexDate($event)?->getTimestamp());
    }

    public function test_event_capped_by_the_limit_reindexes_at_its_last_indexed_occurrence(): void
    {
        $event = $this->makeEvent(
            start: '2026-10-01 09:00:00',
            end: '2026-10-01 09:30:00',
            freq: 'DAILY',
            occurrences: self::every(1, '2026-10-01', 400, '09:00:00'),
        );

        // Limit 5 from today (2026-10-29): 10-29 .. 11-02
        $this->assertSame(self::ts('2026-11-02 09:00:00'), $this->indexer(occurrenceLimit: 5)->getNextReindexDate($event)?->getTimestamp());
    }

    public function test_finite_event_running_past_the_window_reindexes_at_its_last_indexed_occurrence(): void
    {
        $event = $this->makeEvent(
            start: '2026-11-05 14:00:00',
            end: '2026-11-05 15:00:00',
            freq: 'WEEKLY',
            until: '2028-01-01 00:00:00',
            occurrences: self::every(7, '2026-11-05', 61, '14:00:00'),
        );

        // The window ends 2027-10-29; the last Thursday in it is 2027-10-28
        $this->assertSame(self::ts('2027-10-28 14:00:00'), $this->indexer()->getNextReindexDate($event)?->getTimestamp());
    }

    public function test_event_with_nothing_in_the_window_reindexes_when_the_window_moves_on(): void
    {
        $event = $this->makeEvent(
            start: '2025-12-01 10:00:00',
            end: '2025-12-01 12:00:00',
            freq: 'YEARLY',
            occurrences: ['2025-12-01 10:00:00', '2027-12-01 10:00:00', '2029-12-01 10:00:00'],
        );

        $this->assertSame(self::ts('2027-10-29 12:00:00'), $this->indexer()->getNextReindexDate($event)?->getTimestamp());
    }

    public function test_event_whose_only_indexed_occurrence_is_earlier_today_reindexes_tomorrow(): void
    {
        // Every 18 months: today 09:00 (now is 12:00) is the only date in the window
        $event = $this->makeEvent(
            start: '2026-10-29 09:00:00',
            end: '2026-10-29 10:00:00',
            freq: 'MONTHLY',
            occurrences: ['2026-10-29 09:00:00', '2028-04-29 09:00:00'],
        );

        $this->assertSame(self::ts('2026-10-30 00:00:00'), $this->indexer()->getNextReindexDate($event)?->getTimestamp());
    }

    public function test_select_dates_event_starting_earlier_today_reindexes_tomorrow(): void
    {
        $event = $this->makeEvent(
            start: '2026-10-29 08:00:00',
            end: '2026-10-29 09:00:00',
            freq: 'SELECT_DATES',
            occurrences: ['2028-01-01 08:00:00'],
        );

        $this->assertSame(self::ts('2026-10-30 00:00:00'), $this->indexer()->getNextReindexDate($event)?->getTimestamp());
    }

    public function test_select_dates_event_with_no_dates_left_after_its_start_needs_no_reindex(): void
    {
        $event = $this->makeEvent(
            start: '2026-12-20 19:00:00',
            end: '2026-12-20 21:00:00',
            freq: 'SELECT_DATES',
            occurrences: ['2026-08-01 19:00:00'],
        );

        $this->assertNull($this->indexer()->getNextReindexDate($event));
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
    public function __construct(private string $handle, private ?int $limit = null)
    {
    }

    protected function occurrenceLimit(): int
    {
        return $this->limit ?? parent::occurrenceLimit();
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
