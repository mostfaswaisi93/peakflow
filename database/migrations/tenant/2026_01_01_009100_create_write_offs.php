<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Concluding that a debt will not be paid - PF-172.
 *
 * ## A write-off is a conclusion, not a correction
 *
 * It is the one act in this module that destroys value on purpose. Every
 * other path moves money between accounts or names a difference; this one
 * says a receivable is not going to be collected, and the money is gone.
 *
 * That is why it is permissioned, reasoned, approved above a threshold and
 * separated by duty from both invoicing and reconciliation. Not because
 * writing off is rare - it is ordinary - but because a person who can
 * raise an invoice and then write it off can move money out of a business
 * with two clicks and nothing to show a reader.
 *
 * ## The reason is a code, not a sentence
 *
 * A sentence is read once, by the person who approved it. A code is
 * countable: "how much did we write off as unrecoverable after
 * liquidation this year, and how does that compare with last" is the
 * question a provision matrix is built from, and prose cannot answer it.
 *
 * The sentence is kept too, because a code cannot say which customer and
 * which conversation. Both, or the report is either uncountable or
 * unexplained.
 *
 * ## And a recovery is not an un-write-off
 *
 * Money that arrives after a write-off is a **new receipt**, against a
 * recovery account. Reversing the write-off would be saying the
 * conclusion was wrong, and it was not: on the day it was made, with what
 * was known, it was right. A ledger that rewrites that cannot answer "how
 * good are our write-off decisions", which is the only question that
 * improves them.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | Tenant-wide, and deliberately carrying no `entity_id`.
         |
         | A reason code is **vocabulary**, not money. A tenant database is
         | one customer, and the list of ways their debts fail is theirs
         | rather than each legal entity's - so nothing here needs scoping
         | to the entity that holds the money.
         |
         | The first version had a nullable `entity_id` meaning "a code the
         | platform ships, visible to everybody", and two checks refused
         | it: a nullable `entity_id` under row-level security is invisible
         | to everybody, because null equals nothing in SQL, and a table
         | with the column and no policy is one whose rows cross an entity
         | boundary the moment anybody queries it. Neither wanted a
         | cleverer policy; both were pointing at the column.
         |
         | Tenant-extendable all the same: a business whose debts fail in a
         | way nobody anticipated adds a code rather than choosing the
         | nearest wrong answer, which is what makes the report by reason
         | worth reading.
         */
        Schema::create('write_off_reason_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('code', 32)->unique();
            $table->string('label_ar', 120);
            $table->string('label_en', 120);
            $table->boolean('active')->default(true);

            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE write_off_reason_codes IS
                'Why a debt was written off, countable, and tenant-wide: a code is vocabulary rather than money, so it is not scoped to the entity holding the money - PF-172.';
        SQL);

        Schema::create('write_offs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 32);

            $table->string('document_type', 24);
            $table->char('document_id', 26);
            $table->char('party_id', 26)->nullable();

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);
            $table->date('written_off_on');

            $table->char('reason_code_id', 26);
            $table->text('reason');

            $table->ulid('recorded_by');
            $table->timestamp('recorded_at');

            /*
             | A second approver above a threshold.
             |
             | Null below it, and that is not laxity: an approval step on
             | every small write-off is a step people click through, and
             | the habit carries to the ones that matter. The threshold is
             | a tenant setting under a platform ceiling, because "write
             | off anything under 50,000 on one signature" is a policy
             | decision and not an unlimited one.
             */
            $table->decimal('threshold', 21, 4);
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->ulid('journal_entry_id')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'party_id', 'written_off_on']);
            $table->index(['entity_id', 'reason_code_id']);
            $table->index(['entity_id', 'document_type', 'document_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE write_offs
                ADD CONSTRAINT write_offs_document_type_is_known
                    CHECK (document_type IN ('invoice', 'bill')),

                ADD CONSTRAINT write_offs_amount_is_positive
                    CHECK (amount > 0),

                /*
                 | A sentence beside the code.
                 |
                 | The code is countable and says nothing about which
                 | customer or which conversation. Forty characters is
                 | about one sentence, and it is read during an audit of
                 | money the business decided to lose.
                 */
                ADD CONSTRAINT write_offs_state_a_reason
                    CHECK (length(btrim(reason)) >= 40),

                /*
                 | Above the threshold, somebody else's name is on it.
                 |
                 | Written where it cannot be skipped. A service is one
                 | code path; an import, a correction script and a bulk
                 | tool are three others, and this is the control that
                 | stops one person moving money out of a business.
                 */
                ADD CONSTRAINT write_offs_above_the_threshold_are_approved
                    CHECK (amount <= threshold OR approved_by IS NOT NULL),

                /*
                 | And the approver is not the person who recorded it.
                 |
                 | Maker-checker, in the table. An approval somebody gave
                 | themselves is the control performed by the person it
                 | exists to check - and a service that forgot this would
                 | leave no trace of having forgotten.
                 */
                ADD CONSTRAINT write_offs_are_not_self_approved
                    CHECK (approved_by IS NULL OR approved_by <> recorded_by);

            COMMENT ON TABLE write_offs IS
                'A conclusion that a debt will not be collected: permissioned, reasoned by code, approved above a threshold, and never reversed - a recovery is a new receipt - PF-172.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('write_offs')");

        Schema::create('recoveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 32);
            $table->char('write_off_id', 26);
            $table->char('party_id', 26)->nullable();

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);
            $table->date('recovered_on');

            /*
             | The receipt that brought the money in.
             |
             | A recovery is a new receipt against a recovery account, not
             | a reversal of the write-off. Reversing it would say the
             | conclusion was wrong, and it was not: on the day it was
             | made, with what was known, it was right.
             */
            $table->char('payment_id', 26)->nullable();
            $table->text('note')->nullable();

            $table->ulid('recorded_by');
            $table->timestamp('recorded_at');
            $table->ulid('journal_entry_id')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'write_off_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE recoveries
                ADD CONSTRAINT recoveries_amount_is_positive
                    CHECK (amount > 0);

            COMMENT ON TABLE recoveries IS
                'Money that arrived after a write-off, as a new receipt against a recovery account - never as an un-write-off, so "how good are our write-off decisions" stays answerable - PF-172.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('recoveries')");

        /*
         | The platform's own reason codes, from how debts actually fail.
         |
         | Seeded with `entity_id` null so every tenant has something to
         | start from. A business whose debts fail differently adds its
         | own; nobody has to choose the nearest wrong answer.
         */
        $now = now()->toDateTimeString();
        $codes = [
            ['insolvency', 'إعسار أو تصفية', 'Insolvency or liquidation'],
            ['uneconomic_to_pursue', 'تكلفة التحصيل تتجاوز الدين', 'Uneconomic to pursue'],
            ['statute_barred', 'سقط بالتقادم', 'Statute-barred'],
            ['disputed_and_conceded', 'نزاع تمّ التنازل فيه', 'Disputed and conceded'],
            ['untraceable_debtor', 'مدين لا يمكن الوصول إليه', 'Untraceable debtor'],
            ['negotiated_settlement', 'تسوية تفاوضية', 'Negotiated settlement'],
        ];

        foreach ($codes as [$code, $ar, $en]) {
            /*
             | `DB::table`, not `DB::connection('tenant')`.
             |
             | A tenant migration already runs against the tenant's own
             | database, and naming the connection explicitly reaches for
             | a handle the runner may still have pointed at the previous
             | tenant - which is why this failed on the second one and not
             | the first.
             */
            DB::table('write_off_reason_codes')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'code' => $code,
                'label_ar' => $ar,
                'label_en' => $en,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recoveries');
        Schema::dropIfExists('write_offs');
        Schema::dropIfExists('write_off_reason_codes');
    }
};
