<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use craft\base\Element;
use craft\elements\Entry;

final class ConfigDrivenEntryIndexer extends BaseEntryIndexer
{
    private string $section;
    private array $config;

    public function __construct(string $section, array $config = [])
    {
        $this->section = $section;
        $this->config = $config;
    }

    public function sectionHandle(): string
    {
        return $this->section;
    }

    public function getIndexData(Element $element): array
    {
        /** @var Entry $entry */
        $entry = $element;

        $section = $entry->getSection();
        $sectionHandle = $section?->handle ?? $this->section;
        $sectionLabel = $section?->name ?? $sectionHandle;

        $postDate = $entry->postDate;

        // Resolve the date to use for filterDate/filterYear
        $dateField = $this->config['dateField'] ?? 'postDate';
        if ($dateField === 'postDate') {
            $filterDate = $postDate?->getTimestamp();
            $filterYear = $postDate ? (int) $postDate->format('Y') : null;
        } else {
            // Entry types in a section may not all have the date field
            $customDate = $this->hasField($entry, $dateField, 'dateField')
                ? $entry->getFieldValue($dateField)
                : null;
            $filterDate = $customDate?->getTimestamp() ?? null;
            $filterYear = $customDate ? (int) $customDate->format('Y') : null;
        }

        // Build body from bodyFields. Fields missing from this entry type's
        // layout are skipped (getFieldValue() would throw).
        $bodyParts = [];
        $bodyMode = $this->config['bodyMode'] ?? 'search';
        foreach ($this->config['bodyFields'] ?? [] as $fieldHandle) {
            if ($bodyMode === 'plaintext') {
                if (!$this->hasField($entry, $fieldHandle, 'bodyFields')) {
                    continue;
                }
                $value = $entry->getFieldValue($fieldHandle);
                $bodyParts[] = $this->toPlainText((string) $value);
            } else {
                $bodyParts[] = $this->getSearchKeywordsForField($entry, $fieldHandle);
            }
        }
        $body = implode(' ', array_filter($bodyParts));

        // Build extra fields, skipping any not on this entry type's layout
        $extra = [];
        foreach ($this->config['extraFields'] ?? [] as $fieldHandle => $algoliaAttribute) {
            if (!$this->hasField($entry, $fieldHandle, 'extraFields')) {
                continue;
            }
            $extra[$algoliaAttribute] = (string) $entry->getFieldValue($fieldHandle);
        }

        return array_merge($extra, [
            'objectID'      => (string) $entry->id,
            'elementID'     => (string) $entry->id,
            'section'       => $sectionLabel,
            'sectionHandle' => $sectionHandle,
            'entryType'     => $entry->type->handle,
            'siteLanguage'  => $entry->getSite()->language,
            'title'         => (string) $entry->title,
            'url'           => (string) $entry->getUrl(),
            'postDate'      => $postDate?->getTimestamp(),
            'filterDate'    => $filterDate,
            'filterYear'    => $filterYear,
            'body'          => $body,
        ]);
    }
}