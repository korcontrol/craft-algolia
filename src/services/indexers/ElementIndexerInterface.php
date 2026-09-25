<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use craft\base\Element;

interface ElementIndexerInterface
{
    public function supports(Element $element): bool;
    public function shouldIndex(Element $element): bool;
    public function getIndexData(Element $element): array;
}