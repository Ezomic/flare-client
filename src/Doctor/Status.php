<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Doctor;

enum Status: string
{
    case Ok = 'ok';

    /** Works, but will bite. */
    case Warn = 'warn';

    /** Events are being lost, or will be. */
    case Fail = 'fail';
}
