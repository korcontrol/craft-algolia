# Craft Algolia

A Craft CMS 5 plugin for config-driven Algolia search indexing.

Supports entries and assets via a declarative config file, with a custom indexer escape hatch for complex cases.

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

Credentials are read from environment variables automatically. The config file is where you define which sections and volumes to index.

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

### The `searchable` Field

If an entry or asset field layout contains a field with the handle `searchable` (a Lightswitch field), the plugin uses it as an opt-in gate. The element is only indexed if the field is enabled. If no `searchable` field exists on the layout, all elements are indexed by default.

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
```

---

## How Indexing Works

- **On save** — when a live entry or asset is saved, the plugin deletes any existing Algolia records for that element and site, then writes fresh records
- **On delete** — all Algolia records for the element are removed across all sites
- **Chunking** — if the `body` field content exceeds Algolia's record size limit, it is automatically split into multiple records with suffixed `objectID`s (e.g. `42-1-0`, `42-1-1`)
- **Multi-site** — each site variant is indexed as a separate record with its own `objectID` (`elementId-siteId`), `siteID`, and `siteHandle`

---

## Running Tests

The plugin includes a test suite using Codeception with a Docker-based Postgres instance.
```bash
# Start the test database
docker compose up -d

# Run all tests
vendor/bin/codecept run Unit
```
