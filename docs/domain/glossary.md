# Glossary — المصطلحات

The bilingual domain vocabulary. Three reasons this file exists rather than being left implicit:

1. **Arabic financial terminology is not uniform.** Jordanian, Saudi, Egyptian and Gulf practice differ on several of these, and translators invent a fourth. Fixing the term once means the UI, the documents and the support conversation use the same word.
2. **Several English terms are used loosely in the industry and precisely here.** "Payment" and "allocation" are different objects. "Write-off" and "provision" are different accounts. "Invoice date", "tax point" and "due date" are three dates.
3. **Every term names the standard it comes from**, so a disagreement is resolved by reading a clause rather than by seniority.

> **Translation rule.** A translated label is **never concatenated** with a value. Arabic adjectives agree in gender with their noun, so `":label جديدة"` is wrong for every masculine noun a tenant might choose. Always a placeholder inside the full phrase, so the translator controls agreement: `__('إضافة :label', ['label' => $label])`.

## Core accounting

| English | العربية | Means | Source |
|---------|---------|-------|--------|
| General ledger | دفتر الأستاذ العام | The complete set of accounts and postings for one entity | — |
| Chart of accounts | شجرة الحسابات / الدليل المحاسبي | The tree of accounts an entity posts to | IAS 1 |
| Journal | اليومية | A book of entries grouped by source | — |
| Journal entry | قيد محاسبي | A balanced set of lines posted as one act | — |
| Journal line | بند القيد | One account, one signed amount | — |
| Debit | مدين | Positive side | — |
| Credit | دائن | Negative side | — |
| Posting | الترحيل | Writing an entry irreversibly into the ledger | — |
| Reversal | قيد عكسي | A new entry undoing a posted one. Not an edit | IAS 8 |
| Trial balance | ميزان المراجعة | All account balances at a date; must balance | IAS 1 |
| Control account | حساب مراقبة / حساب إجمالي | A GL account whose balance must equal its subledger | — |
| Subledger | دفتر مساعد | The detail behind a control account | — |
| Dimension | بُعد تحليلي | An analysis axis on a line — cost centre, project | — |
| Fiscal year | السنة المالية | An entity's reporting year; need not be calendar | IAS 1 |
| Period | الفترة المالية | A reporting division of a fiscal year | — |
| Adjusting period | فترة التسويات | Period 13, carrying no calendar days | IAS 10 |
| Year-end close | الإقفال السنوي | Zeroing revenue and expense into retained earnings | — |
| Retained earnings | الأرباح المدورة | Accumulated result carried forward | IAS 1 |
| Functional currency | عملة التعامل الأساسية | The currency of the primary economic environment | IAS 21 |
| Presentation currency | عملة العرض | The currency a statement is presented in | IAS 21 |
| Accrual | مستحق | An expense or revenue recognized before its document | — |
| Entity | الشخصية الاعتبارية / الشركة | A legal company that files its own return | — |
| Tenant | المنشأة المشتركة | A customer of the platform; holds one or more entities | — |

## Receivables and sales

| English | العربية | Means | Source |
|---------|---------|-------|--------|
| Accounts receivable | الذمم المدينة / المدينون | What customers owe | — |
| Quote / estimate | عرض سعر | A non-binding offer. Not an accounting document | — |
| Sales order | أمر بيع | A confirmed commitment, before invoicing | — |
| Invoice | فاتورة | A commercial invoice | UNCL 1001 `380` |
| Simplified invoice | فاتورة مبسطة | Reduced field set, where a jurisdiction permits | — |
| Credit note | إشعار دائن | Reduces an issued invoice | UNCL 1001 `381` |
| Debit note | إشعار مدين | Adds to an issued invoice | UNCL 1001 `383` |
| Corrected invoice | فاتورة مصححة | Replaces an invoice where a credit is not legal | UNCL 1001 `384` |
| Prepayment invoice | فاتورة دفعة مقدمة | Cash before performance — a liability | UNCL 1001 `386` |
| Self-billed invoice | فاتورة ذاتية | The buyer issues on the supplier's behalf | UNCL 1001 `389` |
| Invoice date | تاريخ الفاتورة | When the document was issued | EN 16931 `BT-2` |
| **Tax point** | **تاريخ الاستحقاق الضريبي** | **Which return period the tax falls in. Distinct from both the invoice date and the delivery date** | EN 16931 `BT-7` |
| Due date | تاريخ الاستحقاق | When payment is required | EN 16931 `BT-9` |
| Payment terms | شروط الدفع | The rule deriving the due date | UNCL 2475 |
| Dunning | المطالبة / التحصيل | Escalating reminders on an overdue invoice | — |
| Dispute | اعتراض / نزاع | A formally queried invoice; stops dunning | — |
| Credit limit | سقف الائتمان | The maximum exposure permitted to a customer | — |
| Aging | أعمار الذمم | Open balances grouped by how overdue they are | — |
| DSO | متوسط فترة التحصيل | Days sales outstanding | — |
| Deferred revenue | إيراد مؤجل | Billed, not yet earned | IFRS 15 |
| Revenue recognition | الاعتراف بالإيراد | Recording revenue when the obligation is satisfied | IFRS 15 |

## Payables and purchasing

| English | العربية | Means | Source |
|---------|---------|-------|--------|
| Accounts payable | الذمم الدائنة / الدائنون | What is owed to suppliers | — |
| Purchase requisition | طلب شراء | An internal request, before a commitment | — |
| Purchase order | أمر شراء | A commitment to a supplier | — |
| Goods receipt | إشعار استلام | Confirmation of what arrived | — |
| Bill / supplier invoice | فاتورة مورد | A supplier's invoice to us | — |
| Three-way match | المطابقة الثلاثية | Bill against order against receipt | — |
| Approval workflow | مسار الموافقات | The ordered chain a document must pass | — |
| Expense claim | مطالبة مصروفات | An employee's out-of-pocket claim | — |
| DPO | متوسط فترة السداد | Days payable outstanding | — |

## Banking and settlement

| English | العربية | Means | Source |
|---------|---------|-------|--------|
| Bank statement | كشف حساب بنكي | The bank's record of a period | ISO 20022 CAMT.053 |
| Bank transaction | حركة بنكية | One line on a statement. Immutable | — |
| Booking date | تاريخ القيد البنكي | When the bank booked it | ISO 20022 |
| Value date | تاريخ القيمة | When funds are available. Used for FX and forecasting | ISO 20022 |
| Reconciliation | التسوية البنكية / المطابقة البنكية | Explaining the difference between the GL and the statement | — |
| Matching | المطابقة | Linking a bank line to a document | — |
| Suspense account | حساب معلق / حساب وسيط | Where an unexplained line sits until resolved | — |
| **Payment** | **عملية دفع / سداد** | **Cash moving. Distinct from allocation** | — |
| **Allocation** | **تخصيص السداد** | **Saying which obligation a payment settles** | — |
| Unapplied cash | مقبوضات غير مخصصة | Received, not yet allocated. A liability | — |
| Payment run | دفعة سداد مجمعة | A batch of outgoing payments | — |
| Maker-checker | مبدأ المُعِد والمُعتمِد | The preparer and the releaser must differ | SOC 2 |
| Payment initiation | إصدار أمر الدفع | The instruction file sent to the bank | ISO 20022 PAIN.001 |
| In transit | مدفوعات في الطريق | Released, not yet on a statement | — |
| IBAN | رقم الحساب المصرفي الدولي | International bank account number | ISO 13616 |
| BIC / SWIFT | رمز التعريف المصرفي | Bank identifier code | ISO 9362 |
| Mandate | تفويض خصم | Authorization for direct debit | EPC SEPA SDD |
| **Write-off** | **إعدام دين / شطب** | **A conclusion: the debt will not be collected** | — |
| **Provision (ECL)** | **مخصص خسائر ائتمانية** | **An estimate of future loss. A different account** | IFRS 9 |
| Realized FX | فروق عملة محققة | Currency difference crystallized by settlement | IAS 21 |
| Unrealized FX | فروق عملة غير محققة | Period-end revaluation of an open balance | IAS 21 |

## Tax

| English | العربية | Means | Source |
|---------|---------|-------|--------|
| VAT | ضريبة القيمة المضافة | Value added tax | — |
| Output tax | ضريبة المخرجات | Tax charged on sales | — |
| Input tax | ضريبة المدخلات | Tax recoverable on purchases | — |
| Tax category | فئة الضريبة | `S`, `Z`, `E`, `AE`, `O`, `K`, `G` | UNCL 5305 |
| Standard rate | النسبة الأساسية | Category `S` | UNCL 5305 |
| Zero-rated | معفاة بنسبة صفر / خاضعة بنسبة صفر | A taxable supply at 0%; input recoverable | UNCL 5305 `Z` |
| Exempt | غير خاضعة | Not a taxable supply; restricts input recovery | UNCL 5305 `E` |
| Out of scope | خارج نطاق الضريبة | Outside the regime entirely | UNCL 5305 `O` |
| Reverse charge | الاحتساب العكسي | The recipient accounts for the tax | UNCL 5305 `AE` |
| Place of supply | مكان التوريد | Which jurisdiction's tax applies | — |
| Withholding tax | ضريبة مقتطعة من المنبع | Deducted by the payer from the payment | — |
| Tax breakdown | تفصيل الضريبة | One entry per category and rate | EN 16931 `BG-23` |
| Tax return | الإقرار الضريبي | A period's filing | — |

## E-invoicing

| English | العربية | Means | Source |
|---------|---------|-------|--------|
| E-invoicing | الفاتورة الإلكترونية | Issuing in a structured, machine-readable form | — |
| Semantic model | النموذج الدلالي | The meaning of an invoice, independent of syntax | EN 16931 |
| Business term | المصطلح الأعمالي | A numbered field in the semantic model | EN 16931 `BT-*` |
| Syntax | الصياغة / البنية | UBL, CII — how the meaning is written | — |
| CIUS | مواصفة استخدام أساسية | A restriction of EN 16931 for a context | EN 16931 |
| Clearance | الإجازة | An authority approves before delivery | ZATCA |
| Reporting | الإبلاغ | An authority is notified after issue | ZATCA |
| Cryptographic stamp | الختم التشفيري | A seal proving authorship | ZATCA, eIDAS |
| Hash chain | سلسلة البصمات | Each document hashed with its predecessor | — |
| PIH | بصمة الفاتورة السابقة | Previous invoice hash | ZATCA |
| Gapless numbering | التسلسل المتصل دون فجوات | A legal sequence with no missing numbers | — |

## Identity and control

| English | العربية | Means | Source |
|---------|---------|-------|--------|
| Permission | صلاحية | May this user do X | — |
| **Segregation of duties** | **فصل المهام** | **May the same user do X and then Y to the same object. A different question from a permission** | ISO/IEC 27001 |
| Incompatible duties | مهام متعارضة | A pair that must be performed by different users | — |
| Step-up authentication | إعادة التحقق المعزز | Re-proving identity at the moment of an act | OWASP ASVS |
| Passkey | مفتاح المرور | A phishing-resistant credential | WebAuthn |
| Audit trail | سجل التدقيق | Append-only record of who did what | SOC 2 |
| Impersonation | انتحال الجلسة | An operator acting as a tenant user, with consent | — |

## Terms deliberately avoided

| Avoided | Use instead | Why |
|---------|-------------|-----|
| "Delete" for anything financial | Reverse, credit, unreconcile | Nothing financial is deleted; the word implies it is |
| "Edit" an issued document | Credit and re-issue | It is not editable, and the word suggests it is |
| قيد يدوي for every manual entry | قيد يومية عامة | The ledger distinguishes a general-journal entry from an adjustment |
| "Balance" without qualification on a bank account | Ledger / statement / available balance | Three different numbers; collapsing them deletes the reconciliation question |
| "Bank" as a noun for an account | Bank account, or its GL mirror | The account and its GL mirror are different objects and must stay so |
| "Total" without a basis | Net / tax / gross | Three values; a bare "total" is a support ticket |
