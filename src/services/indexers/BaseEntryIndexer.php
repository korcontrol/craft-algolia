<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use craft\base\Element;
use craft\elements\Entry;

abstract class BaseEntryIndexer implements ElementIndexerInterface
{
    use ChecksLayoutFields;

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

    protected function toPlainText(string $html): string
    {
        // Block-level tags and <br> become a space so adjacent blocks don't run
        // together ("</h2><p>" would otherwise join two words). Inline tags
        // (<em>, <strong>, <a>…) are stripped below without one, so words
        // split by inline markup stay whole.
        $text = preg_replace(
            '/<\/?(?:address|article|aside|blockquote|br|dd|div|dl|dt|figcaption|figure|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|tbody|td|tfoot|th|thead|tr|ul)\b[^>]*>/i',
            ' ',
            $html
        ) ?? $html;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = str_replace("\xC2\xA0", ' ', $text);
        // Soft hyphens (often pasted from design files/PDFs) split words invisibly
        $text = str_replace("\u{00AD}", '', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}