<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Invoicing against an order, capped per line - PF-090.
 *
 * ## Why the trigger adds and the CHECK refuses
 *
 * The addition is an `UPDATE` that re-reads the committed row when it waits
 * for another transaction, so two invoices issued at the same instant cannot
 * both see the same remaining balance. The refusal is a CHECK on the row,
 * which means the cap holds against any writer at all - a second issuing
 * path, an import, a correction script - and not only against the code that
 * does the adding.
 *
 * Measured on the credit-note cap first: a locking `SELECT sum(...)` inside
 * a trigger is bound by the snapshot of the statement that fired it, so the
 * lock is taken, the wait happens, and the figure read afterwards is still
 * the one from before the other transaction committed.
 *
 * ## Why a cancelled order with invoiced lines is refused, in the database
 *
 * Because the invoice exists. Cancelling the order it came from leaves a
 * document in a legal sequence pointing at an agreement the system says
 * never happened, and the two readings of the customer's account disagree
 * by the invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_record_order_invoicing() RETURNS trigger AS $$
            DECLARE
                line record;
            BEGIN
                /*
                 | Every line of this document that points at an order line.
                 |
                 | A credit note reverses it: crediting an invoice puts the
                 | balance back, because the order has not been invoiced for
                 | what was credited. Without this, crediting and re-issuing
                 | an invoice would exhaust an order nobody has delivered.
                 */
                FOR line IN
                    SELECT l.order_line_id, l.quantity, l.net_amount
                    FROM invoice_lines l
                    WHERE l.invoice_id = NEW.id
                      AND l.order_line_id IS NOT NULL
                LOOP
                    IF NEW.document_type = '381' THEN
                        UPDATE sales_order_lines
                        SET invoiced_quantity = greatest(invoiced_quantity - line.quantity, 0),
                            invoiced_net = greatest(invoiced_net - line.net_amount, 0),
                            updated_at = now()
                        WHERE id = line.order_line_id;
                    ELSE
                        UPDATE sales_order_lines
                        SET invoiced_quantity = invoiced_quantity + line.quantity,
                            invoiced_net = invoiced_net + line.net_amount,
                            updated_at = now()
                        WHERE id = line.order_line_id;
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER invoices_record_what_they_invoice
                AFTER UPDATE ON invoices
                FOR EACH ROW
                WHEN (NEW.status = 'issued' AND OLD.status = 'draft')
                EXECUTE FUNCTION peakflow_record_order_invoicing();

            CREATE OR REPLACE FUNCTION peakflow_refuse_cancelling_an_invoiced_order() RETURNS trigger AS $$
            DECLARE
                invoiced numeric(21,4);
            BEGIN
                SELECT coalesce(sum(invoiced_net), 0) INTO invoiced
                FROM sales_order_lines
                WHERE sales_order_id = NEW.id;

                IF invoiced > 0 THEN
                    RAISE EXCEPTION
                        'order % has % invoiced against it and cannot be cancelled: the invoice '
                        'exists, and an order that says it never happened leaves a document in a '
                        'legal sequence pointing at an agreement nobody made. Credit the invoices '
                        'first, which puts the balance back and leaves both documents in the record',
                        NEW.reference,
                        invoiced
                    USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER sales_orders_with_invoices_cannot_be_cancelled
                BEFORE UPDATE ON sales_orders
                FOR EACH ROW
                WHEN (NEW.status = 'cancelled' AND OLD.status <> 'cancelled')
                EXECUTE FUNCTION peakflow_refuse_cancelling_an_invoiced_order();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS invoices_record_what_they_invoice ON invoices;
            DROP FUNCTION IF EXISTS peakflow_record_order_invoicing();
            DROP TRIGGER IF EXISTS sales_orders_with_invoices_cannot_be_cancelled ON sales_orders;
            DROP FUNCTION IF EXISTS peakflow_refuse_cancelling_an_invoiced_order();
        SQL);
    }
};
