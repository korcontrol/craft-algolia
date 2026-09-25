<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit\Indexers;

use Codeception\Test\Unit;
use craft\base\FieldInterface;
use craft\models\FieldLayout;
use Tests\Support\UnitTester;

abstract class IndexerTestCase extends Unit
{
    protected UnitTester $tester;

    protected function makeFieldLayout(bool $hasSearchableField = false): FieldLayout
    {
        return $this->make(FieldLayout::class, [
            'getFieldByHandle' => function(string $handle) use ($hasSearchableField) {
                if ($handle === 'searchable' && $hasSearchableField) {
                    return new \craft\fields\PlainText();
                }
                return null;
            },
        ]);
    }
}