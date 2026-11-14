<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WiringRules;
use Tests\TestCase;

/**
 * That the application is wired correctly, not just that the classes exist.
 *
 * The rules live in {@see WiringRules} so bin/verify runs exactly the same
 * checks. The middleware-priority rule in particular asserts the resulting
 * POSITIONS rather than that the pinning code was called - because the pinning
 * was wrong once, silently, and a test of the call would have passed.
 */
final class WiringTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function ruleProvider(): array
    {
        $rules = [
            'tenant resolvers run before the auth guard',
            'tenant resolvers run after the session starts',
            'the tenant connection is a template with no credentials',
            'the application does not connect as the migrator',
            'problem types have unique URIs and sane statuses',
            'exchange and pdf default to their stubs',
        ];

        return array_combine($rules, array_map(static fn (string $r): array => [$r], $rules));
    }

    #[DataProvider('ruleProvider')]
    public function test_wiring_rule_holds(string $rule): void
    {
        $violations = (new WiringRules($this->app))->all()[$rule];

        $this->assertSame(
            [],
            $violations,
            sprintf("The wiring rule \"%s\" was violated:\n  - %s", $rule, implode("\n  - ", $violations)),
        );
    }
}
