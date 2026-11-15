<?php

declare(strict_types=1);

namespace App\Support\Limits;

/**
 * The bounds a tenant may not configure past.
 *
 * ## Why this is a contract in Support and not the Platform service itself
 *
 * The code that ENFORCES a ceiling lives in the tenant plane - the SoD
 * engine, the step-up guard - and the code that STORES one lives in the
 * platform plane. Tenancy and Identity sit below Platform in the module
 * graph: Platform depends on Identity, not the reverse.
 *
 * So the enforcers depend on this interface, which lives in Support where
 * everything may reach it, and the container binds the platform-backed
 * implementation. The dependency points the right way and the tenant plane
 * never learns that a platform database exists.
 */
interface Ceilings
{
    public function int(string $key): int;

    /**
     * A ceiling that is an amount or a ratio, as a decimal STRING.
     *
     * A string, not a float. Several ceilings are money - the largest
     * residual a write-off may absorb, the largest difference an automatic
     * bank match may swallow - and the money path forbids a float end to
     * end. A ceiling compared through a float is a ceiling that admits a
     * value one ten-millionth over it, which is harmless, and a ceiling
     * read as 0.49999999999 when it says 0.50, which is not.
     */
    public function decimal(string $key): string;

    /**
     * Clamp a requested value, and report whether it was reduced.
     *
     * Both, because a caller that only receives the clamped number cannot
     * tell the user their request was cut down - and quietly giving somebody
     * less than they asked for is how a limit becomes a mystery.
     *
     * @return array{value: int, clamped: bool, ceiling: int}
     */
    public function clamp(string $key, int $requested): array;
}
