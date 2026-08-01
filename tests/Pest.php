<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Thijssensoftware\FlareClient\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

// The spool writes to the local disk. Faking it per test keeps one test's
// undelivered events from showing up as another's, which otherwise makes the
// "nothing was spooled" assertions quietly meaningless.
uses()->beforeEach(function (): void {
    Storage::fake('local');
})->in(__DIR__);
