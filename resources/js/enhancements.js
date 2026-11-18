/*
 * The grid's keyboard model (PF-206) and the currency input (PF-212).
 *
 * ## What this is, and what it is not
 *
 * It is an enhancement over markup that already works. PeakFlow ships no
 * SPA, so nothing here is allowed to be the only way to do anything: the
 * grid is a real <table> in a real <form>, every editable cell is a real
 * <input name>, sorting and paging are links, the filter is a GET form and
 * the submit is a submit. With this file removed, every one of those still
 * does its job - the loss is arrow-key navigation, range selection, copy as
 * TSV and the paste preview, which are speed, not function.
 *
 * It is NOT a data layer. Nothing here computes a total, rounds a figure or
 * decides what a value means: a client-side arithmetic layer is a second
 * implementation of the rules and it will disagree with the server.
 *
 * ## Why the markup is driven by data-pf-* hooks
 *
 * Every hook this file reads is written by the grid's own Blade components,
 * and `bin/verify grid` renders them and compares the two sets. A behaviour
 * whose hook nobody renders, and a hook nobody reads, both fail there -
 * which is the only honest way to check a keyboard model without a browser.
 *
 * Strings come from `window.pfUiStrings`, rendered by
 * resources/views/partials/enhancements.blade.php through the translator.
 * A literal here would be an untranslated string that only its author can
 * read, in a product whose first language is Arabic.
 */
(() => {
    'use strict';

    const strings = window.pfUiStrings || {};
    const say = (key, count) => String(strings[key] || '').replace(':count', String(count));

    /*
     * Arabic-Indic and extended Arabic-Indic digits to Western ones.
     *
     * A finance user's keyboard, phone keypad or pasted bank statement can
     * produce any of the three, and `parseFloat('١٢٣')` is NaN. The digits
     * are mapped by code point rather than by a locale-aware parse, because
     * the value here has to survive as a STRING: the server owns rounding,
     * and a client that turns "1234.5678" into a float has already lost the
     * fourth decimal the ledger stores.
     *
     * Western digits going OUT is deliberate too - PF-202: display shape is
     * a tenant setting, but what is submitted, exported or copied is always
     * Western.
     */
    const westernize = (text) => String(text).replace(/[\u0660-\u0669\u06F0-\u06F9]/g, (digit) => {
        const code = digit.codePointAt(0);

        return String(code >= 0x06f0 ? code - 0x06f0 : code - 0x0660);
    })
        // The Arabic decimal separator and the Arabic thousands separator,
        // plus the spaces used as grouping marks (including NBSP and the
        // narrow NBSP, which is what a European export uses).
        //
        // As \u escapes, not as the characters themselves: three of the
        // four are INVISIBLE, and a character class containing an unseen
        // space is a line nobody can review and an editor can eat.
        .replace(/\u066B/g, '.')
        .replace(/[\u066C\u2009\u202F\u00A0]/g, '');

    /* ====================================================================
     *  THE GRID
     * ================================================================= */

    const wireGrid = (table) => {
        const scroller = table.closest('[data-pf-grid-scroller]');
        const root = scroller ? scroller.parentElement : table.parentElement;
        const status = root ? root.querySelector('[data-pf-grid-status]') : null;
        const dialog = root ? root.querySelector('[data-pf-grid-paste]') : null;

        /*
         * The matrix is read from the DOM on demand rather than cached.
         *
         * A cached matrix is a matrix that is wrong the first time anything
         * re-renders a row, and the failure is silent: the arrow keys move
         * focus to a cell that is no longer where it was.
         */
        const rows = () => Array.from(table.querySelectorAll('[data-pf-row]'));
        const cellsIn = (row) => Array.from(row.querySelectorAll('[data-pf-cell]'));
        const matrix = () => rows().map(cellsIn);

        const inputIn = (cell) => cell.querySelector('input');
        const isEditable = (cell) => cell.hasAttribute('data-pf-editable');

        /*
         * The inline direction is read from the computed style, not assumed.
         *
         * The interface is Arabic first, so "the next column" and "the key
         * marked with an arrow pointing right" are opposites half the time.
         * Home and End are NOT mirrored: they mean the first and last cell
         * of the row, and DOM order is reading order in both directions.
         */
        const rtl = () => getComputedStyle(table).direction === 'rtl';

        let anchor = null;   // where a Shift+arrow selection started
        let position = null; // {r, c} of the cell that holds the tab stop
        const undo = [];     // committed cell edits, newest last
        const before = new WeakMap(); // an input's value when it was focused
        let pending = null;  // a parsed paste, waiting for the preview's Apply

        const announce = (text) => {
            if (status) {
                status.textContent = text;
            }
        };

        const at = (r, c) => {
            const grid = matrix();
            const row = grid[r];

            return row ? row[c] || null : null;
        };

        const clamp = (value, max) => Math.max(0, Math.min(value, max));

        const focusCell = (r, c, { edit = false } = {}) => {
            const grid = matrix();

            if (grid.length === 0) {
                return;
            }

            r = clamp(r, grid.length - 1);
            c = clamp(c, grid[r].length - 1);

            const cell = grid[r][c];

            if (!cell) {
                return;
            }

            // The roving tabindex: one tab stop for the whole widget, so a
            // 50x7 grid does not put 350 stops between the filter and the
            // paginator.
            grid.forEach((row) => row.forEach((one) => {
                one.setAttribute('tabindex', one === cell ? '0' : '-1');
            }));

            position = { r, c };
            const field = inputIn(cell);

            if (edit && field) {
                field.focus();
                field.select();

                return;
            }

            cell.focus();
        };

        const locate = (cell) => {
            const grid = matrix();

            for (let r = 0; r < grid.length; r++) {
                const c = grid[r].indexOf(cell);

                if (c !== -1) {
                    return { r, c };
                }
            }

            return null;
        };

        /* ---------------- selection, and what it is for ---------------- */

        const rectangle = (a, b) => ({
            top: Math.min(a.r, b.r),
            bottom: Math.max(a.r, b.r),
            start: Math.min(a.c, b.c),
            end: Math.max(a.c, b.c),
        });

        const select = (a, b) => {
            const grid = matrix();
            const box = rectangle(a, b);
            let count = 0;

            grid.forEach((row, r) => {
                let whole = row.length > 0;

                row.forEach((cell, c) => {
                    const inside = r >= box.top && r <= box.bottom && c >= box.start && c <= box.end;

                    if (inside) {
                        cell.setAttribute('aria-selected', 'true');
                        count++;
                    } else {
                        cell.removeAttribute('aria-selected');
                        whole = false;
                    }
                });

                /*
                 * A row is marked selected only when ALL of it is.
                 *
                 * `aria-selected` on a row means the row is the selected
                 * thing; saying so because two of its seven cells are in a
                 * range tells a screen-reader user they have selected a
                 * record when they have selected part of one.
                 */
                const owner = rows()[r];

                if (owner) {
                    if (whole) {
                        owner.setAttribute('aria-selected', 'true');
                    } else if (owner.getAttribute('aria-selected') === 'true') {
                        owner.setAttribute('aria-selected', 'false');
                    }
                }
            });

            announce(count > 1 ? say('selected', count) : '');
        };

        const clearSelection = () => {
            table.querySelectorAll('[data-pf-cell][aria-selected]').forEach((cell) => {
                cell.removeAttribute('aria-selected');
            });
            anchor = null;
            announce('');
        };

        const selected = () => {
            const grid = matrix();
            const cells = [];

            grid.forEach((row) => {
                const picked = row.filter((cell) => cell.getAttribute('aria-selected') === 'true');

                if (picked.length > 0) {
                    cells.push(picked);
                }
            });

            if (cells.length > 0) {
                return cells;
            }

            const here = position ? at(position.r, position.c) : null;

            return here ? [[here]] : [];
        };

        /*
         * A cell's value as a spreadsheet should receive it.
         *
         * `data-pf-value` when the page gave one, because the text on
         * screen is a presentation: "1,234.56 SAR" pasted into a
         * spreadsheet is a string, and a column of strings cannot be
         * summed - which is the entire reason somebody copied it.
         */
        const valueOf = (cell) => {
            const field = inputIn(cell);

            if (field) {
                return field.value;
            }

            if (cell.hasAttribute('data-pf-value')) {
                return cell.getAttribute('data-pf-value');
            }

            return cell.textContent.replace(/\s+/g, ' ').trim();
        };

        const tsv = () => selected()
            .map((row) => row.map(valueOf).join('\t'))
            .join('\n');

        const copySelection = (event) => {
            const text = tsv();

            if (text === '') {
                return;
            }

            let count = 0;
            selected().forEach((row) => {
                count += row.length;
            });

            if (event && event.clipboardData) {
                // Inside a real `copy` event, which needs no permission and
                // cannot be refused by the browser.
                event.clipboardData.setData('text/plain', text);
                event.preventDefault();
                announce(say('copied', count));

                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => announce(say('copied', count)), () => {});

                return;
            }

            // The pointer path on a browser with no async clipboard: a
            // detached textarea and the legacy command. It is deprecated and
            // it is also the only thing that works there.
            const carrier = document.createElement('textarea');
            carrier.value = text;
            carrier.setAttribute('aria-hidden', 'true');
            carrier.style.position = 'fixed';
            carrier.style.opacity = '0';
            document.body.appendChild(carrier);
            carrier.select();

            try {
                document.execCommand('copy');
                announce(say('copied', count));
            } finally {
                carrier.remove();
            }
        };

        /* ----------------------- editing and undo ---------------------- */

        const commit = (field) => {
            const was = before.get(field);

            if (was !== undefined && was !== field.value) {
                undo.push({ field, was });
            }

            before.delete(field);
        };

        const revertLast = () => {
            const last = undo.pop();

            if (!last) {
                return false;
            }

            last.field.value = last.was;
            const cell = last.field.closest('[data-pf-cell]');
            const spot = cell ? locate(cell) : null;

            if (spot) {
                focusCell(spot.r, spot.c);
            }

            announce(say('reverted', 1));

            return true;
        };

        /* --------------------------- the keys -------------------------- */

        const pageSize = () => {
            const first = rows()[0];
            const height = first ? first.offsetHeight : 0;
            const viewport = scroller ? scroller.clientHeight : 0;

            return Math.max(1, Math.floor((viewport || height * 10) / (height || 1)) - 1);
        };

        table.addEventListener('keydown', (event) => {
            const cell = event.target.closest('[data-pf-cell]');

            if (!cell || !table.contains(cell)) {
                return;
            }

            const spot = locate(cell);

            if (!spot) {
                return;
            }

            position = spot;

            const grid = matrix();
            const lastRow = grid.length - 1;
            const lastColumn = grid[spot.r].length - 1;
            const field = event.target.matches('input') ? event.target : null;
            const ctrl = event.ctrlKey || event.metaKey;
            const key = event.key;

            const move = (r, c, options) => {
                event.preventDefault();

                if (event.shiftKey) {
                    anchor = anchor || { r: spot.r, c: spot.c };
                    const to = { r: clamp(r, lastRow), c: clamp(c, lastColumn) };
                    focusCell(to.r, to.c);
                    select(anchor, to);

                    return;
                }

                clearSelection();
                focusCell(r, c, options);
            };

            /*
             * While the caret is inside an input, Left and Right belong to
             * the caret until it reaches the end of the text.
             *
             * Taking them always would make a figure impossible to correct
             * in the middle; never taking them would make the grid's inline
             * navigation unreachable, because every editable cell IS an
             * input and the caret would swallow the key for ever.
             */
            const caretAtEdge = (towardsEnd) => {
                if (!field) {
                    return true;
                }

                if (field.selectionStart !== field.selectionEnd) {
                    return false;
                }

                return towardsEnd
                    ? field.selectionStart === field.value.length
                    : field.selectionStart === 0;
            };

            switch (key) {
                case 'ArrowUp':
                    move(ctrl ? 0 : spot.r - 1, spot.c);

                    return;
                case 'ArrowDown':
                    move(ctrl ? lastRow : spot.r + 1, spot.c);

                    return;
                case 'ArrowRight': {
                    const forward = !rtl();

                    if (!caretAtEdge(forward)) {
                        return;
                    }

                    const target = forward
                        ? (ctrl ? lastColumn : spot.c + 1)
                        : (ctrl ? 0 : spot.c - 1);
                    move(spot.r, target);

                    return;
                }
                case 'ArrowLeft': {
                    const forward = rtl();

                    if (!caretAtEdge(forward)) {
                        return;
                    }

                    const target = forward
                        ? (ctrl ? lastColumn : spot.c + 1)
                        : (ctrl ? 0 : spot.c - 1);
                    move(spot.r, target);

                    return;
                }
                case 'Home':
                    move(ctrl ? 0 : spot.r, 0);

                    return;
                case 'End':
                    move(ctrl ? lastRow : spot.r, lastColumn);

                    return;
                case 'PageUp':
                    move(spot.r - pageSize(), spot.c);

                    return;
                case 'PageDown':
                    move(spot.r + pageSize(), spot.c);

                    return;
                case 'Escape':
                    if (field) {
                        // The cell reverts to what the server sent, which is
                        // why that value is the input's attribute and not
                        // something a script remembered.
                        field.value = field.defaultValue;
                        before.delete(field);
                        event.preventDefault();
                        focusCell(spot.r, spot.c);

                        return;
                    }

                    clearSelection();

                    return;
                case 'Enter':
                    event.preventDefault();

                    if (field) {
                        commit(field);
                        focusCell(spot.r + 1, spot.c);

                        return;
                    }

                    if (isEditable(cell)) {
                        focusCell(spot.r, spot.c, { edit: true });

                        return;
                    }

                    {
                        // A cell whose only affordance is a link: Enter
                        // follows it, so a mouse is not the only way in.
                        const link = cell.querySelector('a[href]');

                        if (link) {
                            link.click();
                        }
                    }

                    return;
                case 'Tab':
                    /*
                     * Tab commits and moves to the next cell WHILE EDITING,
                     * and otherwise leaves the grid.
                     *
                     * Both are deliberate: inside a row of figures Tab is
                     * how a finance user moves across, and on a cell that
                     * is not being edited the grid is one composite widget
                     * that Tab should step over - not 350 stops.
                     */
                    if (!field) {
                        return;
                    }

                    event.preventDefault();
                    commit(field);

                    if (event.shiftKey) {
                        if (spot.c === 0) {
                            focusCell(spot.r - 1, lastColumn, { edit: true });
                        } else {
                            focusCell(spot.r, spot.c - 1, { edit: true });
                        }

                        return;
                    }

                    if (spot.c === lastColumn) {
                        focusCell(spot.r + 1, 0, { edit: true });
                    } else {
                        focusCell(spot.r, spot.c + 1, { edit: true });
                    }

                    return;
                default:
                    break;
            }

            if (ctrl && (key === 'z' || key === 'Z' || key === 'ي')) {
                /*
                 * Ctrl+Z reverts the last COMMITTED cell edit.
                 *
                 * Only outside an input, where the browser's own undo is
                 * the better answer for text somebody is still typing. The
                 * Arabic letter on the same physical key is included
                 * because `event.key` is the character the layout
                 * produces, and an Arabic keyboard produces that one.
                 */
                if (!field) {
                    event.preventDefault();
                    revertLast();
                }

                return;
            }

            if (ctrl && (key === 'c' || key === 'C' || key === 'ؤ')) {
                if (!field) {
                    copySelection(null);
                    event.preventDefault();
                }

                return;
            }

            /*
             * Type-to-edit: a printable character on a focused cell starts
             * editing with that character, the way a spreadsheet does.
             *
             * Without it the keyboard model is "arrow to the cell, then
             * find the key that begins editing", and the figure a user has
             * already started typing is lost.
             */
            if (!field && key.length === 1 && !ctrl && !event.altKey && isEditable(cell)) {
                const target = inputIn(cell);

                if (target) {
                    event.preventDefault();
                    target.focus();
                    target.value = key;
                }
            }
        });

        // The value an input had when editing began - the thing Ctrl+Z goes
        // back to.
        table.addEventListener('focusin', (event) => {
            if (event.target.matches('input') && !before.has(event.target)) {
                before.set(event.target, event.target.value);
            }
        });

        table.addEventListener('focusout', (event) => {
            if (event.target.matches('input')) {
                commit(event.target);
            }
        });

        // Clicking a cell moves the tab stop there, so the arrows carry on
        // from where the pointer left off rather than from the first cell.
        table.addEventListener('mousedown', (event) => {
            const cell = event.target.closest('[data-pf-cell]');
            const spot = cell ? locate(cell) : null;

            if (spot) {
                position = spot;
                clearSelection();
                matrix().forEach((row) => row.forEach((one) => {
                    one.setAttribute('tabindex', one === cell ? '0' : '-1');
                }));
            }
        });

        document.addEventListener('copy', (event) => {
            if (!table.contains(document.activeElement)) {
                return;
            }

            // A real text selection wins: somebody who dragged over two
            // words wants those two words.
            const text = window.getSelection ? String(window.getSelection()) : '';

            if (text.trim() !== '') {
                return;
            }

            copySelection(event);
        });

        const copyButton = root ? root.querySelector('[data-pf-grid-copy]') : null;

        if (copyButton) {
            copyButton.addEventListener('click', () => copySelection(null));
        }

        /* ---------------------------- paste ---------------------------- */

        if (dialog) {
            const count = dialog.querySelector('[data-pf-grid-paste-count]');
            const sample = dialog.querySelector('[data-pf-grid-paste-sample]');
            const apply = dialog.querySelector('[data-pf-grid-paste-apply]');
            const cancel = dialog.querySelector('[data-pf-grid-paste-cancel]');

            const restore = () => {
                if (position) {
                    focusCell(position.r, position.c);
                }
            };

            table.addEventListener('paste', (event) => {
                const cell = event.target.closest('[data-pf-cell]');

                if (!cell) {
                    return;
                }

                const text = (event.clipboardData || window.clipboardData).getData('text/plain');

                if (!text) {
                    return;
                }

                const block = text.replace(/\r\n?/g, '\n').replace(/\n$/, '')
                    .split('\n')
                    .map((line) => line.split('\t'));

                // A single value pasted into a single field is the
                // browser's business, and interrupting it with a dialog
                // would be absurd.
                if (block.length === 1 && block[0].length === 1) {
                    return;
                }

                event.preventDefault();

                const spot = locate(cell) || position || { r: 0, c: 0 };
                const grid = matrix();
                const changes = [];
                let skipped = 0;

                block.forEach((line, r) => {
                    line.forEach((raw, c) => {
                        const target = grid[spot.r + r] ? grid[spot.r + r][spot.c + c] : null;

                        if (!target) {
                            skipped++;

                            return;
                        }

                        const field = inputIn(target);

                        if (!field) {
                            // A pasted block wider than the editable
                            // columns is normal - a user copies a whole
                            // spreadsheet row. The cells that cannot take a
                            // value are counted, not silently dropped.
                            skipped++;

                            return;
                        }

                        const value = westernize(raw).trim();

                        if (value === field.value) {
                            return;
                        }

                        changes.push({ field, value, was: field.value });
                    });
                });

                pending = changes;

                if (count) {
                    count.textContent = say('willChange', changes.length)
                        + (skipped > 0 ? ' · ' + say('skipped', skipped) : '');
                }

                if (sample) {
                    sample.textContent = '';
                    changes.slice(0, 8).forEach((change) => {
                        const line = document.createElement('li');
                        const column = change.field.closest('[data-pf-cell]');
                        line.textContent = (column ? column.getAttribute('data-pf-cell') + ': ' : '')
                            + (change.was === '' ? '—' : change.was) + ' → ' + change.value;
                        sample.appendChild(line);
                    });
                }

                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else {
                    dialog.setAttribute('open', 'open');
                }
            });

            if (apply) {
                apply.addEventListener('click', () => {
                    (pending || []).forEach((change) => {
                        undo.push({ field: change.field, was: change.was });
                        change.field.value = change.value;
                    });

                    announce(say('pasted', (pending || []).length));
                    pending = null;
                    dialog.close ? dialog.close() : dialog.removeAttribute('open');
                    restore();
                });
            }

            if (cancel) {
                cancel.addEventListener('click', () => {
                    pending = null;
                    dialog.close ? dialog.close() : dialog.removeAttribute('open');
                    restore();
                });
            }

            dialog.addEventListener('close', () => {
                pending = null;
                restore();
            });
        }
    };

    /* ====================================================================
     *  THE CURRENCY INPUT (PF-212)
     *
     *  Groups the integer part as you type and normalises digits. It does
     *  NOT round and it does NOT drop a decimal place: an input that
     *  silently truncated a third decimal would turn 1.234 into 1.23 on a
     *  three-decimal currency - the dinar, the rial - and nothing would
     *  report it. Too many decimals is a refusal from the server, where the
     *  currency's exponent is known.
     * ================================================================= */

    const wireCurrency = (field) => {
        const group = (value) => {
            const text = westernize(value).replace(/[^0-9.\-]/g, '');
            const negative = text.startsWith('-');
            const bare = negative ? text.slice(1) : text;
            const cut = bare.indexOf('.');
            const whole = cut === -1 ? bare : bare.slice(0, cut);
            // Everything after the FIRST separator is kept verbatim,
            // including a second one: "1.2.3" stays visible as entered so
            // the server can refuse it, rather than being tidied into a
            // number the user never typed.
            const rest = cut === -1 ? '' : bare.slice(cut);

            return (negative ? '-' : '') + whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + rest;
        };

        const reformat = () => {
            const caretFromEnd = field.value.length - (field.selectionStart || 0);
            const formatted = group(field.value);

            if (formatted !== field.value) {
                field.value = formatted;

                if (field.type === 'text') {
                    const at = Math.max(0, formatted.length - caretFromEnd);
                    field.setSelectionRange(at, at);
                }
            }
        };

        field.addEventListener('input', reformat);
        // A paste arrives before the value changes, so the grouping runs on
        // the next tick - which is also what makes a pasted "١٢٣٤٥٦٧" land
        // as 1,234,567 rather than being rejected as not a number.
        field.addEventListener('paste', () => window.setTimeout(reformat, 0));
        reformat();
    };

    /*
     * Wired once the document has been parsed.
     *
     * The <script> is inlined wherever the first grid on the page is, so
     * at the moment it runs the rows below it do not exist yet. `defer`
     * would be the obvious fix and does nothing at all on an inline
     * script - which is the trap: the page looks wired, and the arrow keys
     * do nothing on every grid that was not the first thing in the body.
     */
    const start = () => {
        document.querySelectorAll('[data-pf-grid]').forEach(wireGrid);
        document.querySelectorAll('[data-pf-currency]').forEach(wireCurrency);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    /*
     * One deliberate seam: the digit normaliser is published.
     *
     * Everything else in this file is wiring, and wiring is checked by
     * rendering the markup it reads (`bin/verify grid` compares the two).
     * `westernize` is the exception - it transforms a VALUE, and a value
     * on its way to a money field is the one thing here that can be
     * wrong rather than merely inert. So it is reachable, and the check
     * runs it: `node -e "...require the file..."` asserts that Arabic
     * digits, a pasted separator and a third decimal all come out as the
     * server expects.
     *
     * It is published under one name, with nothing else beside it, so
     * this is a test seam rather than a public interface somebody starts
     * calling from a page.
     */
    window.pfUi = { westernize };
})();
