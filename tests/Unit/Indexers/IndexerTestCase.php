<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit\Indexers;

use Codeception\Test\Unit;
use Craft;
use craft\base\FieldInterface;
use craft\errors\InvalidFieldException;
use craft\models\EntryType;
use craft\models\FieldLayout;
use Tests\Support\UnitTester;
use yii\log\Logger;

abstract class IndexerTestCase extends Unit
{
    protected UnitTester $tester;

    private Logger $originalLogger;

    private Logger $logger;

    protected function _before(): void
    {
        // Codeception's logger doesn't keep messages, so swap in a plain Yii
        // logger (no dispatcher) that does, for asserting warnings
        $this->originalLogger = Craft::getLogger();
        $this->logger = new Logger();
        Craft::setLogger($this->logger);
    }

    protected function _after(): void
    {
        Craft::setLogger($this->originalLogger);
    }

    /**
     * Warnings logged by the plugin ("[ALGOLIA]" prefix) during the test.
     *
     * @return string[]
     */
    protected function algoliaWarnings(): array
    {
        $warnings = array_filter(
            $this->logger->messages,
            fn(array $message) => $message[1] === Logger::LEVEL_WARNING
                && is_string($message[0])
                && str_starts_with($message[0], '[ALGOLIA]'),
        );

        return array_values(array_map(fn(array $message) => $message[0], $warnings));
    }

    /**
     * An entry type whose field layout has the given (layout) handles.
     *
     * @param string[] $fieldHandles
     */
    protected function makeEntryType(string $handle, array $fieldHandles = []): EntryType
    {
        return $this->make(EntryType::class, [
            'handle'         => $handle,
            'getFieldLayout' => $this->makeFieldLayout(false, $this->layoutFieldsFor(array_flip($fieldHandles))),
        ]);
    }

    /**
     * @param array<string, FieldInterface> $fields Fields on the layout, keyed by layout handle
     */
    protected function makeFieldLayout(bool $hasSearchableField = false, array $fields = []): FieldLayout
    {
        return $this->make(FieldLayout::class, [
            'getFieldByHandle' => function(string $handle) use ($hasSearchableField, $fields) {
                if ($handle === 'searchable' && $hasSearchableField) {
                    return new \craft\fields\PlainText();
                }
                return $fields[$handle] ?? null;
            },
        ]);
    }

    /**
     * Layout fields for the given field values: a plain text field per handle,
     * merged with any explicitly provided layout fields.
     *
     * @param array<string, mixed> $fieldValues
     * @param array<string, FieldInterface> $layoutFields
     * @return array<string, FieldInterface>
     */
    protected function layoutFieldsFor(array $fieldValues, array $layoutFields = []): array
    {
        $fields = [];
        foreach (array_keys($fieldValues) as $handle) {
            $fields[$handle] = new \craft\fields\PlainText(['handle' => $handle]);
        }
        return array_merge($fields, $layoutFields);
    }

    /**
     * getFieldValue() stub that behaves like Craft: throws for handles that
     * aren't on the element's field layout.
     *
     * @param array<string, mixed> $fieldValues
     */
    protected function fieldValueGetter(FieldLayout $layout, array $fieldValues): \Closure
    {
        return function(string $handle) use ($layout, $fieldValues) {
            if ($layout->getFieldByHandle($handle) === null) {
                throw new InvalidFieldException($handle);
            }
            return $fieldValues[$handle] ?? null;
        };
    }

    /**
     * A field whose search keywords are fixed, standing in for Matrix/CKEditor
     * fields (which return HTML keywords).
     */
    protected function makeKeywordsField(string $handle, string $keywords): FieldInterface
    {
        return $this->make(\craft\fields\PlainText::class, [
            'handle' => $handle,
            'getSearchKeywords' => $keywords,
        ]);
    }
}
