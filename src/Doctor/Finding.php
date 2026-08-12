<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Doctor;

final class Finding
{
    public function __construct(
        public readonly Status $status,
        public readonly string $label,
        public readonly string $detail,
    ) {}

    public static function ok(string $label, string $detail): self
    {
        return new self(Status::Ok, $label, $detail);
    }

    public static function warn(string $label, string $detail): self
    {
        return new self(Status::Warn, $label, $detail);
    }

    public static function fail(string $label, string $detail): self
    {
        return new self(Status::Fail, $label, $detail);
    }
}
