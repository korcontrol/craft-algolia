<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

/**
 * Converts field HTML (rich text, search keywords) to plain text for indexing.
 */
trait ConvertsToPlainText
{
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
