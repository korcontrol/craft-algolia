<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit;

use Codeception\Test\Unit;
use craft\elements\Entry;
use korcontrol\craftalgolia\services\IndexingService;
use Tests\Support\UnitTester;

class IndexingServiceTest extends Unit
{
    protected UnitTester $tester;

    private function makeEntry(
        string $status = Entry::STATUS_LIVE,
        ?\DateTime $postDate = null,
        ?\DateTime $expiryDate = null,
    ): Entry {
        return $this->make(Entry::class, [
            'getStatus'  => $status,
            'postDate'   => $postDate,
            'expiryDate' => $expiryDate,
        ]);
    }

    // getNextTransitionDate()

    public function test_pending_entry_with_post_date_returns_post_date(): void
    {
        $postDate = new \DateTime('+2 days');
        $entry = $this->makeEntry(Entry::STATUS_PENDING, postDate: $postDate);

        $this->assertSame($postDate, IndexingService::getNextTransitionDate($entry));
    }

    public function test_pending_entry_without_post_date_returns_null(): void
    {
        $entry = $this->makeEntry(Entry::STATUS_PENDING);

        $this->assertNull(IndexingService::getNextTransitionDate($entry));
    }

    public function test_live_entry_with_expiry_date_returns_expiry_date(): void
    {
        $expiryDate = new \DateTime('+1 week');
        $entry = $this->makeEntry(Entry::STATUS_LIVE, expiryDate: $expiryDate);

        $this->assertSame($expiryDate, IndexingService::getNextTransitionDate($entry));
    }

    public function test_live_entry_without_expiry_date_returns_null(): void
    {
        $entry = $this->makeEntry(Entry::STATUS_LIVE, postDate: new \DateTime('-1 week'));

        $this->assertNull(IndexingService::getNextTransitionDate($entry));
    }

    public function test_pending_entry_with_both_dates_returns_post_date(): void
    {
        $postDate = new \DateTime('+2 days');
        $expiryDate = new \DateTime('+1 month');
        $entry = $this->makeEntry(Entry::STATUS_PENDING, postDate: $postDate, expiryDate: $expiryDate);

        $this->assertSame($postDate, IndexingService::getNextTransitionDate($entry));
    }

    public function test_expired_entry_returns_null(): void
    {
        $entry = $this->makeEntry(
            Entry::STATUS_EXPIRED,
            postDate: new \DateTime('-1 month'),
            expiryDate: new \DateTime('-1 week'),
        );

        $this->assertNull(IndexingService::getNextTransitionDate($entry));
    }

    public function test_disabled_entry_returns_null(): void
    {
        $entry = $this->makeEntry(
            Entry::STATUS_DISABLED,
            postDate: new \DateTime('+2 days'),
            expiryDate: new \DateTime('+1 month'),
        );

        $this->assertNull(IndexingService::getNextTransitionDate($entry));
    }
}
