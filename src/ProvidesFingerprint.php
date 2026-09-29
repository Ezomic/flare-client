<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient;

/**
 * An exception that decides its own group in flare.
 *
 * flare normally groups on the class, the normalised message and the top
 * in-app frames. That is wrong in two directions an app can know about and
 * flare cannot: a generic wrapper that collapses every integration into one
 * group, and one bug that a refactor has split across several.
 *
 * Return a key such as "integration:stripe" and every event carrying the same
 * key in the same project, kind and source lands in one group, whatever its
 * class, message or stack. Return null to leave grouping to flare. Changing
 * the key starts a new group; the old one keeps its history.
 */
interface ProvidesFingerprint
{
    public function flareFingerprint(): ?string;
}
