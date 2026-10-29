<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit\Indexers;

use Carbon\Carbon;
use korcontrol\craftalgolia\services\indexers\ConfigDrivenEventIndexer;

class ConfigDrivenEventIndexerTest extends IndexerTestCase
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

    public function test_calendar_handle_comes_from_config_key(): void
    {
        $indexer = new ConfigDrivenEventIndexer('events');

        $this->assertSame('events', $indexer->calendarHandle());
        $this->assertTrue($indexer->supports($this->makeEvent()));
    }

    public function test_index_data_is_the_standard_event_data_by_default(): void
    {
        $event = $this->makeEvent(
            calendar: $this->makeCalendar(descriptionFieldHandle: 'description'),
            fieldValues: ['description' => '<p>An evening talk.</p>'],
        );

        $data = (new ConfigDrivenEventIndexer('events'))->getIndexData($event);

        $this->assertSame('An evening talk.', $data['body']);
        $this->assertSame('events', $data['calendarHandle']);
        $this->assertArrayHasKey('occurrenceDates', $data);
    }

    public function test_body_fields_replace_the_description_body(): void
    {
        $event = $this->makeEvent(
            calendar: $this->makeCalendar(descriptionFieldHandle: 'description'),
            fieldValues: [
                'description' => 'Description text.',
                'agenda'      => '<ul><li>Welcome</li><li>Talk</li></ul>',
                'speakers'    => 'Ada Lovelace',
            ],
        );

        $data = (new ConfigDrivenEventIndexer('events', ['bodyFields' => ['agenda', 'speakers']]))->getIndexData($event);

        $this->assertSame('Welcome Talk Ada Lovelace', $data['body']);
    }

    public function test_extra_fields_are_included(): void
    {
        $event = $this->makeEvent(fieldValues: ['organiser' => 'Ada Lovelace']);

        $data = (new ConfigDrivenEventIndexer('events', ['extraFields' => ['organiser' => 'organiserName']]))->getIndexData($event);

        $this->assertSame('Ada Lovelace', $data['organiserName']);
    }

    public function test_extra_fields_do_not_overwrite_standard_fields(): void
    {
        $event = $this->makeEvent(title: 'Real Title', fieldValues: ['headline' => 'Other']);

        $data = (new ConfigDrivenEventIndexer('events', ['extraFields' => ['headline' => 'title']]))->getIndexData($event);

        $this->assertSame('Real Title', $data['title']);
    }

    public function test_fields_missing_from_layout_are_skipped_without_error(): void
    {
        $data = (new ConfigDrivenEventIndexer('events', [
            'extraFields' => ['organiser' => 'organiser'],
            'bodyFields'  => ['agenda'],
        ]))->getIndexData($this->makeEvent());

        $this->assertArrayNotHasKey('organiser', $data);
        $this->assertSame('', $data['body']);
    }

    public function test_warns_with_calendar_name_for_missing_configured_field(): void
    {
        (new ConfigDrivenEventIndexer('events', ['extraFields' => ['organiser' => 'organiser']]))
            ->getIndexData($this->makeEvent());

        $this->assertSame(
            ['[ALGOLIA] Field "organiser" (extraFields) is not on the field layout for calendar "events"; skipped.'],
            $this->algoliaWarnings(),
        );
    }
}
