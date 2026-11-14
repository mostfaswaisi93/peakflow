<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WorkedExamples;
use Tests\TestCase;

/**
 * The specification's worked examples, under a test runner.
 *
 * Every case here appears in docs/ as prose with an expected number. This is
 * what keeps the prose honest: if the documentation says a JOD invoice stores
 * 42.9629 and displays 42.963, that is asserted against the real code.
 *
 * Extends the framework TestCase because the examples read `config('money.*')`
 * - the rounding policy and the apportionment rule are data, not constants.
 */
final class WorkedExamplesTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function exampleProvider(): array
    {
        $examples = array_keys((new WorkedExamples())->all());

        return array_combine(
            $examples,
            array_map(static fn (string $name): array => [$name], $examples),
        );
    }

    #[DataProvider('exampleProvider')]
    public function test_worked_example_matches_the_specification(string $example): void
    {
        $violations = (new WorkedExamples())->all()[$example];

        $this->assertSame(
            [],
            $violations,
            sprintf(
                "The worked example \"%s\" does not match the specification:\n  - %s",
                $example,
                implode("\n  - ", $violations),
            ),
        );
    }
}
