<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use craft\base\Element;
use craft\elements\Asset;

abstract class BaseAssetIndexer implements ElementIndexerInterface
{
    abstract public function volumeHandle(): string;

    public function supports(Element $element): bool
    {
        if (!$element instanceof Asset) {
            return false;
        }

        $volume = $element->getVolume();
        if ($volume === null) {
            return false;
        }

        return $volume->handle === $this->volumeHandle();
    }

    public function shouldIndex(Element $element): bool
    {
        /** @var Asset $asset */
        $asset = $element;

        if ($asset->getFieldLayout()->getFieldByHandle('searchable') === null) {
            return true;
        }

        $searchable = $asset->getFieldValue('searchable');

        if ($searchable === null) {
            return false;
        }

        return (bool) $searchable;
    }
}