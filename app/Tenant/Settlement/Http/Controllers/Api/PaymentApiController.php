<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Http\Controllers\Api;

use App\Support\Http\Api\DatabaseRefusal;
use App\Support\Http\Api\Wire;
use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Support\Money\Money;
use App\Tenant\Identity\Models\User;
use App\Tenant\Settlement\Enums\PaymentDirection;
use App\Tenant\Settlement\Enums\PaymentMethod;
use App\Tenant\Settlement\Exceptions\PaymentRefused;
use App\Tenant\Settlement\Services\Allocations;
use App\Tenant\Settlement\Services\Payments;
use App\Tenant\Tenancy\Exceptions\EntityNotInScope;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Recording a payment, and applying it - PF-264.
 *
 * ## One request, one transaction
 *
 * A payment and the documents it settles arrive together because that is how
 * a person thinks of them: "Gulf Sands paid invoice 28". They are recorded
 * together or not at all. A payment that was recorded while its allocation
 * was refused would sit in unapplied cash with the invoice still showing as
 * owed - two facts the person did not intend, from one tap.
 *
 * ## It posts to the ledger, so it needs an Idempotency-Key
 *
 * A retried receipt without one is a second receipt: same amount, same day,
 * a different number, and the customer's balance short by exactly that much.
 * The route carries `api.idempotent`; this controller does not need to know.
 *
 * ## Through the services the browser uses
 *
 * {@see Payments::record()} and {@see Allocations::allocate()}, unchanged, so
 * every rule they hold - a positive amount, an account that can take it, an
 * allocation no larger than what is open, a direction that matches the
 * document's side - holds here, and a refusal says the same thing.
 */
final class PaymentApiController
{
    public function __construct(
        private readonly Payments $payments,
        private readonly Allocations $allocations,
        private readonly EntityContext $entities,
    ) {}

    public function store(Request $request): JsonResponse|Problem
    {
        Gate::authorize('payment.record');

        $request->validate([
            'direction' => ['required', 'string', 'in:receipt,disbursement'],
            'method' => ['required', 'string', 'in:'.implode(',', array_map(static fn (PaymentMethod $m): string => $m->value, PaymentMethod::cases()))],
            /*
             | Strings, for the reason every amount in this API is one: a JSON
             | number has already lost its precision by the time a rule sees it.
             */
            'amount' => ['required', 'string', 'regex:/^\d{1,17}(\.\d{1,4})?$/'],
            'currency' => ['required', 'string', 'size:3'],
            'value_date' => ['required', 'date_format:Y-m-d'],
            'bank_account_id' => ['nullable', 'string', 'size:26'],
            'cash_account_id' => ['nullable', 'string', 'size:26'],
            'party_id' => ['nullable', 'string', 'size:26'],
            'reference' => ['nullable', 'string', 'max:140'],
            'note' => ['nullable', 'string', 'max:500'],
            'allocations' => ['nullable', 'array', 'max:100'],
            'allocations.*.document_type' => ['required', 'string', 'in:invoice,bill'],
            'allocations.*.document_id' => ['required', 'string', 'size:26'],
            'allocations.*.amount' => ['required', 'string', 'regex:/^\d{1,17}(\.\d{1,4})?$/'],
        ]);

        /** @var list<array{document_type: string, document_id: string, amount: string}> $wanted */
        $wanted = array_values(array_filter(
            (array) $request->input('allocations', []),
            static fn (mixed $row): bool => \is_array($row),
        ));

        // Applying money to a document is its own permission, asked only
        // when the request asks to do it.
        if ($wanted !== []) {
            Gate::authorize('payment.allocate');
        }

        $entity = $this->entities->entity();

        if (! $entity instanceof Entity) {
            throw EntityNotInScope::forQueryOn(Entity::class);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $currency = $request->string('currency')->upper()->value();

        $mismatches = $this->allocationsThatCannotApply(
            $wanted,
            $request->string('direction')->value(),
            self::optional($request, 'party_id'),
        );

        if ($mismatches !== []) {
            $problem = Problem::of(ProblemType::ValidationFailed)
                ->withDetail('The payment was not recorded: one or more allocations name a document it cannot settle.');

            foreach ($mismatches as $index => [$code, $detail]) {
                $problem->withFieldError('/allocations/'.$index.'/document_id', $code, $detail);
            }

            return $problem;
        }

        try {
            $result = DB::connection('tenant')->transaction(function () use ($request, $entity, $user, $currency, $wanted): array {
                $recorded = $this->payments->record(
                    entityId: $entity->id,
                    direction: PaymentDirection::from($request->string('direction')->value()),
                    method: PaymentMethod::from($request->string('method')->value()),
                    amount: Money::of($request->string('amount')->value(), $currency),
                    valueDate: Carbon::createFromFormat('Y-m-d', $request->string('value_date')->value())?->startOfDay() ?? now()->startOfDay(),
                    userId: $user->id,
                    bankAccountId: self::optional($request, 'bank_account_id'),
                    cashAccountId: self::optional($request, 'cash_account_id'),
                    partyId: self::optional($request, 'party_id'),
                    note: self::optional($request, 'note'),
                    externalReference: self::optional($request, 'reference'),
                );

                $paymentId = \is_string($recorded['id'] ?? null) ? $recorded['id'] : '';
                $applied = [];

                foreach ($wanted as $index => $line) {
                    $allocation = $this->allocations->allocate(
                        paymentId: $paymentId,
                        documentType: (string) $line['document_type'],
                        documentId: (string) $line['document_id'],
                        amount: Money::of((string) $line['amount'], $currency),
                        userId: $user->id,
                    );

                    $applied[] = [
                        'id' => \is_string($allocation['id'] ?? null) ? $allocation['id'] : null,
                        'document_type' => (string) $line['document_type'],
                        'document_id' => (string) $line['document_id'],
                        'amount' => Wire::amount((string) $line['amount'], $currency),
                        'index' => $index,
                    ];
                }

                return [
                    'recorded' => $recorded,
                    'applied' => $applied,
                    'unallocated' => $this->allocations->unallocated($paymentId),
                ];
            });
        } catch (PaymentRefused $refused) {
            /*
             | A business rule, refused - a 422 with the service's own sentence,
             | which names the figure or the document. Nothing was written: the
             | refusal rolled the whole request back, payment included.
             */
            return Problem::of(ProblemType::ValidationFailed)->withDetail($refused->getMessage());
        } catch (QueryException $e) {
            // The same, when it was a trigger that said no rather than the
            // service - an allocation larger than what is open, say.
            return DatabaseRefusal::problem($e) ?? throw $e;
        }

        $recorded = $result['recorded'];
        $paymentId = \is_string($recorded['id'] ?? null) ? $recorded['id'] : '';

        return new JsonResponse([
            'resource' => 'payment',
            'id' => $paymentId,
            'number' => \is_string($recorded['number'] ?? null) ? $recorded['number'] : null,
            'direction' => $request->string('direction')->value(),
            'method' => $request->string('method')->value(),
            'amount' => Wire::amount($request->string('amount')->value(), $currency),
            'value_date' => $request->string('value_date')->value(),
            'bank_account_id' => self::optional($request, 'bank_account_id'),
            'party_id' => self::optional($request, 'party_id'),
            'journal_entry_id' => \is_string($recorded['journal_entry_id'] ?? null) ? $recorded['journal_entry_id'] : null,
            'allocations' => array_map(
                static fn (array $row): array => array_diff_key($row, ['index' => true]),
                $result['applied'],
            ),
            'unallocated' => Wire::money($result['unallocated']),
        ], JsonResponse::HTTP_CREATED);
    }

    /**
     * Allocations that name a document this payment cannot settle.
     *
     * Checked here because {@see Allocations::allocate()} does not: it holds
     * the amount (and a trigger holds it again), but not WHICH document. Its
     * open-balance function counts a draft's figures, so without this a
     * receipt could be applied to an invoice nobody has issued, or to a
     * supplier's bill - money in, settling money out. Each is a mistake a
     * person makes with one wrong tap on a list.
     *
     * @param  list<array{document_type: string, document_id: string, amount: string}>  $wanted
     * @return array<int, array{0: string, 1: string}>  index => [code, detail]
     */
    private function allocationsThatCannotApply(array $wanted, string $direction, ?string $partyId): array
    {
        $problems = [];

        foreach ($wanted as $index => $line) {
            $type = (string) $line['document_type'];
            $table = $type === 'invoice' ? 'invoices' : 'bills';

            // Row-level security: another company's document is simply absent.
            $document = DB::connection('tenant')->table($table)
                ->where('id', (string) $line['document_id'])
                ->first(['status', 'party_id']);

            if ($document === null) {
                $problems[$index] = ['not_found', 'No such document is visible in this company.'];

                continue;
            }

            $status = \is_string($document->status ?? null) ? $document->status : '';
            $party = \is_string($document->party_id ?? null) ? $document->party_id : '';

            $expectedDirection = $type === 'invoice' ? 'receipt' : 'disbursement';
            $settleable = $type === 'invoice' ? 'issued' : 'posted';

            if ($direction !== $expectedDirection) {
                $problems[$index] = ['wrong_direction', sprintf(
                    'A %s settles %s, and this document is a %s.',
                    $direction,
                    $direction === 'receipt' ? 'sales invoices' : 'supplier bills',
                    $type,
                )];
            } elseif ($status !== $settleable) {
                $problems[$index] = ['not_payable', sprintf(
                    'This %s is %s; only an %s one can be paid.',
                    $type,
                    $status,
                    $settleable,
                )];
            } elseif ($partyId !== null && $party !== $partyId) {
                $problems[$index] = ['another_party', sprintf(
                    'This %s is with another party than the one the payment names.',
                    $type,
                )];
            }
        }

        return $problems;
    }

    private static function optional(Request $request, string $field): ?string
    {
        return $request->filled($field) ? $request->string($field)->trim()->value() : null;
    }
}
