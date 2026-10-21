<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use craft\base\Element;
use craft\elements\Asset;

final class ConfigDrivenAssetIndexer extends BaseAssetIndexer
{
    private string $volume;
    private array $config;

    public function __construct(string $volume, array $config = [])
    {
        $this->volume = $volume;
        $this->config = $config;
    }

    public function volumeHandle(): string
    {
        return $this->volume;
    }

    public function getIndexData(Element $element): array
    {
        /** @var Asset $asset */
        $asset = $element;

        $volume = $asset->getVolume();
        $sectionHandle = $volume->handle;
        $sectionLabel = $volume->name;

        $updatedDate = $asset->dateUpdated?->getTimestamp();
        $filterYear = $asset->dateUpdated ? (int) $asset->dateUpdated->format('Y') : null;

        // Build extra fields, skipping any not on this volume's field layout
        // (getFieldValue() would throw)
        $extra = [];
        foreach ($this->config['extraFields'] ?? [] as $fieldHandle => $algoliaAttribute) {
            if (!$this->hasField($asset, $fieldHandle, 'extraFields')) {
                continue;
            }
            $extra[$algoliaAttribute] = (string) $asset->getFieldValue($fieldHandle);
        }

        return array_merge($extra, [
            'objectID'      => (string) $asset->id,
            'elementID'     => (string) $asset->id,
            'section'       => $sectionLabel,
            'sectionHandle' => $sectionHandle,
            'siteLanguage'  => $asset->getSite()->language,
            'title'         => (string) $asset->title,
            'url'           => (string) $asset->getUrl(),
            'updatedDate'   => $updatedDate,
            'filterDate'    => $updatedDate,
            'filterYear'    => $filterYear,
        ]);
    }
}