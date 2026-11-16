<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Enums;

/**
 * How the money moved, coded per UNCL 4461 - PF-166.
 *
 * ## Why the e-invoicing code list rather than our own words
 *
 * Because the invoice already carries it. A payment recorded as "transfer"
 * here and `30` on the document it settles is two vocabularies for one
 * fact, and the mapping between them is work nobody budgeted for - written
 * twice, in a serializer and in a report, and wrong in one of them.
 *
 * The subset is the one this system can actually produce and post. A code
 * outside it is refused rather than stored: a method nothing knows how to
 * post is a payment that reaches the ledger by whichever branch happened
 * to be last.
 */
enum PaymentMethod: string
{
    case NotDefined = '1';
    case InCash = '10';
    case Cheque = '20';
    case CreditTransfer = '30';
    case PaymentToBankAccount = '42';
    case BankCard = '48';
    case DirectDebit = '49';
    case SepaCreditTransfer = '58';
    case SepaDirectDebit = '59';
    case OnlinePayment = '68';
    case ClearingBetweenPartners = '97';

    /**
     * Whether this method moves money through a bank account.
     *
     * `10` is notes in a drawer and `97` is a set-off between two parties
     * that never reaches a bank. Everything else does, and the difference
     * decides which account a payment may name - a credit transfer against
     * a cash account is a payment nobody can trace to a statement.
     */
    public function movesThroughABank(): bool
    {
        return match ($this) {
            self::InCash, self::ClearingBetweenPartners => false,
            default => true,
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::NotDefined => 'unspecified',
            self::InCash => 'cash',
            self::Cheque => 'cheque',
            self::CreditTransfer => 'credit transfer',
            self::PaymentToBankAccount => 'payment to bank account',
            self::BankCard => 'bank card',
            self::DirectDebit => 'direct debit',
            self::SepaCreditTransfer => 'SEPA credit transfer',
            self::SepaDirectDebit => 'SEPA direct debit',
            self::OnlinePayment => 'online payment',
            self::ClearingBetweenPartners => 'clearing between partners',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::NotDefined => 'غير محدد',
            self::InCash => 'نقداً',
            self::Cheque => 'شيك',
            self::CreditTransfer => 'حوالة',
            self::PaymentToBankAccount => 'إيداع في حساب بنكي',
            self::BankCard => 'بطاقة بنكية',
            self::DirectDebit => 'خصم مباشر',
            self::SepaCreditTransfer => 'حوالة سيبا',
            self::SepaDirectDebit => 'خصم مباشر سيبا',
            self::OnlinePayment => 'دفع إلكتروني',
            self::ClearingBetweenPartners => 'مقاصة بين الطرفين',
        };
    }
}
