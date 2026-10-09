<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Heartbeat;

use Illuminate\Console\Scheduling\Event;

/**
 * The name flare knows a scheduled task by.
 *
 * An artisan command is its name and arguments: the interpreter path in front
 * of it differs between the box and a laptop, and Laravel fills the description
 * from the command class, which would make the key a sentence that changes
 * whenever someone rewords it. Anything else (a callback, a shell command, a
 * job) is known by its description, so name those with ->name().
 */
final class TaskKey
{
    public static function for(Event $event): string
    {
        $command = $event->command;

        if (is_string($command) && preg_match("/artisan'?\\s+(.+)\$/s", $command, $match) === 1) {
            return mb_substr(trim($match[1]), 0, 191);
        }

        return mb_substr($event->getSummaryForDisplay(), 0, 191);
    }
}
