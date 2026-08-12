<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Console;

use Illuminate\Console\Command;
use Thijssensoftware\FlareClient\Doctor\Doctor;
use Thijssensoftware\FlareClient\Doctor\Finding;
use Thijssensoftware\FlareClient\Doctor\Status;

/**
 * Answers "will this install deliver anything", which flare:test does not.
 *
 * Exits non-zero on anything that means events are being lost, so it can be
 * the last step of a deploy rather than something somebody remembers to run.
 */
class DoctorCommand extends Command
{
    protected $signature = 'flare:doctor';

    protected $description = 'Check that this app can actually deliver events to flare';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->run();

        foreach ($findings as $finding) {
            $this->line(sprintf(
                '  %s  %-10s %s',
                $this->marker($finding->status),
                $finding->label,
                $finding->detail,
            ));
        }

        $this->newLine();

        $failed = array_filter($findings, fn (Finding $f): bool => $f->status === Status::Fail);

        if ($failed !== []) {
            $this->error(sprintf('%d check(s) mean events are being lost.', count($failed)));

            return self::FAILURE;
        }

        $warnings = array_filter($findings, fn (Finding $f): bool => $f->status === Status::Warn);

        if ($warnings !== []) {
            $this->warn(sprintf('%d check(s) will bite later.', count($warnings)));

            return self::SUCCESS;
        }

        $this->info('This app can deliver to flare.');

        return self::SUCCESS;
    }

    private function marker(Status $status): string
    {
        return match ($status) {
            Status::Ok => '<fg=green>ok  </>',
            Status::Warn => '<fg=yellow>warn</>',
            Status::Fail => '<fg=red>fail</>',
        };
    }
}
