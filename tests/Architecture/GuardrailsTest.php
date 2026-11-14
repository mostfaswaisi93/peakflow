<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArchitectureRules;

/**
 * The guardrails, under a test runner (PF-005).
 *
 * The rules themselves live in {@see ArchitectureRules} so that `bin/verify`
 * can run exactly the same checks without a test runner at all. One
 * implementation, two runners - which is what keeps the checks runnable in an
 * environment where the dev dependencies cannot be installed.
 */
final class GuardrailsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function ruleProvider(): array
    {
        $rules = array_keys((new ArchitectureRules(self::basePath()))->all());

        return array_combine(
            $rules,
            array_map(static fn (string $rule): array => [$rule], $rules),
        );
    }

    #[DataProvider('ruleProvider')]
    public function test_architecture_rule_holds(string $rule): void
    {
        $violations = (new ArchitectureRules(self::basePath()))->all()[$rule];

        $this->assertSame(
            [],
            $violations,
            sprintf(
                "The rule \"%s\" was violated %d time(s):\n  - %s",
                $rule,
                count($violations),
                implode("\n  - ", $violations),
            ),
        );
    }

    private static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
