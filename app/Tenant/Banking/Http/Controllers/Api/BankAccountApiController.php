<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Http\Controllers\Api;

use App\Support\Http\Api\Wire;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Services\BankBalances;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The company's own bank accounts and where each one stands - PF-262.
 *
 * The browser's bank-accounts page as data: the same `bank.manage` gate, the
 * same {@see BankBalances} service, and the same masking. The identifier is
 * the stored tail - never `iban()` or `accountNumber()`, which decrypt.
 *
 * ## Three balances, because they answer three questions
 *
 * `ledger` is what the books say, `statement` is what the bank last said, and
 * `available` is the statement less the payments that have left the books
 * and not yet reached the bank. An app that showed one number would be
 * choosing which question to answer on the person's behalf.
 *
 * Not paginated, like the screen: a company has a handful of accounts, and a
 * page boundary through them would only make the total harder to read.
 */
final class BankAccountApiController
{
    public function __construct(private readonly BankBalances $balances) {}

    public function __invoke(): JsonResponse
    {
        Gate::authorize('bank.manage');

        $data = BankAccount::query()->orderBy('label')->get()
            ->map(function (BankAccount $account): array {
                $balances = $this->balances->all($account);
                $currency = \is_string($balances['currency'] ?? null) ? $balances['currency'] : (string) $account->currency;
                $reconciliation = \is_array($balances['reconciliation'] ?? null) ? $balances['reconciliation'] : [];

                $tail = \is_string($account->iban_tail) && $account->iban_tail !== ''
                    ? $account->iban_tail
                    : (\is_string($account->domestic_identifier_tail) ? $account->domestic_identifier_tail : '');

                return [
                    'resource' => 'bank_account',
                    'id' => (string) $account->id,
                    'label' => (string) $account->label,
                    'status' => (string) $account->status,
                    'institution_name' => \is_string($account->institution_name) ? $account->institution_name : null,
                    'institution_country' => \is_string($account->institution_country) ? $account->institution_country : null,
                    'currency' => $currency,
                    'masked' => $tail === '' ? null : '···· '.$tail,
                    'as_of' => Wire::accountingDate(self::string($balances['as_of'] ?? null)),
                    'ledger' => self::money($balances['ledger'] ?? null, $currency),
                    'statement' => self::money($balances['statement'] ?? null, $currency),
                    'available' => self::money($balances['available'] ?? null, $currency),
                    'reconciliation' => [
                        'agrees' => ($reconciliation['agrees'] ?? false) === true,
                        'difference' => self::money($reconciliation['difference'] ?? null, $currency),
                        'why' => self::string($reconciliation['why'] ?? null),
                    ],
                ];
            })
            ->values()
            ->all();

        return new JsonResponse(['data' => $data]);
    }

    /** @return array<string, mixed>|null */
    private static function money(mixed $amount, string $currency): ?array
    {
        $amount = self::string($amount);

        return $amount === null || $amount === '' ? null : Wire::amount($amount, $currency);
    }

    private static function string(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : null);
    }
}
