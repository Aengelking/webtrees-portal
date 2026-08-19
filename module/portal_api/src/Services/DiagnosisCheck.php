<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

/**
 * One line of the diagnosis screen.
 *
 * `advice` is what to do about it, in a sentence, and is the reason this is
 * an object rather than a status and a label. A screen that says "Problem:
 * database tables" and leaves the reader to work out the rest is a screen
 * that gets read once.
 */
final class DiagnosisCheck
{
    public function __construct(
        public readonly string $key,
        /** One of Diagnosis::OK, ::WARNING, ::PROBLEM. */
        public readonly string $status,
        public readonly string $label,
        public readonly string $detail,
        public readonly string $advice,
    ) {
    }
}
