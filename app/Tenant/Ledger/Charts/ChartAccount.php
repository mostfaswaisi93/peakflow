<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Charts;

use App\Tenant\Ledger\Charts\Exceptions\InvalidChartTemplate;
use App\Tenant\Ledger\Enums\AccountType;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Enums\StatementLine;

/**
 * One account in a chart template - PF-039.
 *
 * ## `monetary` is required, and may not be inferred
 *
 * IAS 21 restates monetary items at the closing rate and leaves non-monetary
 * items alone, and the classification cannot be derived from the account
 * type: property and receivables are both assets. A template that omitted it
 * would be choosing a default, and a default is wrong in whichever direction
 * it points - silently, in a figure nobody recomputes.
 *
 * So the loader refuses a row without it rather than assuming. The two rows
 * worth reading twice in any template are deferred revenue, which is NOT
 * monetary because it is settled by delivering goods rather than paying
 * money, and suspense, which is not monetary because revaluing an
 * unidentified item asserts a classification nobody has made.
 */
final readonly class ChartAccount
{
    public function __construct(
        public string $code,
        public ?string $parent,
        public AccountType $type,
        public string $nameAr,
        public string $nameEn,
        public bool $postable,
        public ?ControlRole $role,
        public bool $monetary,
        public ?StatementLine $statementLine,
        public ?string $note,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromArray(string $template, array $row): self
    {
        $code = (string) ($row['code'] ?? '');

        if ($code === '') {
            throw InvalidChartTemplate::because($template, 'an account has no code');
        }

        foreach (['type', 'name_ar', 'name_en'] as $required) {
            if (($row[$required] ?? '') === '') {
                throw InvalidChartTemplate::because(
                    $template,
                    sprintf('account %s has no %s', $code, $required),
                );
            }
        }

        /*
         | `array_key_exists`, not `??`.
         |
         | `false` is a meaningful value for both of these and `??` cannot
         | tell it from absent - which would turn "somebody forgot to
         | classify this" into "this is not monetary", the exact silent
         | default this class exists to refuse.
         */
        foreach (['postable', 'monetary'] as $required) {
            if (! array_key_exists($required, $row)) {
                throw InvalidChartTemplate::because(
                    $template,
                    sprintf(
                        'account %s does not say whether it is %s. It cannot be inferred: %s',
                        $code,
                        $required,
                        $required === 'monetary'
                            ? 'property and receivables are both assets, and only one of them is restated '.
                              'at the closing rate'
                            : 'a group account and a posting account look identical in a tree',
                    ),
                );
            }
        }

        $type = AccountType::tryFrom((string) $row['type']);

        if ($type === null) {
            throw InvalidChartTemplate::because(
                $template,
                sprintf('account %s has type "%s", which is not one of the five', $code, (string) $row['type']),
            );
        }

        $role = null;

        if (($row['role'] ?? null) !== null) {
            $role = ControlRole::tryFrom((string) $row['role']);

            if ($role === null) {
                throw InvalidChartTemplate::because(
                    $template,
                    sprintf('account %s claims the role "%s", which does not exist', $code, (string) $row['role']),
                );
            }
        }

        /*
         | The statement line, required of a POSTABLE account.
         |
         | A group account carries none: it is structure, and a heading that
         | claimed a line would be added to the line its own children are
         | already on - which doubles the section and still balances.
         |
         | A postable account without one would be a balance the statement
         | cannot place. PF-184 asks for those to appear in an explicit
         | "unclassified" section rather than be dropped, and they will -
         | but a SHIPPED template reaching that state is a template bug, so
         | the loader refuses it here and the unclassified section exists
         | for charts a customer has edited.
         */
        $statementLine = null;

        if (($row['statement_line'] ?? null) !== null) {
            $statementLine = StatementLine::tryFrom((string) $row['statement_line']);

            if ($statementLine === null) {
                throw InvalidChartTemplate::because(
                    $template,
                    sprintf(
                        'account %s claims the statement line "%s", which is not one of the IAS 1 lines. '.
                        'The set is closed on purpose: an open one lets two entities in a group classify '.
                        'the same account differently and the consolidation adds them under two headings '.
                        'that look like two things',
                        $code,
                        (string) $row['statement_line'],
                    ),
                );
            }

            if (! \in_array($type, $statementLine->allowedTypes(), true)) {
                throw InvalidChartTemplate::because(
                    $template,
                    sprintf(
                        'account %s is of type "%s" and claims the statement line "%s", which is a %s '.
                        'line. A balance presented on the wrong side of the statement is a statement that '.
                        'does not balance, and the report would be the one to discover it',
                        $code,
                        $type->value,
                        $statementLine->value,
                        $statementLine->section()->value,
                    ),
                );
            }
        } elseif ($row['postable'] === true) {
            throw InvalidChartTemplate::because(
                $template,
                sprintf(
                    'account %s is postable and names no statement line, so nothing can place its balance '.
                    'on a statement. It cannot be inferred from the type: cost of sales and '.
                    'administrative expenses are both expenses and are never added together',
                    $code,
                ),
            );
        }

        if ($statementLine !== null && $row['postable'] !== true) {
            throw InvalidChartTemplate::because(
                $template,
                sprintf(
                    'account %s is a group account and claims the statement line "%s". Its children carry '.
                    'the lines; a heading that carried one too would be added to the same section twice, '.
                    'and the statement would still balance',
                    $code,
                    $statementLine->value,
                ),
            );
        }

        if ($role !== null && $row['postable'] !== true) {
            throw InvalidChartTemplate::because(
                $template,
                sprintf(
                    'account %s carries the role "%s" and is not postable. A control role on a group '.
                    'account resolves to something nothing can post to, and the failure arrives at the '.
                    'first posting rather than here',
                    $code,
                    $role->value,
                ),
            );
        }

        return new self(
            code: $code,
            parent: ($row['parent'] ?? null) === null ? null : (string) $row['parent'],
            type: $type,
            nameAr: (string) $row['name_ar'],
            nameEn: (string) $row['name_en'],
            postable: (bool) $row['postable'],
            role: $role,
            monetary: (bool) $row['monetary'],
            statementLine: $statementLine,
            note: ($row['note'] ?? null) === null ? null : (string) $row['note'],
        );
    }
}
