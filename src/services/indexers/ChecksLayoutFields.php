<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Entry;
use Solspace\Calendar\Elements\Event;

/**
 * Checks configured field handles against an element's field layout.
 *
 * Handles are layout handles, which may differ from the field's global handle
 * (Craft 5 per-layout handle overrides). Fields missing from a layout are
 * skipped rather than throwing, so a save never fails because of indexing.
 */
trait ChecksLayoutFields
{
    /** @var array<string, bool> Memoised "no entry type in the section has this handle" results */
    private array $missingFromSection = [];

    /**
     * Whether the element's field layout has a field with this handle.
     *
     * When it doesn't and the handle looks like a config typo, logs a warning
     * so the problem is visible without failing the save.
     */
    protected function hasField(Element $element, string $fieldHandle, string $configKey): bool
    {
        if ($element->getFieldLayout()?->getFieldByHandle($fieldHandle) !== null) {
            return true;
        }

        if ($this->isLikelyConfigTypo($element, $fieldHandle)) {
            Craft::warning(sprintf(
                '[ALGOLIA] Field "%s" (%s) is not on the field layout for %s; skipped.',
                $fieldHandle,
                $configKey,
                $this->describeLayoutOwner($element),
            ), __METHOD__);
        }

        return false;
    }

    /**
     * A handle missing from one entry type is expected when a section mixes
     * entry types, so only treat it as a typo if no entry type in the section
     * has it. Assets and Calendar events have a single layout per volume or
     * calendar, so a miss always counts.
     */
    private function isLikelyConfigTypo(Element $element, string $fieldHandle): bool
    {
        if (!$element instanceof Entry) {
            return true;
        }

        $section = $element->getSection();
        if ($section === null) {
            return true;
        }

        $key = ($section->uid ?? $section->handle) . '|' . $fieldHandle;

        return $this->missingFromSection[$key] ??= !array_filter(
            $section->getEntryTypes(),
            fn($entryType) => $entryType->getFieldLayout()->getFieldByHandle($fieldHandle) !== null,
        );
    }

    private function describeLayoutOwner(Element $element): string
    {
        if ($element instanceof Entry && ($section = $element->getSection()) !== null) {
            return sprintf('section "%s"', $section->handle);
        }

        if ($element instanceof Asset && ($volume = $element->getVolume()) !== null) {
            return sprintf('volume "%s"', $volume->handle);
        }

        // instanceof doesn't autoload, so this is safe when Calendar isn't installed
        if ($element instanceof Event && $element->calendarId !== null) {
            return sprintf('calendar "%s"', $element->getCalendar()->handle);
        }

        return $element::class;
    }
}
