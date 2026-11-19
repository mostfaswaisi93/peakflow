<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

/**
 * What is kept, whatever the rate - PF-240.
 *
 * ## The two exceptions are the whole point
 *
 * A sampled trace is a statistical statement about a population, which is the
 * right thing for a page render and the wrong thing for a payment release. A
 * sampled-away payment release is the one trace you needed, and you find out
 * it was sampled away at the moment somebody asks what happened to the money.
 *
 * So `telemetry.sampling.always_sample` names the operations that are kept at
 * any rate, including a rate of zero, and `errors` is one of its entries. The
 * list is configuration rather than code because which operations move money
 * is a product fact that changes as modules land - and it is read here rather
 * than copied, so a name added to the config is kept without a deployment of
 * new code.
 *
 * ## An error is decided at the END and the span was decided at the START
 *
 * Which is the awkward part of head sampling and the reason `keepBecauseOf()`
 * exists separately. A span that starts unsampled and then fails has to be
 * kept, so the tracer asks again when it closes. The alternative - sampling
 * only at the end - would mean holding every span of every request in memory
 * until it finished, which is a memory profile nobody wants on the request
 * that is already going wrong.
 *
 * ## Per-mille integers, not a float comparison
 *
 * `OTEL_TRACES_SAMPLER_ARG` is a ratio and arrives as one. It is converted
 * once, here, into whole parts per thousand and compared against
 * `random_int()`: a rate of 0.1 is "100 in 1000", which is exact, orderable
 * and reproducible in a check. Comparing `mt_rand() / mt_getrandmax()`
 * against a float is the ordinary implementation and it cannot be asserted
 * about at the boundaries, because 0.0 and 1.0 are the two values a reader
 * most wants to be sure of.
 */
final class Sampler
{
    private const PER_MILLE = 1000;

    /**
     * @param  list<string>  $alwaysSample
     */
    public function __construct(
        private readonly array $alwaysSample,
        private readonly int $ratePerMille,
    ) {}

    public static function fromConfig(): self
    {
        $always = config('telemetry.sampling.always_sample');
        $ratio = config('telemetry.sampling.ratio');

        /*
         | Clamped, and a value outside 0..1 is read as 1 rather than
         | refused.
         |
         | A misconfigured sample rate must not stop the application booting,
         | and the safe direction is "keep everything": a trace too many is a
         | disk bill, and a trace too few is an incident nobody can explain.
         */
        $perMille = is_numeric($ratio) ? (int) round($ratio * self::PER_MILLE) : self::PER_MILLE;

        return new self(
            alwaysSample: is_array($always)
                ? array_values(array_map(static fn (mixed $v): string => (string) $v, $always))
                : [],
            ratePerMille: max(0, min(self::PER_MILLE, $perMille)),
        );
    }

    /** Whether this operation is kept at any rate. */
    public function isAlwaysSampled(string $operation): bool
    {
        return in_array($operation, $this->alwaysSample, true);
    }

    public function keepsErrors(): bool
    {
        return in_array('errors', $this->alwaysSample, true);
    }

    /** The head decision, made when the span starts. */
    public function shouldSample(string $operation): bool
    {
        if ($this->isAlwaysSampled($operation)) {
            return true;
        }

        if ($this->ratePerMille <= 0) {
            return false;
        }

        if ($this->ratePerMille >= self::PER_MILLE) {
            return true;
        }

        return random_int(1, self::PER_MILLE) <= $this->ratePerMille;
    }

    /**
     * The second decision, made when the span closes.
     *
     * An unsampled span that ended in an error is kept - which is what
     * "always keep errors, whatever the rate" means, and is impossible to
     * implement at the start of a span because nothing has gone wrong yet.
     */
    public function keepAfterTheFact(string $operation, bool $sampled, bool $isError): bool
    {
        if ($sampled) {
            return true;
        }

        if ($isError && $this->keepsErrors()) {
            return true;
        }

        return $this->isAlwaysSampled($operation);
    }

    public function ratePerMille(): int
    {
        return $this->ratePerMille;
    }

    /** @return list<string> */
    public function alwaysSampled(): array
    {
        return $this->alwaysSample;
    }
}
