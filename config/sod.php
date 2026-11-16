<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | How a duty is recognised in the audit trail
    |---------------------------------------------------------------------------
    |
    | A segregation-of-duties rule names two permission keys - `invoice.issue`
    | and `credit_note.issue` - and the engine answers "has this user already
    | performed the conflicting one on this document" by reading the audit
    | trail. So it has to know which audit ACTIONS constitute performing a
    | duty.
    |
    | They are not the same strings, and should not be. The audit trail is
    | read by people and says what happened, in the past tense:
    | `invoice.issued`, `return.filed`, `role.granted`. A permission key says
    | what somebody is allowed to do. Collapsing the two would make one of
    | them worse.
    |
    | ## The defect this closes, measured
    |
    | Until this map existed, the engine matched `action = 'invoice.issue'` -
    | the permission key - against an audit trail in which issuing an invoice
    | is recorded as `invoice.issued`. It matched nothing. Every object-scoped
    | rule involving a real flow was a control that passed its own tests
    | against synthetic audit records written by the test, and could not fire
    | in production.
    |
    | A duty with no entry here falls back to matching its own key, which is
    | what the engine did before. `SodCoverageRules` fails the build if a duty
    | named by an active rule has neither a mapped action that some service
    | actually writes nor an entry in `awaiting_implementation` below.
    |
    */

    'performed_as' => [

        // The document flows, which are built.
        'invoice.issue' => ['invoice.issued'],
        'credit_note.issue' => ['credit_note.issued'],
        'corrected_invoice.issue' => ['corrected_invoice.issued'],
        'tax_return.file' => ['return.filed'],

        /*
         | Reconciling, which is several acts and not one - PF-163.
         |
         | Every one of them decides what remains unexplained at the end:
         | accepting a proposal, letting an automatic one stand, splitting
         | a line, parking the rest in suspense. The SoD pair that matters
         | is "whoever decided what could not be explained must not be the
         | one who signs off that it is acceptable", and that only fires if
         | the duty recognises all of those as reconciling.
         |
         | `match.applied_automatically` is included deliberately. The
         | engine applied it, but a person ran the matching and let it
         | stand, and "the engine did it" is exactly the defence the
         | control exists to not accept.
         */
        'bank.reconcile' => [
            'match.accepted',
            'match.applied_automatically',
            'match.rejected',
            'match.reversed',
            'match.split',
            'match.unsplit',
            'suspense.parked',
            'suspense.cleared',
        ],

        'suspense.sign_off' => ['suspense.signed_off'],

        /*
         | Posting, through the paths that record one.
         |
         | A posting made BY a document is attributed to that document's own
         | duty - issuing the invoice is what the person did, and the journal
         | entry is a consequence - so the rule that matters here is the one
         | about postings a person makes directly: an import, an inter-entity
         | entry, an opening balance, an adjusting entry in a closed period.
         | Those four are recorded, and those four are what `post_then_close`
         | is about: somebody who posts by hand and then closes the period
         | over it.
         */
        'journal.post' => [
            'ledger.import',
            'ledger.inter_entity',
            'ledger.opening_balance',
            'period.post_adjusting',
        ],

        /*
         | Closing, in every form that stops a period accepting work.
         |
         | The action is composed from the target state
         | (`'period.'.$to->value`), so these are listed in
         | `composed_actions` below rather than appearing as literals.
         | Reopening is included deliberately: whoever reopened a period has
         | exercised the same authority as whoever closed it.
         */
        'period.close' => [
            'period.soft_closed',
            'period.closed',
            'period.locked',
            'period.reopened',
            'year.closed',
        ],

        /*
         | Managing a party, in the forms that are recorded.
         |
         | Creating and updating a party write no audit record today, so the
         | "whoever creates a supplier" half of `supplier_then_release` is
         | blind. That rule's other duty is `payment.release`, which arrives
         | in Phase 09, and the gap is recorded under `declared_gaps` so the
         | day the payment run lands this is not discovered by an auditor.
         */
        'party.manage' => ['party.merged', 'credit.override.granted'],

        'supplier.bank_details.update' => [
            'supplier.bank_details.update',
            'supplier.bank_details.verified',
        ],

        /*
         | The payable side - PF-133, PF-136.
         |
         | `bill_create_then_approve` is object-scoped: nobody approves a
         | bill they entered. Entering one is recorded as `bill.entered` by
         | `Bills::enter()`, and approving one as `approval.approved` by the
         | approval engine - and until both existed this rule matched
         | nothing, which the coverage check reported as a declared gap.
         */
        'bill.create' => ['bill.entered'],
        'bill.approve' => ['approval.approved'],

        /*
         | Expense claims - PF-143.
         |
         | `claim_then_approve` is object-scoped: nobody approves their own
         | claim. The approval engine refuses the requester directly, which
         | covers the ordinary case, and this pair catches the same conflict
         | arriving by another door - somebody who entered the claim for a
         | colleague and then approves it.
         |
         | `expense_claim.submitted` is recorded by `ExpenseClaims::enter()`
         | and `expense_claim.approved` by `recordApproval()`, so both were
         | declared gaps until those two methods existed.
         */
        'expense_claim.submit' => ['expense_claim.submitted'],
        'expense_claim.approve' => ['expense_claim.approved'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Actions the code composes rather than writes as a literal
    |---------------------------------------------------------------------------
    |
    | `PeriodStateMachine` records `'period.'.$to->value`, so grepping the
    | source for the action finds nothing. Each entry names the file that
    | composes it and the prefix it composes from, and the coverage check
    | verifies both - so a mapping cannot be justified by a file that no
    | longer builds that action.
    |
    */

    'composed_actions' => [
        /*
         | The document actions, which `DocumentType::auditAction()` returns.
         |
         | The issuer asks the type rather than writing a literal, because
         | issuing a credit note is not `invoice.issued` and a single literal
         | at the one call site is how it would become one. The enum is where
         | these strings live, and that is the file named here.
         |
         | Measured: making the issuer ask the type broke this check at once,
         | which is the check doing its job on the change that introduced it.
         */
        'invoice.issued' => ['app/Tenant/Sales/Enums/DocumentType.php', "'invoice.issued'"],
        'credit_note.issued' => ['app/Tenant/Sales/Enums/DocumentType.php', "'credit_note.issued'"],
        'debit_note.issued' => ['app/Tenant/Sales/Enums/DocumentType.php', "'debit_note.issued'"],
        'corrected_invoice.issued' => ['app/Tenant/Sales/Enums/DocumentType.php', "'corrected_invoice.issued'"],

        'period.soft_closed' => ['app/Tenant/Ledger/Services/PeriodStateMachine.php', "'period.'"],
        'period.closed' => ['app/Tenant/Ledger/Services/PeriodStateMachine.php', "'period.'"],
        'period.locked' => ['app/Tenant/Ledger/Services/PeriodStateMachine.php', "'period.'"],
        'period.reopened' => ['app/Tenant/Ledger/Services/PeriodStateMachine.php', "'period.reopened'"],
    ],

    'declared_gaps' => [
        'payment.prepare' => 'Phase 09 - the payment run',
        'payment.release' => 'Phase 09 - the payment run',
        'reconciliation.write_off' => 'PF-164 - difference classification, where a write-off becomes an act with a posting rule behind it',

        /*
         | Built, and not recorded.
         |
         | `ReturnGenerator` computes a return and persists nothing until it
         | is filed, so "preparing" one is not an act the audit trail can
         | see - which means `prepare_then_file_return` currently enforces
         | nothing. Closing it means recording the preparation, which is
         | PF-086's remaining work rather than a missing phase.
         */
        'tax_return.prepare' => 'PF-086 - a prepared return is not recorded, so the maker-checker pair over a return cannot fire',
    ],

];
