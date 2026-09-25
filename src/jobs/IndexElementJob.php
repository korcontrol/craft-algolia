<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\jobs;

use Craft;
use craft\queue\BaseJob;
use korcontrol\craftalgolia\services\IndexingService;

/**
 * Re-indexes a single element in Algolia. Pushed with a delay matching the
 * element's next status transition (postDate/expiryDate), since Craft fires
 * no event when those dates pass — status is computed at query time.
 */
final class IndexElementJob extends BaseJob
{
    public int $elementId;

    public int $siteId;

    public function execute($queue): void
    {
        $element = Craft::$app->getElements()->getElementById($this->elementId, null, $this->siteId);

        if ($element === null) {
            // Deleted since the job was queued; the after-delete handler
            // already removed it from the index.
            return;
        }

        $algoliaConfig = Craft::$app->getConfig()->getConfigFromFile('algolia');

        IndexingService::indexElement($element, $algoliaConfig);

        // A pending entry that just went live may also carry an expiryDate;
        // queue the follow-up transition job for it.
        IndexingService::scheduleStatusTransitionJob($element, $algoliaConfig);
    }

    protected function defaultDescription(): ?string
    {
        return "Re-indexing element {$this->elementId} (site {$this->siteId}) in Algolia";
    }
}
