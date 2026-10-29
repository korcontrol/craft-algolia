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
     * Identifies this job as the latest scheduled for its element and site
     * (see IndexingService::reindexTokenKey()). Null for jobs queued before
     * tokens were added.
     */
    public ?string $token = null;

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
        // unless a later save or resave has already queued its own
        $latestToken = Craft::$app->getCache()->get(IndexingService::reindexTokenKey($this->elementId, $this->siteId));
        if (self::shouldReschedule($this->token, $latestToken === false ? null : $latestToken)) {
            IndexingService::scheduleReindexJob($element, $algoliaConfig);
        }
    }

    /**
     * Whether this job should queue the next one. Every save (and resave)
     * queues a job, and a recurring event's jobs re-queue indefinitely, so
     * only the most recently scheduled job for the element and site carries
     * the chain on. Without a stored token (e.g. after a cache clear) it
     * carries on too: a duplicate chain at worst, until the next scheduling
     * supersedes it.
     */
    public static function shouldReschedule(?string $jobToken, ?string $latestToken): bool
    {
        if ($jobToken === null || $latestToken === null) {
            return true;
        }

        return $jobToken === $latestToken;
    }

    protected function defaultDescription(): ?string
    {
        return "Re-indexing element {$this->elementId} (site {$this->siteId}) in Algolia";
    }
}
