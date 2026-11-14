<?php

namespace Modules\Sviat\Ukrposhta\Backend;

use Okay\Modules\Sviat\Ukrposhta\Backend\DictionaryStatusView;
use Okay\Modules\Sviat\Ukrposhta\Dictionaries\DictionaryStatus;
use PHPUnit\Framework\TestCase;

class DictionaryStatusViewTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    public function testWithoutBearerTheButtonIsOffAndNothingLooksQueued(): void
    {
        $status = new DictionaryStatus();
        $status->requestedAt = self::NOW - 60;

        $vars = DictionaryStatusView::vars($status, self::NOW, [], false);

        self::assertSame('no_keys', $vars['state']);
        self::assertFalse($vars['busy']);
        self::assertFalse($vars['can_run']);
        self::assertSame('warning', $vars['tone']);
    }

    public function testWithBearerAFreshRequestIsQueued(): void
    {
        $status = new DictionaryStatus();
        $status->requestedAt = self::NOW - 60;

        $vars = DictionaryStatusView::vars($status, self::NOW, [], true);

        self::assertSame('queued', $vars['state']);
        self::assertTrue($vars['busy']);
        self::assertFalse($vars['can_run']);
    }

    public function testWithBearerAndNothingPendingTheButtonWorks(): void
    {
        self::assertTrue(DictionaryStatusView::vars(new DictionaryStatus(), self::NOW, [], true)['can_run']);
    }

    public function testRunningStaysRunningEvenIfTheBearerWasClearedMeanwhile(): void
    {
        $status = new DictionaryStatus();
        $status->runningSince = self::NOW - 60;

        self::assertSame('running', DictionaryStatusView::vars($status, self::NOW, [], false)['state']);
    }

    public function testRefusedBearerShowsTheFailure(): void
    {
        $status = new DictionaryStatus();
        $status->lastRun = ['started' => self::NOW - 100, 'finished' => self::NOW - 90, 'status' => DictionaryStatus::FAILED,
            'counts' => [], 'skipped' => [], 'unresolved' => [], 'error' => 'HTTP 401'];

        $vars = DictionaryStatusView::vars($status, self::NOW, [], true);

        self::assertSame('error', $vars['state']);
        self::assertSame('HTTP 401', $vars['error']);
    }
}
