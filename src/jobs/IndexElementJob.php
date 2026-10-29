<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\jobs;

use Craft;
use craft\queue\BaseJob;
use korcontrol\craftalgolia\services\IndexingService;

/**
 * Re-indexes a single element in Algolia. Pushed with a delay matching when
 * the element's record next goes out of date on its own: an entry's next
 * status transition (postDate/expiryDate), since Craft fires no event when
 * those dates pass, or a recurring event running out of indexed dates.
 */
final class IndexElementJob extends BaseJob
{
    public int $elementId;

    public int $siteId;

    /**
     * When the job was pushed (Unix time). Null for jobs queued before this
     * was added.
     */
    public ?int $queuedAt = null;

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

        // Queue the follow-up job (a pending entry that just went live may
        // also carry an expiryDate; a recurring event needs its next top-up),
        // unless a later save has already queued its own
        if (self::shouldReschedule($element->dateUpdated, $this->queuedAt)) {
            IndexingService::scheduleStatusTransitionJob($element, $algoliaConfig);
        }
    }

    /**
     * Whether this job should queue the next one. Every save queues a job,
     * and a recurring event's jobs re-queue indefinitely, so only the chain
     * started by the latest save carries on: once the element has been saved
     * after this job was queued, that save's job takes over.
     */
    public static function shouldReschedule(?\DateTimeInterface $dateUpdated, ?int $queuedAt): bool
    {
        if ($queuedAt === null || $dateUpdated === null) {
            return true;
        }

        return $dateUpdated->getTimestamp() <= $queuedAt;
    }

    protected function defaultDescription(): ?string
    {
        return "Re-indexing element {$this->elementId} (site {$this->siteId}) in Algolia";
    }
}
