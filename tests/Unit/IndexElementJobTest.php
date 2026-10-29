<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit;

use Codeception\Test\Unit;
use korcontrol\craftalgolia\jobs\IndexElementJob;
use Tests\Support\UnitTester;

class IndexElementJobTest extends Unit
{
    protected UnitTester $tester;

    private const QUEUED_AT = 1793000000;

    // shouldReschedule()

    public function test_reschedules_when_element_was_saved_before_the_job_was_queued(): void
    {
        $this->assertTrue(IndexElementJob::shouldReschedule(new \DateTime('@' . (self::QUEUED_AT - 60)), self::QUEUED_AT));
    }

    public function test_reschedules_when_element_was_saved_in_the_same_second(): void
    {
        // A save queues its job straight away, usually within the same second
        $this->assertTrue(IndexElementJob::shouldReschedule(new \DateTime('@' . self::QUEUED_AT), self::QUEUED_AT));
    }

    public function test_does_not_reschedule_when_element_was_saved_since(): void
    {
        // That save queued its own job, which carries the chain on
        $this->assertFalse(IndexElementJob::shouldReschedule(new \DateTime('@' . (self::QUEUED_AT + 60)), self::QUEUED_AT));
    }

    public function test_reschedules_jobs_queued_before_queued_at_existed(): void
    {
        $this->assertTrue(IndexElementJob::shouldReschedule(new \DateTime('@' . (self::QUEUED_AT + 60)), null));
    }

    public function test_reschedules_when_element_has_no_date_updated(): void
    {
        $this->assertTrue(IndexElementJob::shouldReschedule(null, self::QUEUED_AT));
    }
}
