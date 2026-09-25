<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use Craft;
use craft\base\Element;
use craft\elements\Entry;

abstract class BaseEntryIndexer implements ElementIndexerInterface
{
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
        $fieldsService = Craft::$app->getFields();
        $field = $fieldsService->getFieldByHandle($fieldHandle);

        if ($field === null) {
            return '';
        }

        $value = $entry->getFieldValue($fieldHandle);

        $keywordsRaw = (string) $field->getSearchKeywords($value, $entry);

        return $this->toPlainText($keywordsRaw);
    }

    protected function toPlainText(string $html): string
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}