<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Enums;

enum Source: string
{
    case Http = 'http';
    case Job = 'job';
    case Schedule = 'schedule';
    case Console = 'console';
    case Log = 'log';
}
