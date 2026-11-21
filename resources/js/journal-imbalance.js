/**
 * The running imbalance on the manual journal screen - PF-055.
 *
 * ## What this file does NOT do
 *
 * Add up the amount column. That is the whole point of it.
 *
 * `ManualJournal::validate()` resolves every account code, refuses a
 * control account with an explanation, reads amounts strictly - Arabic-Indic
 * digits normalised, thousands separators accepted, "about 12k" refused,
 * more than four decimal places refused - translates a foreign line at a
 * rate with a provenance, and rounds to the policy's scale. A browser
 * summing `parseFloat` would agree with it on the easy rows and disagree on
 * exactly the rows somebody is about to post, and the disagreement would
 * show as a balanced entry that the server then refuses.
 *
 * So this sends the rows and renders what comes back. There is one answer to
 * "is this entry balanced" and it is the server's.
 *
 * ## Debounced, and the last answer wins
 *
 * A keystroke is not a request. Typing "1250" at speed is four of them, and
 * four in flight come back in whatever order the network chooses - so a
 * sequence number is carried and a late answer to an earlier question is
 * dropped. Without it the figure settles on whichever reply arrived last,
 * which is the one bug in this file nobody would ever reproduce by hand.
 *
 * ## And the page works without it
 *
 * The form has a Check button that submits the same rows to the same
 * validation and re-renders the page. This file is an improvement on that,
 * not a replacement for it: if it throws, is blocked or never loads, the
 * screen still tells somebody their entry does not balance.
 */
(() => {
    'use strict';

    const form = document.querySelector('[data-pf-journal]');

    if (!form) {
        return;
    }

    const url = form.getAttribute('data-pf-journal-check');

    /*
     | The output is looked up in the DOCUMENT, not inside the form.
     |
     | The grid is a sibling of the form rather than a child of it - it
     | renders forms of its own, and a form inside a form is not nested by
     | any parser - so the paragraph that shows the imbalance sits beside
     | the form and not within it. Scoped to the form, this found nothing,
     | and the only symptom was a figure that never changed.
     */
    const output = document.querySelector('[data-pf-journal-imbalance]');

    if (!url || !output) {
        return;
    }

    const debits = document.querySelector('[data-pf-journal-debits]');
    const credits = document.querySelector('[data-pf-journal-credits]');
    const token = form.querySelector('input[name="_token"]');

    let sequence = 0;
    let settled = 0;
    let timer = null;

    const ask = () => {
        const mine = ++sequence;
        const body = new FormData(form);

        /*
         | `intent` is stripped, because this is not a submission.
         |
         | The form carries two submit buttons whose value decides what the
         | POST means, and FormData from a programmatic read picks up
         | neither - but a page that later sets one would send it here and
         | the check endpoint would be asked to post.
         */
        body.delete('intent');

        fetch(url, {
            method: 'POST',
            body,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token.value } : {}),
            },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((verdict) => {
                if (!verdict || mine <= settled) {
                    // A late answer to a question two keystrokes old.
                    return;
                }

                settled = mine;

                if (typeof verdict.sentence === 'string') {
                    output.textContent = verdict.sentence;
                }

                if (debits && typeof verdict.total_debit === 'string') {
                    debits.textContent = verdict.total_debit;
                }

                if (credits && typeof verdict.total_credit === 'string') {
                    credits.textContent = verdict.total_credit;
                }
            })
            .catch(() => {
                /*
                 | Silence, deliberately.
                 |
                 | The figure on the page is the last one the server gave,
                 | and the Check button is still there. Replacing it with
                 | "could not reach the server" would be a worse answer
                 | than a slightly stale one, because the stale one is
                 | still the server's arithmetic.
                 */
            });
    };

    /*
     | `input`, not `change`.
     |
     | PF-055 asks for the imbalance as you type, and `change` fires when a
     | field is left. On the last row of an entry that is the moment after
     | somebody has decided it is finished.
     */
    /*
     | Listened for on the DOCUMENT, not on the form.
     |
     | The grid's cells belong to the form through HTML's `form` attribute
     | and sit outside it in the tree. An event bubbles up the TREE, not
     | through the association - so a listener on the form heard nothing
     | from the only fields that matter.
     |
     | Measured: `input` fired, the field was in `form.elements`, and the
     | form's own listener was never called.
     */
    document.addEventListener('input', (event) => {
        const target = event.target;

        if (!(target instanceof HTMLInputElement)) {
            return;
        }

        if (!target.name.startsWith('lines[')) {
            return;
        }

        window.clearTimeout(timer);
        timer = window.setTimeout(ask, 180);
    });
})();
