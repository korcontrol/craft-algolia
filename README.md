# Craft Algolia

A Craft CMS 5 plugin for config-driven Algolia search indexing.

Supports entries, assets and [Solspace Calendar](https://plugins.craftcms.com/calendar) events via a declarative config file, with a custom indexer escape hatch for complex cases.

---

## Installation

Require the plugin with Composer and install it:
```bash
composer require korcontrol/craft-algolia
php craft plugin/install craft-algolia
```

---

## Environment Variables

Add these to your `.env` file:
```bash
ALGOLIA_APP_ID=your-app-id
ALGOLIA_WRITE_API_KEY=your-write-api-key
ALGOLIA_INDEX_NAME=your-index-name
```

The write API key must have `addObject`, `deleteObject`, and `deleteIndex` permissions. Never expose it publicly — it stays server-side only.

---

## Algolia Index Configuration

After creating your index, configure the following settings in the Algolia dashboard under **Index → Configuration**.

### Searchable Attributes

Under **Searchable attributes**, add:

- `title`
- `body`
- `section`
- `elementID`

### Attributes for Faceting

Under **Facets → Attributes for faceting**, add:

- `elementID` *(set to Filter only)*
- `siteID` *(set to Filter only)*

> **Required** — the plugin uses `deleteBy` filters on `elementID` and `siteID` when saving and deleting elements. Without these, stale records will not be removed and deletions will silently fail.

### Deduplication

Under **Deduplication and Grouping**:

- Set **Distinct** to `true`
- Set **Attribute for Distinct** to `elementID`

> The plugin splits large `body` fields across multiple Algolia records (e.g. `42-1-0`, `42-1-1`). Without deduplication, a single entry could appear multiple times in search results.

---

## Configuration

Copy the plugin's `src/config.php` to your project's `config/` directory as `algolia.php`:
```bash
cp vendor/korcontrol/craft-algolia/src/config.php config/algolia.php
```

Credentials are read from environment variables automatically. The config file is where you define which sections, volumes and calendars to index.

---

## Config Reference

### Entries

Index entries from one or more sections:
```php
'entries' => [
    'news' => [
        'bodyFields' => ['editorComponents'],
    ],
    'pages' => [
        'bodyFields' => ['contentBuilder'],
        'bodyMode'   => 'plaintext',
    ],
    'events' => [
        'bodyFields' => ['description'],
        'dateField'  => 'eventDate',
        'extraFields' => [
            'location' => 'location',
            'ticketUrl' => 'ticketUrl',
        ],
    ],
],
```

#### Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `bodyFields` | `array` | `[]` | Field handles whose content is concatenated into the `body` attribute |
| `bodyMode` | `string` | `'search'` | How body content is extracted — `'search'` uses Craft's search keywords extractor (recommended for Matrix/CKEditor fields), `'plaintext'` casts the field value directly to string |
| `dateField` | `string` | `'postDate'` | Which date field to use for `filterDate` and `filterYear` — useful for events with a custom event date field |
| `extraFields` | `array` | `[]` | Map of `fieldHandle => algoliaAttribute` for additional scalar fields to include |

Field handles are the handles **as they appear on the entry type's field layout**. If a field's handle is overridden on the layout (e.g. an `editorComponents` field added as `body`), use the overridden handle (`body`). Fields that aren't on an entry type's layout are skipped for entries of that type.

#### Standard fields always indexed for entries

`objectID`, `elementID`, `section`, `sectionHandle`, `title`, `url`, `postDate`, `filterDate`, `filterYear`, `body`, `siteID`, `siteHandle`

---

### Assets

Index assets from one or more volumes:
```php
'assets' => [
    'documents' => [],
    'images' => [
        'extraFields' => [
            'altText' => 'altText',
        ],
    ],
],
```

#### Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `extraFields` | `array` | `[]` | Map of `fieldHandle => algoliaAttribute` for additional scalar fields to include |

#### Standard fields always indexed for assets

`objectID`, `elementID`, `section`, `sectionHandle`, `title`, `url`, `updatedDate`, `filterDate`, `filterYear`, `siteID`, `siteHandle`

---

### Calendar Events

Index [Solspace Calendar](https://plugins.craftcms.com/calendar) events from one or more calendars, keyed by calendar handle. Calendar is optional: the plugin doesn't require it, and the `events` key is ignored (with a logged warning) when Calendar isn't installed.
```php
'events' => [
    'events' => [],
    'workshops' => [
        'bodyFields'  => ['description', 'agenda'],
        'extraFields' => [
            'organiser' => 'organiser',
        ],
    ],
],
```

An event is indexed when it's enabled for the site and, if its calendar's field layout has a `searchable` field, that field is on. Calendar events have no pending or expired state, and their post date doesn't hide them, so neither affects indexing.

#### Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `bodyFields` | `array` | The calendar's description field | Field handles whose plain text is concatenated into the `body` attribute, replacing the default |
| `extraFields` | `array` | `[]` | Map of `fieldHandle => algoliaAttribute` for additional scalar fields to include |

Fields that aren't on the calendar's field layout are skipped, with a warning in the log.

#### Standard fields always indexed for events

| Attribute | Value |
|-----------|-------|
| `elementID`, `title`, `url`, `siteLanguage` | As for entries |
| `calendar`, `calendarHandle` | The event's calendar name and handle |
| `section`, `sectionHandle` | Also the calendar name and handle, so facets on `sectionHandle` work across entries and events |
| `postDate` | Unix timestamp, or `null` |
| `startDate`, `endDate` | Unix timestamps (UTC) of the event itself (its first occurrence, for recurring events) |
| `allDay` | `true` for all-day events |
| `filterDate`, `filterYear` | From `startDate` |
| `body` | Plain text of the calendar's **description field** (set in the calendar's settings), or of `bodyFields` when configured; chunked like entry bodies |
| `location` | Plain text of the calendar's **location field** |
| `repeats`, `repeatFrequency` | Whether the event repeats, and how: `DAILY`, `WEEKLY`, `MONTHLY`, `YEARLY` or `SELECT_DATES` (`null` when it doesn't repeat) |
| `occurrenceDates` | Unix start timestamps of occurrences from today up to 12 months ahead, at most 100 (override `occurrenceWindow()` / `occurrenceLimit()` to change). A non-repeating event has just its own start |
| `lastDate` | When the final occurrence ends, or `null` for an event that repeats forever |

`objectID`, `siteID` and `siteHandle` are added too, as for every record.

#### Recurring events

A recurring event is **one record per site**, not one per occurrence. Calendar stores it as a single element, and `occurrenceDates` lists its upcoming dates. Algolia matches a numeric filter on an array if any value matches, so to show upcoming events (including ones on today), filter on the start of today:
```js
filters: `occurrenceDates >= ${startOfTodayTimestamp}`
```

Records are only rebuilt when an event is saved. Past dates stay in `occurrenceDates` and new ones aren't added as time passes, so on sites with recurring events, resave events on a schedule (for example nightly) to keep the window current:
```bash
php craft calendar/events/resave
```

---

### The `searchable` Field

If an entry, asset or Calendar event field layout contains a field with the handle `searchable` (a Lightswitch field), the plugin uses it as an opt-in gate. The element is only indexed if the field is enabled. If no `searchable` field exists on the layout, all elements are indexed by default.

---

### Custom Indexers

For complex cases — relational fields, Commerce products, or any logic that can't be expressed in config — register a custom indexer class:
```php
'custom' => [
    \mynamespace\indexers\CareersIndexer::class,
],
```

Custom indexers are checked first and take priority over config-driven ones.

#### Writing a Custom Entry Indexer

Extend `BaseEntryIndexer` and implement `sectionHandle()` and `getIndexData()`:
```php
<?php

namespace mynamespace\indexers;

use craft\base\Element;
use craft\elements\Entry;
use korcontrol\craftalgolia\services\indexers\BaseEntryIndexer;

final class CareersIndexer extends BaseEntryIndexer
{
    public function sectionHandle(): string
    {
        return 'careers';
    }

    public function getIndexData(Element $element): array
    {
        /** @var Entry $entry */
        $entry = $element;

        return [
            'objectID'      => (string) $entry->id,
            'elementID'     => (string) $entry->id,
            'section'       => 'Careers',
            'sectionHandle' => 'careers',
            'title'         => (string) $entry->title,
            'url'           => (string) $entry->getUrl(),
            'department'    => (string) $entry->getFieldValue('department'),
            'body'          => $this->getSearchKeywordsForField($entry, 'jobDescription'),
        ];
    }
}
```

#### Writing a Custom Asset Indexer

Extend `BaseAssetIndexer` and implement `volumeHandle()` and `getIndexData()`:
```php
<?php

namespace mynamespace\indexers;

use craft\base\Element;
use craft\elements\Asset;
use korcontrol\craftalgolia\services\indexers\BaseAssetIndexer;

final class ReportsIndexer extends BaseAssetIndexer
{
    public function volumeHandle(): string
    {
        return 'reports';
    }

    public function getIndexData(Element $element): array
    {
        /** @var Asset $asset */
        $asset = $element;

        return [
            'objectID'      => (string) $asset->id,
            'elementID'     => (string) $asset->id,
            'section'       => 'Reports',
            'sectionHandle' => 'reports',
            'title'         => (string) $asset->title,
            'url'           => (string) $asset->getUrl(),
            'year'          => (string) $asset->getFieldValue('reportYear'),
        ];
    }
}
```

#### Writing a Custom Calendar Event Indexer

When the [`events` config](#calendar-events) can't express what you need, extend `BaseSolspaceEventIndexer` and implement `calendarHandle()` and `getIndexData()`. It's only loaded when you extend it, so Calendar stays optional.
```php
<?php

namespace mynamespace\indexers;

use craft\base\Element;
use korcontrol\craftalgolia\services\indexers\BaseSolspaceEventIndexer;
use Solspace\Calendar\Elements\Event;

final class EventsIndexer extends BaseSolspaceEventIndexer
{
    public function calendarHandle(): string
    {
        return 'events';
    }

    public function getIndexData(Element $element): array
    {
        /** @var Event $event */
        $event = $element;

        return array_merge($this->getStandardEventData($event), [
            // A relation the events config can't express (extraFields are scalar)
            'categories' => $event->getFieldValue('eventCategories')->collect()->pluck('title')->all(),
        ]);
    }
}
```

Register it under `custom`, one indexer per calendar:
```php
'custom' => [
    \mynamespace\indexers\EventsIndexer::class,
],
```

`getStandardEventData()` returns the [standard event fields](#standard-fields-always-indexed-for-events); merge your own fields into it.

---

## Reindexing

Since the plugin indexes on save, use Craft's built-in resave commands to index existing content:
```bash
# Reindex all entries in a section
php craft resave/entries --section=news

# Reindex all assets in a volume
php craft resave/assets --volume=documents

# Reindex all entries across all sections
php craft resave/entries

# Reindex a single entry (or a comma-separated list of IDs)
php craft resave/entries --elementId=42
```

Calendar events have their own resave command:
```bash
php craft calendar/events/resave
```

---

## How Indexing Works

- **On save** — when a live entry or asset is saved, the plugin deletes any existing Algolia records for that element and site, then writes fresh records
- **On delete** — all Algolia records for the element are removed across all sites
- **Chunking** — if the `body` field content exceeds Algolia's record size limit, it is automatically split into multiple records with suffixed `objectID`s (e.g. `42-1-0`, `42-1-1`)
- **Multi-site** — each site variant is indexed as a separate record with its own `objectID` (`elementId-siteId`), `siteID`, and `siteHandle`
- **Scheduled entries** — an entry with a future post date is indexed when it goes live, and removed when its expiry date passes, via delayed queue jobs. Calendar events aren't scheduled this way (they have no such dates); see [Recurring events](#recurring-events)

---

## Running Tests

The plugin includes a test suite using Codeception with a Docker-based Postgres instance.
```bash
# Start the test database
docker compose up -d

# Run all tests
vendor/bin/codecept run Unit
```
