<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Heartbeat;

use Illuminate\Console\Scheduling\Event;

/**
 * The name flare knows a scheduled task by, and the readable form of it.
 *
 * An artisan command is its name and arguments, found after the `artisan`
 * token: the interpreter path in front differs between the box and a laptop,
 * and Laravel fills the description from the command class, which would make
 * the key a sentence that changes whenever someone rewords it.
 *
 * Values that change every run (dates, uuids, long numbers such as a timestamp
 * or an id) are replaced in the key only, so a task scheduled with
 * `now()->timestamp` is one task and not a new one a minute. The description
 * keeps the real arguments.
 *
 * Anything else (a callback, a shell command, a job) is known by its
 * description. An unnamed callback is just "Callback" and cannot be told from
 * the next one, so it has no key at all: name it with ->name().
 */
final class TaskKey
{
    private const GENERIC = 'Callback';

    public static function for(Event $event): ?string
    {
        $description = self::description($event);

        if ($description === self::GENERIC) {
            return null;
        }

        $key = preg_replace(
            [
                '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
                '/\b\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?)?\b/',
                '/\b\d{5,}\b/',
            ],
            ['{uuid}', '{date}', '{n}'],
            $description,
        ) ?? $description;

        return mb_substr($key, 0, 191);
    }

    public static function description(Event $event): string
    {
        $command = $event->command;

        if (is_string($command) && preg_match("/\\s'?artisan'?\\s+(.+)\$/s", $command, $match) === 1) {
            return mb_substr(trim($match[1]), 0, 255);
        }

        return mb_substr($event->getSummaryForDisplay(), 0, 255);
    }
}
