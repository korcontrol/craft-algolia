<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\services;

use Craft;
use craft\base\Element as BaseElement;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\helpers\ElementHelper;
use korcontrol\craftalgolia\jobs\IndexElementJob;
use korcontrol\craftalgolia\Plugin;
use korcontrol\craftalgolia\services\indexers\IndexerFactory;
use Throwable;
use yii\base\Event;

final class IndexingService
{
    /**
     * Seconds added to a transition job's delay so it runs after the
     * postDate/expiryDate has definitely passed, not a moment before.
     */
    private const TRANSITION_DELAY_BUFFER = 30;

    public static function handleAfterSaveEvent(ModelEvent $event, array $algoliaConfig = []): void
    {
        $element = $event->sender;
        Craft::info('[ALGOLIA] handleAfterSaveEvent fired for element ID: ' . ($element->id ?? 'null'), __METHOD__);
        if (!$element instanceof BaseElement) {
            return;
        }

        if (ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        self::indexElement($element, $algoliaConfig);

        // A pending entry fails shouldIndex() today but must be indexed when
        // its postDate arrives; a live entry must be removed when it expires.
        self::scheduleStatusTransitionJob($element, $algoliaConfig);
    }

    /**
     * Queues a delayed IndexElementJob for the element's next status
     * transition (see getNextTransitionDate()). Duplicate or stale jobs are
     * harmless: the job re-evaluates status when it runs.
     */
    public static function scheduleStatusTransitionJob(BaseElement $element, array $algoliaConfig = []): void
    {
        if (!$element instanceof Entry) {
            return;
        }

        $elementId = (int) ($element->id ?? 0);
        if ($elementId <= 0) {
            return;
        }

        if (!Plugin::getInstance()->client->isConfigured()) {
            return;
        }

        if ((new IndexerFactory($algoliaConfig))->getIndexerForElement($element) === null) {
            return;
        }

        $transitionDate = self::getNextTransitionDate($element);
        if ($transitionDate === null) {
            return;
        }

        $delay = max(0, $transitionDate->getTimestamp() - time()) + self::TRANSITION_DELAY_BUFFER;

        Craft::$app->getQueue()
            ->delay($delay)
            ->push(new IndexElementJob([
                'elementId' => $elementId,
                'siteId' => (int) $element->siteId,
            ]));

        Craft::info(
            "[ALGOLIA] Queued re-index of element {$elementId} (site {$element->siteId}) in {$delay}s for status transition",
            __METHOD__
        );
    }

    /**
     * Deletes the element's objects from Algolia, then re-adds them if the
     * element's indexer says it should currently be indexed. Idempotent, and
     * re-evaluates status at call time — safe to re-run from a delayed queue
     * job even if the element changed since the job was pushed.
     */
    public static function indexElement(BaseElement $element, array $algoliaConfig = []): void
    {
        $client = Plugin::getInstance()->client;
        if (!$client->isConfigured()) {
            return;
        }

        $indexName = $client->getIndex();

        $elementId = (int) ($element->id ?? 0);
        if ($elementId <= 0) {
            Craft::warning('[ALGOLIA] Save skipped: element has no id', __METHOD__);
            return;
        }

        $siteId = (int) $element->siteId;

        self::deleteByElementIdAndSiteId($client, $indexName, $elementId, $siteId);

        $indexer = (new IndexerFactory($algoliaConfig))->getIndexerForElement($element);

        if ($indexer === null) {
            return;
        }

        if (!$indexer->shouldIndex($element)) {
            return;
        }

        $indexData = $indexer->getIndexData($element);
        $indexData['siteID'] = $siteId;

        $site = Craft::$app->sites->getSiteById($siteId);
        $indexData['siteHandle'] = $site?->handle;

        $indexData['elementID'] = $indexData['elementID'] ?? (string) $elementId;
        $indexData['objectID'] = (string) ($elementId . '-' . $siteId);

        foreach (self::chunkBodyData($indexData) as $chunk) {
            try {
                $client->getWriteClient()->saveObject($indexName, $chunk);
            } catch (Throwable $e) {
                Craft::error('[ALGOLIA] saveObject error: ' . $e->getMessage(), __METHOD__);
            }
        }
    }

    /**
     * Returns the date of the entry's next status transition that Algolia
     * needs to react to: the postDate while the entry is pending (it will
     * become live), or the expiryDate while it is live (it will expire).
     * Craft fires no event when these dates pass, so the caller schedules a
     * delayed re-index job for the returned date.
     */
    public static function getNextTransitionDate(Entry $entry): ?\DateTimeInterface
    {
        return match ($entry->getStatus()) {
            Entry::STATUS_PENDING => $entry->postDate,
            Entry::STATUS_LIVE => $entry->expiryDate,
            default => null,
        };
    }

    public static function handleAfterDeleteEvent(Event $event): void
    {
        $element = $event->sender;
        Craft::info('[ALGOLIA] handleAfterDeleteEvent fired for element ID: ' . ($element->id ?? 'null'), __METHOD__);
        if (!$element instanceof BaseElement) {
            return;
        }

        $client = Plugin::getInstance()->client;
        if (!$client->isConfigured()) {
            return;
        }

        $elementId = (int) ($element->id ?? 0);
        if ($elementId <= 0) {
            Craft::warning('[ALGOLIA] Delete skipped: element has no id', __METHOD__);
            return;
        }

        self::deleteByElementId($client, $client->getIndex(), $elementId);
    }

    private static function deleteByElementIdAndSiteId(
        ClientService $client,
        string $indexName,
        int $elementId,
        int $siteId
    ): void {
        try {
            $client->getWriteClient()->deleteBy($indexName, [
                'filters' => "elementID:'{$elementId}' AND siteID={$siteId}",
            ]);
        } catch (Throwable $e) {
            Craft::error('[ALGOLIA][DELETE] deleteBy error: ' . $e->getMessage(), __METHOD__);
        }
    }

    private static function deleteByElementId(
        ClientService $client,
        string $indexName,
        int $elementId
    ): void {
        try {
            $client->getWriteClient()->deleteBy($indexName, [
                'filters' => "elementID:'{$elementId}'",
            ]);
        } catch (Throwable $e) {
            Craft::error('[ALGOLIA][DELETE] deleteBy error: ' . $e->getMessage(), __METHOD__);
        }
    }

    private static function chunkBodyData(array $indexData): array
    {
        if (!isset($indexData['body'])) {
            return [$indexData];
        }

        $body = self::normalizeWhitespace((string) $indexData['body']);
        if ($body === '') {
            $indexData['body'] = '';
            return [$indexData];
        }

        $max = 8000;
        $probe = $indexData;
        $probe['body'] = $body;

        if (self::objectToSize($probe) < $max) {
            $indexData['body'] = $body;
            return [$indexData];
        }

        $chunks = self::textChunksAscii($body, $max);
        if (!$chunks) {
            $indexData['body'] = substr($body, 0, $max);
            return [$indexData];
        }

        $out = [];
        foreach ($chunks as $i => $chunk) {
            $piece = $indexData;
            $piece['body'] = $chunk;
            $piece['objectID'] = $indexData['objectID'] . '-' . $i;
            $out[] = $piece;
        }

        return $out;
    }

    private static function textChunksAscii(string $text, int $maxChunkSize): array
    {
        $chunks = [];
        $len = strlen($text);
        $offset = 0;

        while ($offset < $len) {
            $remaining = $len - $offset;
            $take = min($maxChunkSize, $remaining);
            $sliceRaw = substr($text, $offset, $take);
            $consumedLen = strlen($sliceRaw);

            if ($offset + $take < $len) {
                $breakAt = strrpos($sliceRaw, ' ');
                if ($breakAt !== false && $breakAt > 0) {
                    $consumedLen = $breakAt + 1;
                    $sliceRaw = substr($sliceRaw, 0, $consumedLen);
                }
            }

            $slice = trim($sliceRaw);

            if ($slice === '') {
                $offset += max($consumedLen, 1);
                continue;
            }

            $chunks[] = $slice;
            $offset += $consumedLen;
        }

        return $chunks;
    }

    private static function normalizeWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    private static function objectToSize(array $data): int
    {
        return strlen(json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}