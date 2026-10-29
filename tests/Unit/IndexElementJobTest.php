<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit;

use Codeception\Test\Unit;
use korcontrol\craftalgolia\jobs\IndexElementJob;
use Tests\Support\UnitTester;

class IndexElementJobTest extends Unit
{
    protected UnitTester $tester;

    // shouldReschedule()

    public function test_reschedules_when_job_is_the_latest_scheduled(): void
    {
        $this->assertTrue(IndexElementJob::shouldReschedule('abc', 'abc'));
    }

    public function test_does_not_reschedule_when_a_later_job_was_scheduled(): void
    {
        // The later job carries the chain on
        $this->assertFalse(IndexElementJob::shouldReschedule('abc', 'def'));
    }

    public function test_reschedules_when_no_latest_token_is_stored(): void
    {
        // e.g. the cache was cleared; at worst a duplicate runs until the next
        // scheduling supersedes it
        $this->assertTrue(IndexElementJob::shouldReschedule('abc', null));
    }

    public function test_reschedules_jobs_queued_without_a_token(): void
    {
        // Entry transition jobs queued before tokens existed
        $this->assertTrue(IndexElementJob::shouldReschedule(null, 'def'));
    }
}
