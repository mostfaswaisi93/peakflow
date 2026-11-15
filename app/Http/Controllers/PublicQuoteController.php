<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Tenant\Sales\Exceptions\QuoteRefused;
use App\Tenant\Sales\Models\Quote;
use App\Tenant\Sales\Services\QuoteOutcomes;
use App\Tenant\Sales\Services\Quotes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * The quote a customer sees, by signed link, with no login - PF-089.
 *
 * ## Why no login
 *
 * Because the person who has to answer is the customer, and the number of
 * quotes that go unanswered because somebody could not remember a password
 * for a portal they use twice a year is not small. The link is signed, it
 * expires, and it does one thing.
 *
 * ## What the page deliberately does not show
 *
 * Anything but this quote. No navigation, no other documents, no party
 * record, no totals for the customer's account. A signed link is a key to one
 * door, and a page that offers a way to a second door is a page where the
 * signature protects nothing.
 *
 * ## Why accepting here does not issue an invoice
 *
 * Accepting is the customer's act. Issuing is the business's, and it consumes
 * a number from a gapless series, writes a posting and takes a place in a
 * hash chain. Collapsing the two would do all of that at the moment somebody
 * clicked a link on a phone - and the business would find out afterwards.
 *
 * So acceptance records the acceptance, and `sales.order_step` decides
 * whether an order or a DRAFT invoice follows.
 */
final class PublicQuoteController extends Controller
{
    public function show(Request $request, string $tenant, string $entity, string $quote): View
    {
        $found = $this->find($quote);
        $previous = $found->supersedes;

        return view('public.quote', [
            'title' => $found->label(),
            'quote' => $found,
            'lines' => $found->lines()->get(),
            'party' => $found->party,
            'actionable' => $found->isActionable(),
            'comparison' => $previous === null
                ? null
                : app(Quotes::class)->compare($previous, $found),
            'previous' => $previous,
        ]);
    }

    /**
     * @no-permission-required: the visitor is the customer, who has no user
     * record, no role and no login here - that is the point of the link. The
     * authorization IS the signature: it proves this exact quote, in this
     * exact entity, was sent to whoever holds the URL, and a forged or
     * edited one is refused by `ValidateSignature` before this method is
     * reached. A `can:` check would ask a Gate about a user that does not
     * exist, and the only way to satisfy it would be to give customers
     * logins - which is the thing this route exists to avoid.
     */
    public function accept(Request $request, string $tenant, string $entity, string $quote): RedirectResponse
    {
        $validated = $request->validate([
            // The name is required and is what the acceptance is recorded
            // against: a dispute about "we never agreed to this" is argued
            // with a name, a time and an address.
            'accepted_by' => ['required', 'string', 'min:2', 'max:200'],
        ]);

        $found = $this->find($quote);

        try {
            $accepted = app(Quotes::class)->accept(
                $found,
                (string) $validated['accepted_by'],
                $request->ip(),
            );

            /*
             | What it becomes is the tenant's flow, and a failure here must
             | not undo the acceptance.
             |
             | The customer accepted; that is a fact about the world. If the
             | order or the draft invoice cannot be created - a closed
             | period, a lapsed registration - the acceptance stands and the
             | business deals with the rest. Rolling it back would tell the
             | customer their answer was not received.
             */
            try {
                app(QuoteOutcomes::class)->realise($accepted);
            } catch (Throwable) {
                // Recorded by the audit trail on the acceptance itself; the
                // business sees the quote as accepted with no outcome.
            }

            return back()->with('status', __('تم تسجيل قبولك. شكراً لك.'));
        } catch (QuoteRefused $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }
    }

    /**
     * @no-permission-required: as above - the signature is the
     * authorization, and the visitor is a customer with no user record. A
     * decline writes less than an accept and is refused the same way when
     * the link is not genuine.
     */
    public function decline(Request $request, string $tenant, string $entity, string $quote): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            app(Quotes::class)->decline(
                $this->find($quote),
                $validated['reason'] === null ? null : (string) $validated['reason'],
                $request->ip(),
            );

            return back()->with('status', __('تم تسجيل ردّك.'));
        } catch (QuoteRefused $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }
    }

    /**
     * The quote, or a 404.
     *
     * Not a "quote not found" page: the signature already proved the visitor
     * was given this link, and a quote that has been deleted is a link that
     * does not work. Saying more would describe the business's data to
     * somebody holding a stale email.
     */
    private function find(string $id): Quote
    {
        $quote = Quote::query()->whereKey($id)->first();

        if ($quote === null) {
            abort(404);
        }

        return $quote;
    }
}
