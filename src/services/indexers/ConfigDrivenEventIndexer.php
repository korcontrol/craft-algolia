<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use craft\base\Element;
use Solspace\Calendar\Elements\Event;

/**
 * Indexes Solspace Calendar events for one calendar from the `events` config
 * key. Only built when Calendar is installed (see IndexerFactory).
 */
final class ConfigDrivenEventIndexer extends BaseSolspaceEventIndexer
{
    private string $calendar;
    private array $config;

    public function __construct(string $calendar, array $config = [])
    {
        $this->calendar = $calendar;
        $this->config = $config;
    }

    public function calendarHandle(): string
    {
        return $this->calendar;
    }

    public function getIndexData(Element $element): array
    {
        /** @var Event $event */
        $event = $element;

        $data = $this->getStandardEventData($event);

        // bodyFields replaces the default body (the calendar's description
        // field). Fields missing from the calendar's layout are skipped
        // (getFieldValue() would throw).
        if (isset($this->config['bodyFields'])) {
            $bodyParts = [];
            foreach ($this->config['bodyFields'] as $fieldHandle) {
                if (!$this->hasField($event, $fieldHandle, 'bodyFields')) {
                    continue;
                }
                $bodyParts[] = $this->toPlainText((string) $event->getFieldValue($fieldHandle));
            }
            $data['body'] = implode(' ', array_filter($bodyParts));
        }

        // Build extra fields, skipping any not on the calendar's layout
        $extra = [];
        foreach ($this->config['extraFields'] ?? [] as $fieldHandle => $algoliaAttribute) {
            if (!$this->hasField($event, $fieldHandle, 'extraFields')) {
                continue;
            }
            $extra[$algoliaAttribute] = (string) $event->getFieldValue($fieldHandle);
        }

        return array_merge($extra, $data);
    }
}
