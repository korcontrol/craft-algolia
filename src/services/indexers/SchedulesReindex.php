<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services\indexers;

use craft\base\Element;

/**
 * Implemented by indexers whose records go out of date with time alone, with
 * no save to trigger a re-index. The plugin queues a delayed re-index for the
 * returned date (see IndexingService::scheduleStatusTransitionJob()).
 */
interface SchedulesReindex
{
    /**
     * When the element's record should next be rebuilt, or null if it stays
     * current until the element is saved again.
     */
    public function getNextReindexDate(Element $element): ?\DateTimeInterface;
}
