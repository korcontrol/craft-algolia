<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit\Indexers;

use craft\elements\Entry;
use craft\models\Section;
use korcontrol\craftalgolia\services\indexers\ConfigDrivenAssetIndexer;
use korcontrol\craftalgolia\services\indexers\ConfigDrivenEntryIndexer;
use korcontrol\craftalgolia\services\indexers\ElementIndexerInterface;
use korcontrol\craftalgolia\services\indexers\IndexerFactory;
use craft\base\Element;

class IndexerFactoryTest extends IndexerTestCase
{
    private function getIndexers(IndexerFactory $factory): array
    {
        $reflection = new \ReflectionClass($factory);
        $property = $reflection->getProperty('indexers');
        $property->setAccessible(true);
        return $property->getValue($factory);
    }

    public function test_empty_config_returns_no_indexer_for_element(): void
    {
        $factory = new IndexerFactory([]);
        $element = $this->make(Entry::class, [
            'getStatus'      => Entry::STATUS_LIVE,
            'getFieldLayout' => $this->makeFieldLayout(),
        ]);

        $this->assertNull($factory->getIndexerForElement($element));
    }

    public function test_null_element_returns_null(): void
    {
        $this->assertNull((new IndexerFactory([]))->getIndexerForElement(null));
    }

    public function test_non_element_returns_null(): void
    {
        $this->assertNull((new IndexerFactory([]))->getIndexerForElement(new \stdClass()));
    }

    public function test_returns_correct_indexer_for_matching_element(): void
    {
        $section = new Section();
        $section->handle = 'news';
        $section->name = 'News';

        $entry = $this->make(Entry::class, [
            'getStatus'      => Entry::STATUS_LIVE,
            'getSection'     => $section,
            'getFieldLayout' => $this->makeFieldLayout(),
        ]);

        $factory = new IndexerFactory([
            'entries' => ['news' => []],
        ]);

        $indexer = $factory->getIndexerForElement($entry);

        $this->assertInstanceOf(ConfigDrivenEntryIndexer::class, $indexer);
    }

    public function test_builds_entry_indexers_from_config(): void
    {
        $indexers = $this->getIndexers(new IndexerFactory([
            'entries' => [
                'news'  => ['bodyFields' => []],
                'pages' => ['bodyFields' => []],
            ],
        ]));

        $this->assertCount(2, $indexers);
        $this->assertInstanceOf(ConfigDrivenEntryIndexer::class, $indexers[0]);
        $this->assertInstanceOf(ConfigDrivenEntryIndexer::class, $indexers[1]);
    }

    public function test_builds_asset_indexers_from_config(): void
    {
        $indexers = $this->getIndexers(new IndexerFactory([
            'assets' => ['documents' => []],
        ]));

        $this->assertCount(1, $indexers);
        $this->assertInstanceOf(ConfigDrivenAssetIndexer::class, $indexers[0]);
    }

    public function test_custom_indexers_appear_before_config_driven(): void
    {
        $customIndexer = new class implements ElementIndexerInterface {
            public function supports(Element $element): bool { return false; }
            public function shouldIndex(Element $element): bool { return false; }
            public function getIndexData(Element $element): array { return []; }
        };

        $indexers = $this->getIndexers(new IndexerFactory([
            'custom'  => [get_class($customIndexer)],
            'entries' => ['news' => []],
        ]));

        $this->assertCount(2, $indexers);
        $this->assertInstanceOf(ConfigDrivenEntryIndexer::class, $indexers[1]);
    }

    public function test_builds_mixed_config(): void
    {
        $indexers = $this->getIndexers(new IndexerFactory([
            'entries' => ['news' => []],
            'assets'  => ['documents' => []],
        ]));

        $this->assertCount(2, $indexers);
        $this->assertInstanceOf(ConfigDrivenEntryIndexer::class, $indexers[0]);
        $this->assertInstanceOf(ConfigDrivenAssetIndexer::class, $indexers[1]);
    }
}