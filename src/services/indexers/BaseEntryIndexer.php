<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use craft\base\Element;
use craft\elements\Entry;

abstract class BaseEntryIndexer implements ElementIndexerInterface
{
    use ChecksLayoutFields;
    use ConvertsToPlainText;

    abstract public function sectionHandle(): string;

    public function supports(Element $element): bool
    {
        return $element instanceof Entry
            && $element->section
            && $element->section->handle === $this->sectionHandle();
    }

    public function shouldIndex(Element $element): bool
    {
        /** @var Entry $entry */
        $entry = $element;

        // Must be live first
        if ($entry->getStatus() !== Entry::STATUS_LIVE) {
            return false;
        }

        // If a searchable field exists on the field layout, it must be truthy
        if ($entry->getFieldLayout()->getFieldByHandle('searchable') !== null) {
            $searchable = $entry->getFieldValue('searchable');
            if ($searchable === null) {
                return false;
            }
            return (bool) $searchable;
        }

        return true;
    }

    protected function getSearchKeywordsForField(
        Entry $entry,
        string $fieldHandle
    ): string {
        if (!$this->hasField($entry, $fieldHandle, 'bodyFields')) {
            return '';
        }

        // Resolve via the entry's field layout so per-layout handle overrides
        // (Craft 5 multi-instance fields) work, matching getFieldValue() below.
        // The global Fields service only knows global handles.
        $field = $entry->getFieldLayout()->getFieldByHandle($fieldHandle);

        $value = $entry->getFieldValue($fieldHandle);

        $keywordsRaw = (string) $field->getSearchKeywords($value, $entry);

        return $this->toPlainText($keywordsRaw);
    }
}