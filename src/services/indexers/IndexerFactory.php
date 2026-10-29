<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use Craft;
use craft\base\Element;
use Solspace\Calendar\Elements\Event;

final class IndexerFactory
{
    /** Factories are built on every save, so the warning is logged once per request */
    private static bool $warnedEventsConfigIgnored = false;

    private array $indexers;

    public function __construct(array $config = [])
    {
        $this->indexers = $this->buildIndexers($config);
    }

    public function getIndexerForElement(?object $element): ?ElementIndexerInterface
    {
        if (!$element instanceof Element) {
            return null;
        }

        foreach ($this->indexers as $indexer) {
            if ($indexer->supports($element)) {
                return $indexer;
            }
        }

        return null;
    }

    private function buildIndexers(array $config): array
    {
        $indexers = [];

        // Custom indexers first so they take priority
        foreach ($config['custom'] ?? [] as $class) {
            $indexers[] = new $class();
        }

        // Config-driven entry indexers
        foreach ($config['entries'] ?? [] as $sectionHandle => $sectionConfig) {
            $indexers[] = new ConfigDrivenEntryIndexer($sectionHandle, $sectionConfig);
        }

        // Config-driven asset indexers
        foreach ($config['assets'] ?? [] as $volumeHandle => $volumeConfig) {
            $indexers[] = new ConfigDrivenAssetIndexer($volumeHandle, $volumeConfig);
        }

        // Config-driven Calendar event indexers. Calendar is optional, so the
        // events key is ignored (with a warning) when it isn't installed.
        if (!empty($config['events'])) {
            if (class_exists(Event::class)) {
                foreach ($config['events'] as $calendarHandle => $calendarConfig) {
                    $indexers[] = new ConfigDrivenEventIndexer($calendarHandle, $calendarConfig);
                }
            } else {
                self::warnEventsConfigIgnored();
            }
        }

        return $indexers;
    }

    public static function warnEventsConfigIgnored(): void
    {
        if (self::$warnedEventsConfigIgnored) {
            return;
        }

        self::$warnedEventsConfigIgnored = true;
        Craft::warning('[ALGOLIA] The "events" config is ignored: Solspace Calendar is not installed.', __METHOD__);
    }
}