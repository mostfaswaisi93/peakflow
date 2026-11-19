# خطة البناء — متجر ملابس على PeakFlow: نقاط البيع، المخازن، المقاسات والألوان، التقارير

> **للوكلاء المنفّذين:** نفّذ مهمةً مهمة بالترتيب المكتوب في «خريطة المراحل». الخطوات بصيغة المربعات (`- [ ]`) للتتبّع، ودورة كل مهمة مكتوبة مرة واحدة في «دورة كل مهمة» ولا تتكرر تحت كل مهمة.

**الهدف:** أن يستطيع متجر ملابس بعدة فروع ومخازن أن يدير أصنافه بمقاساتها وألوانها، ويبيع من الكاشير ولو انقطعت الشبكة، ويحرّك البضاعة بين المخازن، ويرى تقاريره — وكل ذلك يُرحَّل إلى الدفتر العام الذي بُني أصلًا.

**المعمارية:** المنطق في الوحدات القائمة (`Catalog` للمتغيرات، `Inventory` للحركات، `Sales` و`Purchasing` يحرّكان المخزون) ووحدة جديدة `Pos` للصناديق والورديات. الشاشات Livewire 3 + Alpine كما في ADR-0006، **باستثناء شاشة الكاشير** التي تعمل بلا شبكة: تطبيق Alpine مع IndexedDB وService Worker يرسل البيع عبر آلية المزامنة القائمة (PF-255).

**التقنيات:** PHP 8.3، Laravel 13، PostgreSQL 16، Livewire 3، Alpine 3، Tailwind 4، `brick/math`. للكاشير: JavaScript بلا إطار، IndexedDB، Service Worker، `node --test` للاختبار.

**المرجع (ما تُبنى الخطة عليه):**
- `docs/tasks/done/phase-14-operations.md` — PF-247 حتى PF-255 (المخزون، المتغيرات، الدفعات، المرتجعات، الجرد، الأسعار، المزامنة)
- `docs/tasks/phase-15-verticals.md` — PF-264 نقطة البيع
- `docs/tasks/phase-11-design-system.md` — PF-209 باني الفاتورة، PF-212 نظام النماذج، PF-213 تغطية الحالات
- `docs/architecture/decisions/ADR-0006-server-driven-ui.md` و`ADR-0009-gapless-numbering.md`
- README، قسم «Where rounding happens, exactly once»
- `docs/plan/2026-11-19-roadmap.md` — الموجة 1 (الواجهة) والقسم 5 (توصية البدء بنقطة البيع وحدها من المرحلة 15)

---

## نقطة البداية

| الجزء | الموجود الآن | الناقص |
|---|---|---|
| المتغيرات (مقاس، لون) | الجداول `item_axes` و`item_axis_values` و`item_variants` | لا نموذج ولا خدمة، والمخزون لا يُتتبَّع لكل متغيّر، والباركود غير فريد |
| المخزون | `Stock` (استلام، صرف، تسوية، مرتجع) بتكلفة مجمّدة وقيد تلقائي، جرد، دفعات، أرقام تسلسلية | التحويل بين المخازن، الربط بالبيع والشراء |
| البيع | الفاتورة والفاتورة المبسّطة والمرتجع (`SalesReturns` يعيد المخزون) | إصدار الفاتورة لا يصرف المخزون، ولا أسعار شاملة الضريبة |
| الشراء | أمر الشراء والاستلام والمطابقة الثلاثية | الاستلام لا يضيف للمخزون، وفاتورة المورّد لا تصفّي حساب «بضاعة مستلمة لم تُفوتر» للأصناف المخزنية |
| نقطة البيع | المزامنة والأجهزة (PF-255) | كل شيء آخر |
| الشاشات | قوائم عرض فقط | كل نماذج الإدخال |
| التقارير | التقارير المالية | تقارير المتجر كلها |

---

## القيود العامة

كل مهمة في هذه الخطة تخضع لهذه القيود ضمنًا:

- **لا يُعلَّم معيار `[x]` إلا بفحص في `bin/verify` يفشل عند إزالة السلوك**، مع «عضّة» تُثبت أنه يفشل فعلًا (`docs/tasks/README.md`).
- **`docs/domain/schema.md` يُحدَّث قبل كتابة الهجرة**، وكل قيد محاسبي يُكتب مدينًا ودائنًا في المهمة قبل الكود (تعريف الجاهزية).
- المال `Money` بأربعة منازل. تكلفة الوحدة `BigDecimal` بستة منازل (PF-248). التقريب `HALF_UP` مرة واحدة: صافي السطر، ثم ضريبة كل مجموعة نسبة.
- **لا عمود كمية مخزّن.** الكمية مشتقة من `stock_movements` دائمًا (PF-247).
- **لا عمود `size` ولا `colour`.** المحاور بيانات، والفحص `axesAreData` قائم ويبقى ناجحًا.
- كل جدول جديد يحمل `entity_id` ويُطبَّق عليه `peakflow_apply_entity_rls`.
- كل اعتماد جديد بين وحدتين يُضاف إلى `deptrac.yaml` **مع تعليق يشرح لماذا**، على نمط التعليقات الموجودة.
- كل نص بالعربية والإنجليزية. الواجهات بخصائص CSS منطقية فقط (RTL).
- الأذونات الجديدة في `database/seeders/Tenant/PermissionSeeder.php`، والأدوار في `RoleSeeder.php`.
- الحركات والقيود لا تُحذف ولا تُعدَّل. التصحيح حركة جديدة.
- **الترقيم بلا فجوات (ADR-0009):** أي رفض يحدث بعد حجز رقم يجب أن يُرجع الحجز داخل المعاملة نفسها.
- رسائل الكوميت بصيغة السجل: `feat(inventory): …`، `fix(sales): …`.

---

## دورة كل مهمة

كل مهمة تمرّ بهذه الخطوات. تحت كل مهمة تجد فقط ما يخصّها: الملفات، الواجهات، المعايير، الفحوص، والقيد.

- [ ] **1.** حدّث `docs/domain/schema.md` بأي تغيير في المخطط، واكتب القيد مدينًا ودائنًا إن كانت المهمة تُرحّل.
- [ ] **2.** اكتب الفحوص بأسمائها المذكورة في المهمة، في ملف `tests/Support/<Suite>Rules.php` المذكور، وأضفها إلى `all()`.
- [ ] **3.** شغّل `php bin/verify <suite>` — يجب أن تفشل الفحوص الجديدة **بالسبب المتوقع**، لا بخطأ تحميل.
- [ ] **4.** نفّذ.
- [ ] **5.** شغّل `php bin/verify <suite>` ثم `php bin/verify` كاملًا — كل شيء ينجح، والقديم لم ينكسر.
- [ ] **6.** العضّة: أزل السلوك مؤقتًا، تأكد أن الفحص يفشل، ثم أرجعه.
- [ ] **7.** `composer lint` و`composer analyse` و`php bin/verify arch` نظيفة (Deptrac غير مثبّت هنا، و`ArchitectureRules` يقرأ اتجاهات الطبقات من `deptrac.yaml` نفسه)، وللشاشات `php artisan test --filter=<Test>`.
- [ ] **8.** علّم المعايير في ملف المرحلة، وحدّث جدول التقدم، وشغّل `php bin/verify repo`.
- [ ] **9.** Commit.

---

## محور المراجعة

خمس حالات لا يغطيها نصّ المواصفات صراحةً، وهي الأرجح أن تؤذي مستخدمًا حقيقيًا. لكلٍّ منها فحص في المهمة المذكورة:

1. **بيع يُرفض لنقص المخزون يجب ألا يترك فجوة في ترقيم الفواتير.** الفحص `a refused sale leaves no gap in the numbering` في R3.
2. **الكاشير والخادم يجب أن يحسبا السلة نفسها حتى آخر فلس.** الكاشير يحسب بلا شبكة، والخادم يعيد الحساب عند المزامنة. الفحص `the server's totals equal the till's for every golden basket` في R13، والوجه الآخر منه في PF-264.
3. **جهازان بلا شبكة يبيعان آخر قطعة من المقاس نفسه.** الخادم يقبل الأول، ويُظهر الثاني كتعارض يقرّر فيه شخص، ولا يدمج بصمت ولا يسجّل مخزونًا سالبًا بلا سبب. الفحص `two tills selling the last large surface a conflict` في R13.
4. **باركود ممسوح لا يطابق شيئًا، أو يطابق متغيّرًا موقوفًا.** يُرفض برسالة تسمّي السبب، ولا يُضاف سطر بلا سعر. الفحص `an unknown barcode adds no line` في PF-264.
5. **فاتورة مورّد بسعر يختلف عن سعر الاستلام.** حساب «بضاعة مستلمة لم تُفوتر» يُصفّى بالضبط، والفرق يذهب إلى حساب واحد مسمّى. الفحص `a price difference lands in purchase price variance` في R5.

---

## قرارات تحتاجك قبل المرحلة 2

لكل قرار اقتراح، والخطة مكتوبة عليه. إن اخترت غيره تتغيّر المهمة المذكورة فقط:

| # | القرار | المقترح | يؤثر على |
|---|---|---|---|
| 1 | الدولة والملف الضريبي (يحدد شكل الفاتورة المبسّطة، ورمز QR، ونسبة الضريبة) | — يحتاج جوابك | R4، PF-264 |
| 2 | فرق سعر المورّد عن سعر الاستلام | حساب مستقل «فروقات أسعار الشراء»، لأنه يبقى مرئيًا ولا يغيّر تكلفة بضاعة ربما بيعت | R5 |
| 3 | التكلفة لكل متغيّر أم لكل صنف | لكل متغيّر (كل مقاس ولون رمز مخزني مستقل، والمعطف الكبير قد يكلف أكثر) | R2 |
| 4 | طباعة الإيصال | طباعة المتصفح على ورق 80 مم، بلا تعريف طابعة خاص | PF-264 |
| 5 | الدفع بالبطاقة | يُسجَّل المبلغ يدويًا في حساب وسيط، وتطابقه البنوك لاحقًا. الربط المباشر بجهاز البنك خارج النطاق | R12 |

---

## خريطة المراحل

| المرحلة | المهام | النقاط | تعتمد على | متى تبدأ |
|---|---|---|---|---|
| 0 · القرار والتسجيل | ADR-0011، ADR-0012، تسجيل المهام | 2 | — | الآن |
| 1 · المتغيرات مخزونًا حقيقيًا | R1، R2 | 13 | المرحلة 0 | الآن |
| 2 · ربط المستندات بالمخزون | R3، R4، R5، R6 | 20 | R2، والقرارات 1–3 | بعد المرحلة 1 |
| 3 · شاشات الإدخال | R7–R11، و PF-209 | 30 + 8 | PF-212، PF-213، والمرحلة 2 | بعد إغلاق PF-212 وPF-213 |
| 4 · نقطة البيع | R12، R13، PF-264، R14، R15، R16 | 26 + 13 | المراحل 2 و3 | بعد المرحلة 3 |
| 5 · تقارير المتجر | R17، R18، R19 | 13 | المرحلة 4 | بعد المرحلة 4 |
| 6 · التشغيل الفعلي | R20، R21 | 7 | كل ما سبق | الأخيرة |
| **المجموع** | | **111 جديدة + 21 قائمة = 132** | | |

بمقياس المشروع (النقطة ≈ نصف يوم عمل مركّز) هذا نحو **66 يوم عمل**، من غير PF-212 وPF-213 اللتين تنتميان أصلًا إلى الموجة 1 في الخطة الكبيرة.

**المرحلتان 1 و2 لا تحتاجان أي شاشة**، فتبدآن الآن بالتوازي مع الموجة 1 (الواجهة).

**المعرّفات:** `R1…R21` تسميات مؤقتة. تأخذ كل مهمة معرّف `PF` الحرّ التالي **عند تسجيلها** (المهمة 0.3)، لأن جلسات أخرى تعمل في المستودع الآن وتحجز أرقامًا. القسم 0 في الخطة الكبيرة يشرح ما يحدث حين يُؤخذ رقم بلا قراءة الدليل.

---

## المرحلة 0 — القرار والتسجيل

### المهمة 0.1 · ADR-0011: PeakFlow تتبنّى نقطة البيع للتجزئة

**الملفات:**
- إنشاء: `docs/architecture/decisions/ADR-0011-retail-point-of-sale.md`
- تعديل: `docs/architecture/decisions/README.md`، و`README.md` (فقرة «Not a POS» تصبح إشارة إلى هذا القرار)، و`docs/tasks/phase-15-verticals.md` (PF-264 داخل النطاق، والسبعة الباقية خارجه)

**المعايير:**
- [ ] القرار يقول ما يُلزم به المنتج: متجر تجزئة واحد النوع (ملابس أولًا)، بفروع ومخازن وكاشير يعمل بلا شبكة
- [ ] يقول ما **لا** يُلزم به: المطعم والصيدلية والوقود وقطع السيارات والورشة تبقى خارج النطاق، بالسبب المكتوب في رأس المرحلة 15
- [ ] البدائل التي خسرت (حوالاتي للمحل وPeakFlow للحسابات) مكتوبة مع سبب الخسارة

### المهمة 0.2 · ADR-0012: الكاشير يعمل بلا شبكة

**الملفات:**
- إنشاء: `docs/architecture/decisions/ADR-0012-offline-till.md`

**المعايير:**
- [ ] يسجّل الاستثناء من ADR-0006: شاشة الكاشير ليست Livewire، لأن Livewire يحتاج الخادم في كل ضغطة، وPF-264 يشترط «nothing in that path may call the network»
- [ ] يسجّل الاستثناء من PF-209 («tax recalculates on the server and only on the server»): الكاشير يحسب ضريبة السلة على الجهاز. **والضمانة:** ملفات سلال ذهبية مشتركة يمرّ عليها الحساب في PHP وفي JavaScript، وفحص يفشل عند أي اختلاف (R13 وPF-264)
- [ ] يسجّل ترقيم الإيصالات بلا شبكة: **لكل صندوق سلسلة أرقام خاصة**، يخصّص الجهاز الرقم التالي محليًا، والخادم عند المزامنة يقبل الرقم إذا كان التالي تمامًا في سلسلة ذلك الصندوق. الفجوة أو التكرار تعارضٌ يظهر لشخص، ولا يُعاد الترقيم أبدًا
- [ ] يسجّل ما يحتاج الشبكة عمدًا: استبدال رصيد المتجر (store credit)، ومرتجع إيصال صادر من صندوق آخر

### المهمة 0.3 · تسجيل المهام في الـbacklog

**الملفات:**
- إنشاء: `docs/tasks/phase-16-retail.md` — المهام R1–R21 بصيغة المهام المعتمدة (الوحدة، النقاط، الاعتماديات، المعايير)
- تعديل: `docs/tasks/backlog.md`، و`docs/tasks/README.md` (جدولا المراحل والتقدم)، و`docs/tasks/phase-15-verticals.md` (إعادة تقدير PF-264 من 5 إلى 13، مع السبب: التقدير الأصلي يغطي منطق البيع لا شاشته)

**المعايير:**
- [ ] كل مهمة أخذت معرّفها من **أول رقم حرّ في الدليل لحظة التسجيل**، والجدول في هذه الخطة حُدِّث بالأرقام الفعلية
- [ ] `php bin/verify repo` ينجح

---

## المرحلة 1 — المتغيرات مخزونًا حقيقيًا

### R1 · نماذج المتغيرات وخدمتها
`Catalog` · **5 نقاط** · يعتمد على: PF-249 · الفحوص: `php bin/verify stock`

**الملفات:**
- إنشاء: `app/Tenant/Catalog/Models/ItemAxis.php`، `ItemAxisValue.php`، `ItemVariant.php`
- إنشاء: `app/Tenant/Catalog/Services/Variants.php`
- إنشاء: `app/Tenant/Catalog/Exceptions/VariantRefused.php`
- إنشاء: `database/migrations/tenant/<التاريخ>_a_barcode_names_one_variant.php`
- تعديل: `app/Tenant/Catalog/Models/Item.php` — علاقة `variants()` و`hasVariants(): bool`
- فحوص: `tests/Support/StockRules.php`، في قسم «Variants - PF-249» الموجود (المساعدات `aStockedItem` و`anAxis` و`aVariant` هناك)

**الواجهات — ما تنتجه لما بعدها:**
```php
Variants::defineAxis(string $entityId, string $code, string $nameAr, string $nameEn): ItemAxis
Variants::addValue(string $axisId, string $code, string $nameAr, string $nameEn): ItemAxisValue
Variants::create(string $itemId, array $axes, ?string $code = null, ?string $barcode = null, ?string $priceOverride = null): ItemVariant
    // $axes: array<axisCode, valueCode>
Variants::createMany(string $itemId, array $combinations): list<ItemVariant>
    // $combinations: list<array<axisCode, valueCode>> — ما يُعلَّم في شبكة المقاس × اللون فقط
Variants::byBarcode(string $entityId, string $barcode): ?ItemVariant   // النشط وغير المحذوف فقط
Variants::priceOn(ItemVariant $variant, string $currency, Carbon $on, ?string $partyId = null): BigDecimal
    // price_override إن وُجد، وإلا PriceResolver::resolve() للصنف
ItemVariant::label(string $locale): string   // «قميص كتان — أحمر / L»
```

**الهجرة:** فهرس فريد جزئي `item_variants_barcode_once` على `(entity_id, barcode) WHERE barcode IS NOT NULL AND deleted_at IS NULL`.

**المعايير والفحوص:**
- [ ] `a barcode names one variant` — متغيّران بالباركود نفسه في كيان واحد: الثاني يُرفض من قاعدة البيانات، ورسالة الخدمة **تسمّي المتغيّر الذي يحمله**
- [ ] `a variant code defaults to the item and its values` — صنف `TSHIRT`، المحور `SIZE` في الموضع 1 و`COLOUR` في الموضع 2، والتركيبة `{COLOUR: RED, SIZE: L}` ← الرمز `TSHIRT-L-RED` (بترتيب موضع المحور، لا بترتيب المفاتيح)
- [ ] `creating a combination twice returns the first` — استدعاء `createMany` مرتين بالتركيبات نفسها لا يزيد عدد الصفوف
- [ ] `a combination names values that exist` — محور أو قيمة غير معرّفة: رفض يسمّي أيّهما
- [ ] `a variant price falls back to the item's` — متغيّر بلا `price_override` يعيد سعر الصنف من قائمة الأسعار في ذلك التاريخ

### R2 · المخزون لكل متغيّر
`Inventory` · **8 نقاط** · يعتمد على: R1 · القرار 3 · الفحوص: `php bin/verify stock`

**الملفات:**
- إنشاء: `database/migrations/tenant/<التاريخ>_stock_moves_per_variant.php`
- تعديل: `app/Tenant/Inventory/Services/Stock.php` — `receive` (السطر 62)، `issue` (101)، `adjust` (186)، `returnIn` (287)، `costOnRecord` (335)، `onHand` (356)
- تعديل: `app/Tenant/Inventory/Services/StockCounts.php` — `open` و`sheet` و`enter`: سطر لكل متغيّر
- تعديل: `app/Tenant/Sales/Services/SalesReturns.php` — يقرأ `variant_id` من حركة الصرف الأصلية ويمرّره إلى `returnIn` (السطر 326)

**الواجهات:**
```php
// يُضاف معامل أخير اختياري، فلا ينكسر أي مستدعٍ قائم يستخدم الوسائط المسمّاة
Stock::receive(..., ?string $batchId = null, ?string $variantId = null): array
Stock::issue(..., ?string $expiryAcceptedReason = null, ?string $variantId = null): array
Stock::adjust(..., string $kind = 'adjustment', ?string $variantId = null): array
Stock::returnIn(..., ?string $variantId = null): array
Stock::onHand(string $entityId, string $itemId, ?string $locationId = null, ?string $variantId = null): BigDecimal
    // $variantId = null ← مجموع الصنف بكل متغيراته، كما اليوم
Stock::onHandByVariant(string $entityId, string $itemId, ?string $locationId = null): array   // array<variantId, BigDecimal>
```

**الهجرة:**
- مُشغّل `peakflow_a_variant_item_names_its_variant`: صنف له متغيّر واحد على الأقل (غير محذوف) لا تتحرك له بضاعة بلا `variant_id`، والمتغيّر يجب أن يتبع الصنف نفسه. على نمط `peakflow_a_tracked_movement_names_its_batch`.
- استبدال `peakflow_stock_does_not_go_negative`: المجموع على `(item_id, variant_id, location_id)` باستخدام `IS NOT DISTINCT FROM`، مع قفل صف الصنف كما اليوم.
- `stock_count_lines`: الفهرس الفريد يصبح `(count_id, item_id, variant_id, batch_id)` بـ `NULLS NOT DISTINCT`.

**المعايير والفحوص:**
- [ ] `stock is counted per variant` — استلام 5 من L و3 من M: `onHand(variant: L) = 5`، و`onHand(item) = 8`
- [ ] `an item with variants cannot move without naming one` — إدخال حركة مباشرة في قاعدة البيانات بلا `variant_id` ← `check_violation`
- [ ] `a movement naming another item's variant is refused`
- [ ] `the last large cannot be sold twice` — 1 من L و5 من M: صرف 2 من L يُرفض، مع أن مجموع الصنف 6
- [ ] `a variant's cost is its own` — L استُلم بـ 10 وM بـ 12 (متوسط مرجّح): صرف L يُكلَّف 10.000000
- [ ] `an item with stock cannot gain its first variant` — الرسالة تقول: انقل الرصيد إلى متغيّر أولًا بتسوية مزدوجة
- [ ] `a count sheet has a line per variant`
- [ ] `a return restores the variant that was sold`
- [ ] كل فحوص PF-247 حتى PF-253 القائمة تبقى ناجحة (الأصناف بلا متغيرات لا يتغيّر سلوكها)

---

## المرحلة 2 — ربط المستندات بالمخزون

### R3 · إصدار الفاتورة يصرف المخزون
`Sales` · **5 نقاط** · يعتمد على: R2 · الفحوص: `php bin/verify stock`، `php bin/verify sales`

**الملفات:**
- إنشاء: `database/migrations/tenant/<التاريخ>_a_sale_moves_its_stock.php` — `invoice_lines.variant_id` (اختياري، مفتاح أجنبي إلى `item_variants`)، و`invoices.location_id` (اختياري، مفتاح أجنبي إلى `stock_locations`)، ومُشغّل: المتغيّر يتبع صنف السطر
- تعديل: `app/Tenant/Sales/Models/Invoice.php`، `InvoiceLine.php`
- تعديل: `app/Tenant/Sales/Services/Issuer.php` — `issue()` (السطر 98): داخل معاملة الإصدار نفسها، بعد تجميد الأسطر، لكل سطر صنفه مخزني: `Stock::issue(..., sourceType: 'invoice_line', sourceId: <id السطر>, variantId: <variant_id السطر>)`
- تعديل: `app/Tenant/Sales/Services/InvoiceValidator.php` — ملاحظة مانعة: سطر مخزني بلا موقع
- تعديل: `app/Tenant/Sales/Services/Corrections.php` — الفاتورة المصحِّحة التي تغيّر كمية سطر مخزني تُرفض
- لا تعديل على `deptrac.yaml`: `Sales → Inventory` مسموح أصلًا (PF-252)

**القيد** (قائم في `StockIssuedRule`، ولا يتغيّر): مدين تكلفة المبيعات / دائن المخزون، بالتكلفة المجمّدة على الحركة.

**المعايير والفحوص:**
- [ ] `issuing an invoice issues its stocked lines` — حركة `issue` بـ `source_type = 'invoice_line'` وكمية سالبة ومتغيّر السطر
- [ ] `a service line moves nothing`
- [ ] `a refused sale leaves no gap in the numbering` — رصيد 1 وفاتورة بـ 2 ← رفض، والفاتورة التالية تأخذ الرقم السابق + 1 بالضبط **(محور المراجعة 1)**
- [ ] `an invoice and its movement are one transaction` — فشل مُحقَن بعد الحركة: لا فاتورة صادرة ولا حركة
- [ ] `a stocked line with no location is a blocking finding` — يُرفض قبل حجز الرقم
- [ ] `a negative stocked line is refused on an invoice` — المرتجع طريقه `SalesReturns`، لا سطر سالب
- [ ] `a correction that changes a stocked quantity is refused` — الرسالة تقول: مرتجع ثم فاتورة جديدة
- [ ] `a credit note for an amount moves no stock` — الإشعار الدائن المالي لا يحرّك بضاعة، والمرتجع الفعلي هو الذي يحرّكها
- [ ] `ledger:verify` ينجح بعد كل فحص يُرحّل

### R4 · الأسعار الشاملة للضريبة
`Tax` + `Sales` · **5 نقاط** · يعتمد على: R3 · القرار 1 · الفحوص: `php bin/verify tax`، `php bin/verify sales`

بيع التجزئة يكتب على الرف السعر **شاملًا** الضريبة، والمحرك اليوم يحسب الضريبة فوق الصافي فقط. بدون هذه المهمة، الرف يقول 100 والفاتورة تقول 115.

**الملفات:**
- إنشاء: هجرة — `price_lists.prices_include_tax boolean default false`، و`invoices.prices_include_tax boolean default false` (يُجمَّد على المستند عند الإصدار)
- تعديل: `app/Tenant/Tax/Services/TaxCalculator.php` — طريق ثانٍ للأسعار الشاملة
- تعديل: `app/Tenant/Sales/Services/Issuer.php` — يمرّر العلامة ويجمّدها

**القاعدة** (تُكتب في `docs/domain/tax-engine.md` قبل الكود):
1. اجمع **إجمالي** كل مجموعة نسبة (فئة + نسبة) من أسعار الأسطر الشاملة.
2. ضريبة المجموعة = `round_half_up(إجمالي المجموعة × النسبة ÷ (1 + النسبة), 4)` — **التقريب مرة واحدة لكل مجموعة**، كما في README.
3. صافي المجموعة = إجمالي المجموعة − ضريبتها.
4. وزّع صافي المجموعة على أسطرها بنسبة إجماليّاتها، وما يبقى من التقريب يذهب إلى السطر صاحب أكبر كسر متبقٍ.
5. النتيجة: **مجموع ما يدفعه الزبون يساوي مجموع أسعار الرف بالضبط**، والتقسيم لكل نسبة يبقى صحيحًا وفق BR-CO-14.

**المعايير والفحوص:**
- [ ] `a shelf price is what the customer pays` — ثلاثة أسطر شاملة بـ 99.99 و49.95 و10.00 بنسبة 16%: الإجمالي 159.94 بالضبط
- [ ] `inclusive tax is rounded once per rate group`
- [ ] `the net of the lines adds up to the net of the group`
- [ ] `the flag is frozen on the issued document` — تغيير علامة قائمة الأسعار بعد الإصدار لا يغيّر الفاتورة
- [ ] السلال الذهبية لهذه الحالات تُحفظ في `tests/Fixtures/pos/baskets/` لتستخدمها R13 وPF-264

### R5 · الاستلام يضيف المخزون، وفاتورة المورّد تصفّي «بضاعة مستلمة لم تُفوتر»
`Purchasing` · **5 نقاط** · يعتمد على: R2 · القرار 2 · الفحوص: `php bin/verify stock`، `php bin/verify purchasing`

**الملفات:**
- تعديل: `deptrac.yaml` — `Purchasing` يعتمد على `Inventory`، مع تعليق بالسبب نفسه المكتوب لـ `Sales`: الاستلام هو الحدث والحركة نتيجته، ولا تعرف `Inventory` شيئًا عن أوامر الشراء
- إنشاء: هجرة — `purchase_order_lines.variant_id`، و`goods_receipts.location_id`
- إنشاء: حالة جديدة في `ControlRole`: `PurchasePriceVariance = 'purchase_price_variance'`، وإضافتها إلى قوالب دليل الحسابات في `database/charts/`
- تعديل: `app/Tenant/Purchasing/Services/GoodsReceipts.php` — `receive()` (السطر 78) يأخذ `?string $locationId = null`، ولكل سطر مخزني: `Stock::receive(...)` بالكمية **المقبولة**، وبسعر أمر الشراء محوّلًا إلى العملة الوظيفية بسعر صرف يوم الاستلام، و`sourceType: 'goods_receipt_line'`
- تعديل: `app/Tenant/Purchasing/Posting/Rules/GoodsReceivedRule.php` — يستثني الأسطر المخزنية، لأن `StockReceivedRule` يرحّلها
- تعديل: `app/Tenant/Purchasing/Services/ThreeWayMatch.php` — `releaseAccruals()` لا يعكس قيد استلام مخزني (ليس استحقاقًا، بل بضاعة دخلت المخزن)
- تعديل: `app/Tenant/Purchasing/Posting/Rules/BillPostedRule.php` و`Services/Bills.php` — سطر الفاتورة المقابل لسطر أمر شراء مخزني يُرحَّل كما يلي

**القيود:**

| الحدث | مدين | دائن |
|---|---|---|
| الاستلام (قائم: `StockReceivedRule`) | المخزون — الكمية المقبولة × تكلفة الاستلام | بضاعة مستلمة لم تُفوتر |
| فاتورة المورّد | بضاعة مستلمة لم تُفوتر — الكمية المفوترة × تكلفة الاستلام | ذمم الموردين — الإجمالي |
| | ضريبة المدخلات — القابلة للاسترداد | |
| | فروقات أسعار الشراء — صافي الفاتورة − تكلفة الاستلام (مدين إن زاد، دائن إن نقص) | |

**المعايير والفحوص:**
- [ ] `a goods receipt receives its stocked lines`
- [ ] `rejected goods do not enter stock` — 10 مستلمة و2 مرفوضة ← 8 في المخزن
- [ ] `a stocked receipt credits GRNI once` — مجموع الدائن في «بضاعة مستلمة لم تُفوتر» للاستلام = قيمة الاستلام، لا ضعفها
- [ ] `a bill clears GRNI exactly` — بعد الفاتورة، رصيد الحساب لذلك السطر صفر
- [ ] `a price difference lands in purchase price variance` — استلام 5 بـ 10 وفاتورة 5 بـ 11 ← الفرق 5.0000 مدين في فروقات أسعار الشراء **(محور المراجعة 5)**
- [ ] `a foreign-currency receipt is costed at the receipt date's rate`
- [ ] فحص الربط القائم `inventoryReconciles` (المخزون = قيمة الحركات) ينجح بعد كل ما سبق

### R6 · التحويل بين المخازن
`Inventory` · **5 نقاط** · يعتمد على: R2 · الفحوص: `php bin/verify stock`

**الملفات:**
- إنشاء: هجرة —
  - `stock_locations.kind` بالقيم `warehouse | store | transit | quarantine` (الافتراضي `warehouse`)، وفهرس فريد جزئي: موقع `transit` واحد لكل كيان، يُنشأ تلقائيًا ولا يكون قابلًا للبيع
  - `stock_transfers` (`number`، `from_location_id`، `to_location_id`، `state` بالقيم `draft | dispatched | received | cancelled`، ومن أرسل ومتى، ومن استلم ومتى)
  - `stock_transfer_lines` (`item_id`، `variant_id`، `batch_id`، `quantity_sent`، `quantity_received`، `unit_cost`)
- إنشاء: `app/Tenant/Inventory/Services/Transfers.php`
- تعديل: `app/Tenant/Inventory/Services/Stock.php` — `transfer()`
- إذن جديد: `stock.transfer`، وقاعدة فصل مهام: من يستلم ليس من أرسل

**الواجهات:**
```php
Stock::transfer(string $entityId, string $itemId, ?string $variantId, string $fromLocationId, string $toLocationId,
                string $quantity, Carbon $on, string $userId, string $sourceType, string $sourceId): array   // ['out' => …, 'in' => …]
    // حركتا transfer_out وtransfer_in في معاملة واحدة، بتكلفة المصدر المسجّلة

Transfers::draft(string $entityId, string $fromLocationId, string $toLocationId, array $lines, string $userId): string
    // $lines: list<array{item_id: string, variant_id?: string, quantity: string}>
Transfers::dispatch(string $transferId, Carbon $on, string $userId): void         // من المصدر إلى «في الطريق»
Transfers::receive(string $transferId, array $received, Carbon $on, string $userId, ?string $shortReason = null): void
    // من «في الطريق» إلى الوجهة. الناقص تسوية على موقع «في الطريق» بسببها (10 أحرف على الأقل)
Transfers::inTransit(string $entityId): list<array>
```

**القيد:** لا شيء. نقل بضاعة بين مخزنين في الكيان نفسه ليس حدثًا ماليًا، وقيمة المخزون الكلية لا تتغيّر. الناقص عند الاستلام يُرحَّل عبر قاعدة التسوية القائمة.

**المعايير والفحوص:**
- [ ] `a transfer moves quantity and value, not cost` — قيمة المخزون الكلية قبل وبعد متساوية، ومتوسط الوجهة يُعاد حسابه
- [ ] `goods in transit are not sellable` — الصرف من موقع «في الطريق» يُرفض
- [ ] `a transfer posts nothing to the ledger` — عدد القيود لا يتغيّر، والربط بين الحساب والحركات ينجح
- [ ] `a short receipt is an adjustment with its reason`
- [ ] `the receiver is not the dispatcher`
- [ ] `a transfer to the same location is refused`

---

## المرحلة 3 — شاشات الإدخال

**شرط البدء:** إغلاق PF-212 (نظام النماذج) وPF-213 (تغطية الحالات) من الموجة 1. كل شاشة هنا تأخذ منهما مجانًا التسميات وملخّص الأخطاء وحالات الفراغ والتحميل والخطأ.

**اصطلاح Livewire** (لا يوجد مكوّن واحد في المستودع بعد، وهذا أوّلها):
- الصنف في `app/Tenant/<Module>/Http/Livewire/<Name>.php`، والعرض في `resources/views/livewire/<module>/<name>.blade.php`
- التسجيل بـ `Livewire::component()` في مزوّد خدمة الوحدة
- الاختبار بـ `Livewire::test()` في `tests/Feature/<Module>/<Name>Test.php`
- كل حفظ يمرّ عبر خدمة الوحدة، **ولا كتابة مباشرة إلى نموذج من المكوّن**
- كل شاشة تُشغَّل مرة كاملة بلوحة المفاتيح وحدها (تعريف الإنجاز)

### R7 · محرر الصنف وشبكة المقاس × اللون
`Catalog` · **8 نقاط** · يعتمد على: R1، PF-212، PF-213

**الملفات:**
- إنشاء: `app/Tenant/Catalog/Http/Livewire/ItemEditor.php` و`AxisManager.php`
- إنشاء: `resources/views/livewire/catalog/item-editor.blade.php` و`axis-manager.blade.php`
- تعديل: `routes/web.php` — `GET /masters/items/create`، `GET /masters/items/{item}/edit`، `GET /masters/axes`
- تعديل: `app/Support/Ui/Navigation.php` و`resources/views/app/masters/items/index.blade.php` — زر «صنف جديد»
- اختبار: `tests/Feature/Catalog/ItemEditorTest.php`

**السلوك:**
- حقول الصنف: الرمز، الاسم بالعربية والإنجليزية، التصنيف، رمز الضريبة، الوحدة، السعر الأساسي، مخزني أم لا، طريقة التكلفة، السماح بالسالب مع السبب
- قسم المتغيرات: اختيار محورين (مثلًا المقاس والألوان) ← شبكة صفوفها قيم المحور الأول وأعمدتها قيم الثاني، ومربع اختيار في كل خانة. المعلَّم فقط يصبح متغيرًا عبر `Variants::createMany`
- جدول المتغيرات: الرمز، الباركود (مع زر «توليد» من R8)، السعر الخاص، نشط
- إلغاء علامة خانة لمتغيّر له حركات يُرفض برسالة تسمّي عدد الحركات، ويُعرض «إيقاف» بديلًا

**الاختبارات:**
- [ ] `test_ticking_two_of_six_cells_creates_two_variants`
- [ ] `test_a_duplicate_barcode_is_reported_on_its_field_naming_the_holder`
- [ ] `test_unticking_a_variant_with_movements_is_refused`
- [ ] `test_an_item_without_permission_is_forbidden` — بلا `item.manage` ← 403

### R8 · الباركود وطباعة الملصقات
`Catalog` · **3 نقاط** · يعتمد على: R7

**الملفات:**
- إنشاء: `app/Tenant/Catalog/Services/Barcodes.php`
- إنشاء: `app/Support/Barcodes/Ean13Svg.php` و`Code128Svg.php` — بلا مكتبة خارجية، لأن كلًّا منهما بضع عشرات من الأسطر ومختبَر بمثال GS1
- إنشاء: `resources/views/app/masters/items/labels.blade.php` مع نمط طباعة لملصق حراري 50×30 مم ولورقة A4 بشبكة 3×8
- تعديل: `routes/web.php` — `GET /masters/items/labels`

**الواجهات:**
```php
Barcodes::nextInternal(string $entityId): string     // EAN-13 داخلي: يبدأ بـ 2، وعدّاد من سلسلة ترقيم، ورقم تحقق
Barcodes::isValidEan13(string $code): bool
Ean13Svg::render(string $ean13, int $moduleWidth = 2, int $height = 60): string
Code128Svg::render(string $value, int $moduleWidth = 2, int $height = 50): string    // لرقم الإيصال (R15)
```

**الملصق:** اسم الصنف، قيم المحاور («أحمر / L»)، السعر في يوم الطباعة من `Variants::priceOn`، والباركود بأرقامه.

**الفحوص** (`php bin/verify catalog`):
- [ ] `a generated barcode carries a valid check digit` — `isValidEan13('4006381333931') === true`، وكل ناتج من `nextInternal` 13 رقمًا يبدأ بـ 2 وصالح
- [ ] `an EAN-13 is 95 modules with its guards` — حارسا البداية والنهاية `101`، والحارس الأوسط `01010`
- [ ] `two generated barcodes are never the same` — عشرون استدعاءً متتاليًا ← عشرون قيمة مختلفة

### R9 · شاشات المخازن والأرصدة والتحويل والجرد
`Inventory` · **8 نقاط** · يعتمد على: R2، R6، PF-212، PF-213

**الملفات:**
- إنشاء: `app/Tenant/Inventory/Http/Livewire/` — `Locations.php`، `OnHand.php`، `Movements.php`، `TransferEditor.php`، `CountSheet.php`، `Adjustment.php`
- إنشاء: العروض المقابلة في `resources/views/livewire/inventory/`
- تعديل: `routes/web.php` (البادئة `/inventory`)، و`Navigation.php` (قسم «المخزون» جديد)، و`PermissionSeeder.php` (`stock.view`، `stock.manage`، `stock.adjust`، `stock.count`، `stock.view_cost`)
- اختبارات: `tests/Feature/Inventory/*Test.php`

**الشاشات:**
- **المخازن:** إضافة وتعديل (الرمز، الاسمان، النوع، قابل للبيع، نشط)
- **الأرصدة:** ابحث عن صنف ← مصفوفة المقاس × اللون لكل مخزن، والقيمة لا تظهر إلا لمن يملك `stock.view_cost`
- **الحركات:** سجل الحركات لكل صنف أو متغيّر أو مخزن، ومن كل حركة رابط إلى مستندها
- **التحويل:** مسودة بمسح الباركود ← إرسال ← استلام بإدخال الكميات المستلمة
- **الجرد:** فتح جرد لمخزن ← ورقة عمياء (مسح الباركود يزيد العدد واحدًا) ← إنهاء ← اعتماد ← ترحيل ← تقرير الفروقات
- **التسوية:** بسبب إجباري

**الاختبارات:**
- [ ] `test_the_on_hand_matrix_shows_sizes_by_colours`
- [ ] `test_cost_is_hidden_without_permission`
- [ ] `test_scanning_on_the_count_sheet_adds_one`
- [ ] `test_the_count_sheet_never_shows_the_expected_figure` — العرض المولَّد لا يحتوي الكمية المتوقعة قبل الإنهاء

### R10 · شاشة العميل والمورّد
`Parties` · **3 نقاط** · يعتمد على: PF-212

**الملفات:**
- إنشاء: `app/Tenant/Parties/Http/Livewire/PartyEditor.php` والعرض
- تعديل: `routes/web.php` — `GET /masters/parties/create`، `GET /masters/parties/{party}/edit`

**السلوك:** الاسم، النوع (عميل، مورّد، كلاهما)، الهاتف بصيغة دولية، العنوان، التسجيل الضريبي، حد الائتمان. البيانات البنكية تمرّ بمسار التحقق القائم (`BankDetailRegistry`) ولا تُكتب مباشرة.

**الاختبارات:**
- [ ] `test_a_customer_is_created_with_arabic_and_latin_digits_in_the_phone` — الأرقام العربية تُطبَّع
- [ ] `test_bank_details_go_through_verification`

### R11 · أمر الشراء والاستلام وفاتورة المورّد
`Purchasing` · **8 نقاط** · يعتمد على: R5، PF-212، PF-213

**الملفات:**
- إنشاء: `app/Tenant/Purchasing/Http/Livewire/` — `PurchaseOrderEditor.php`، `GoodsReceiptForm.php`، `BillFromReceipt.php`
- تعديل: `routes/web.php`، و`Navigation.php` (رابطا «أوامر الشراء» و«الاستلام» يصبح لهما مسار بدل `null`)

**السلوك:**
- أمر الشراء: المورّد، المخزن المستهدف، وأسطر يُدخَل فيها **شبكة كميات بالمقاس × اللون** لكل صنف، فطلب الملابس هكذا يُكتب
- الاستلام: مقابل أمر الشراء، مع المخزن، والمقبول والمرفوض وسبب الرفض
- فاتورة المورّد: من الاستلام، مع المطابقة الثلاثية القائمة وعرض فرق السعر قبل الترحيل

**الاختبارات:**
- [ ] `test_a_size_by_colour_grid_becomes_one_line_per_variant`
- [ ] `test_receiving_into_a_location_moves_stock` — شاشة تستدعي R5، والحركة موجودة
- [ ] `test_the_price_difference_is_shown_before_posting`

### PF-209 · باني الفاتورة (مهمة قائمة) — إضافات المخزون
`Sales` · **8 نقاط** (تقديرها في ملفها) · يعتمد على: R3، R4، PF-206

تُضاف إلى معاييرها في `phase-11-design-system.md`:
- [ ] اختيار مخزن البيع، والافتراضي مخزن المستخدم
- [ ] إضافة سطر بمسح الباركود ← المتغيّر مباشرة
- [ ] الكمية المتوفرة ظاهرة لكل سطر، والنقص ملاحظة مانعة في لوحة التحقق قبل الإصدار، لا خطأ بعده
- [ ] علامة «الأسعار شاملة الضريبة» من R4، ظاهرة على المستند

---

## المرحلة 4 — نقطة البيع

### R12 · الصناديق والورديات والأدراج (منطق الخادم)
`Pos` (وحدة جديدة) · **8 نقاط** · يعتمد على: R3، R4 · القرار 5 · الفحوص: `php bin/verify pos` (جناح جديد)

**الملفات:**
- إنشاء: الوحدة `app/Tenant/Pos/` — `Models/`، `Services/`، `Posting/Rules/`، `Exceptions/`
- إنشاء: `app/Providers/PosServiceProvider.php`
- تعديل: `deptrac.yaml` — الطبقة `Pos` تعتمد على `Support`، `Tenancy`، `Ledger`، `Parties`، `Catalog`، `Tax`، `Sales`، `Inventory`، `Settlement`، `Sync`، ولا يعتمد عليها إلا `Reporting` (تُضاف إلى قائمته)
- إنشاء: هجرة —
  - `pos_registers` (`location_id` قابل للبيع، `code`، `name`، `device_id`، `cash_account_id` حساب الدرج، `number_series_key`، `active`)
  - `pos_shifts` (`register_id`، `number`، `opened_by`، `opened_at`، `opening_float`، `state`، `closed_by`، `closed_at`، `journal_entry_id`)، مع فهرس فريد جزئي: **وردية مفتوحة واحدة لكل صندوق**
  - `pos_shift_counts` (`shift_id`، `method`، `expected`، `counted`، `variance`)
  - `pos_cash_movements` (`shift_id`، `kind` بالقيم `pay_in | pay_out | drop`، `amount`، `reason`، `recorded_by`)
- إنشاء: حالات `ControlRole`: `CashOverShort`، `CardClearing`، `StoreCredit`، وقوالب دليل الحسابات
- إنشاء: أذونات `pos.sell`، `pos.shift`، `pos.override`، `pos.refund`، `pos.cash_movement`، وأدوار: **كاشير**، **مدير فرع**، **أمين مخزن**
- إنشاء: `tests/Support/PosRules.php`، وتسجيل `pos` في `bin/verify`

**الواجهات:**
```php
Shifts::open(string $registerId, string $openingFloat, string $userId, Carbon $at): PosShift
Shifts::expected(string $shiftId): array           // array<method, Money>: العهدة + النقد الداخل − الخارج
Shifts::close(string $shiftId, array $counted, string $userId, Carbon $at): array   // array<method, string>
CashMovements::record(string $shiftId, string $kind, string $amount, string $reason, string $userId): string
PosSales::record(string $registerId, string $shiftId, string $clientId, int $sequence,
                 array $basket, array $tenders, string $userId, Carbon $occurredAt): array
    // فاتورة مبسّطة (Simplified::mark) برقم من سلسلة الصندوق،
    // تُصدَر عبر Issuer (فتصرف المخزون بـ R3)، وكل وسيلة دفع تُسجَّل عبر Payments::record وتُخصَّص للفاتورة.
    // كل ذلك في معاملة واحدة.
Issuer::issue(..., ?int $expectedSequence = null)
    // يرفض إذا لم يكن الرقم المحجوز هو المتوقع (ADR-0012)
```

**القيود:**

| الحدث | مدين | دائن |
|---|---|---|
| البيع (قائم: إصدار الفاتورة) | الذمم المدينة | الإيراد، ضريبة المخرجات |
| دفع نقدي | حساب درج الصندوق | الذمم المدينة |
| دفع بالبطاقة | وسيط البطاقات `card_clearing` | الذمم المدينة |
| عجز عند الإغلاق | فروقات الصندوق `cash_over_short` | حساب الدرج |
| زيادة عند الإغلاق | حساب الدرج | فروقات الصندوق |
| توريد النقد للبنك (`drop`) | مدفوعات في الطريق | حساب الدرج |

**المعايير والفحوص:**
- [ ] `one open shift per register` — من قاعدة البيانات
- [ ] `a sale on a closed shift is refused`
- [ ] `the expected drawer is the float plus the cash that moved`
- [ ] `the variance is per payment method`
- [ ] `a shortage posts to cash over and short` — بمدينه ودائنه
- [ ] `a mixed payment posts each method to its own account` — نقد 50 وبطاقة 70 لإيصال بـ 120: الدرج مدين 50، والوسيط مدين 70، ورصيد الإيصال في الذمم صفر
- [ ] `a pos sale is one transaction` — فشل مُحقَن بعد صرف المخزون: لا فاتورة ولا حركة ولا دفعة
- [ ] `a register number out of sequence is a conflict, not a renumber`
- [ ] `a material variance needs a second name` — على نمط الجرد (PF-253)

### R13 · واجهة API للكاشير
`Pos` · **5 نقاط** · يعتمد على: R12، PF-255 · الفحوص: `php bin/verify pos`، `php bin/verify api`

**الملفات:**
- إنشاء: `app/Tenant/Pos/Http/Middleware/AuthenticateDevice.php` — يستدعي `Devices::resolve()` القائمة ويثبّت المستأجر والكيان والصندوق
- إنشاء: `app/Tenant/Pos/Http/Controllers/Api/` — `CatalogueController.php`، `StockController.php`، `SubmissionController.php`
- تعديل: `routes/api.php`، ووثيقة OpenAPI، و`docs/api/`
- إنشاء: `tests/Fixtures/pos/baskets/*.json` — سلال ذهبية: أسعار شاملة وغير شاملة، نسب متعددة، خصومات، كميات كسرية للأمتار، ومبالغ تحتاج تقريبًا

**النقاط:**
```
GET  /api/v1/pos/catalogue?since={cursor}
     الأصناف، المتغيرات، الباركود، الأسعار اليوم وتغييرات الأيام السبعة القادمة، رموز الضريبة ونسبها،
     إعداد الصندوق، والعملاء ذوو الحساب. تغييرات فقط منذ آخر مؤشّر
GET  /api/v1/pos/stock?location={id}
     الرصيد لكل متغيّر — للعرض فقط، لا للمنع
POST /api/v1/pos/submissions
     دفعة من {client_id, kind, sequence?, occurred_at, payload}
     kind: pos.sale | pos.refund | pos.exchange | pos.shift_open | pos.shift_close | pos.cash_movement
     كل عنصر ← Submissions::submit() مع دالة التطبيق من خدمات Pos
     الرد لكل عنصر: accepted | duplicate | conflict
```

**المعايير والفحوص:**
- [ ] `a submission sent twice creates one sale`
- [ ] `two tills selling the last large surface a conflict` — جهازان بلا شبكة يبيعان آخر L: الأول مقبول، والثاني تعارض فيه «ما قاله الجهاز» و«ما يقوله الخادم». قرار المدير: تسجيله بمخزون سالب مع سبب (البضاعة خرجت مع الزبون، والجرد يصحّح) **(محور المراجعة 3)**
- [ ] `the server's totals equal the till's for every golden basket` — كل سلة في المجلد يحسبها `TaxCalculator` فيطابق القيمة المكتوبة فيها **(محور المراجعة 2، الوجه الأول)**
- [ ] `a revoked device cannot submit`
- [ ] `a device of one tenant cannot reach another`
- [ ] `a sale is ordered by the server's receipt, not the device's clock` — جهاز بتاريخ خاطئ لا يرحّل في فترة مغلقة

### PF-264 · شاشة الكاشير (مهمة قائمة، يُعاد تقديرها إلى 13)
`Pos` · **13 نقطة** · يعتمد على: R13 · القرار 4 · الاختبار: `node --test resources/js/pos/`

معاييرها الخمسة في `phase-15-verticals.md` تبقى كما هي، وتُضاف إليها المعايير أدناه.

**الملفات:**
- إنشاء: `resources/js/pos/`:
  - `app.js` — مخزن Alpine
  - `db.js` — IndexedDB: الكتالوج، السلة، صندوق الصادر، الإيصالات المحفوظة
  - `money.js` و`tax.js` — الحساب بـ `BigInt` على مقياس ثابت (السعر 8 منازل، الكمية 6، النسبة 12)، والتقريب `HALF_UP` بعيدًا عن الصفر. **ممنوع `Number` و`parseFloat` و`toFixed` في أي مبلغ**
  - `outbox.js` — إرسال بالخلفية مع تراجع تدريجي، والسجل لا يُحذف إلا بعد إقرار الخادم بـ `client_id`
  - `receipt.js` و`sw.js`
- إنشاء: `resources/views/pos/till.blade.php`، و`public/pos-manifest.json`
- تعديل: `routes/web.php` — `GET /pos/{register}` (يحتاج `pos.sell`)، و`vite.config.js`
- تعديل: `ArchitectureRules` — فحص يفشل إن ظهر `parseFloat` أو `toFixed` أو `Number(` في `resources/js/pos/money.js` و`tax.js`

**السلوك:**
- قارئ الباركود يكتب الأرقام ثم Enter ← المتغيّر من الكتالوج المحلي ← سطر جديد أو زيادة الكمية
- المفاتيح: F2 بحث، F4 كمية، F8 دفع، F9 تعليق السلة، Esc إلغاء، وكل شيء يعمل بلا فأرة
- نافذة الدفع: وسائل متعددة. الباقي يُحسب للنقد فقط. لا إتمام قبل أن يغطي المدفوع الإجمالي (أو يغطي «على الحساب» الباقي لعميل من القائمة المحمّلة)
- الإيصال على ورق 80 مم: رقم سلسلة الصندوق، الأسطر مع المقاس واللون، تقسيم الضريبة لكل نسبة، الدفع لكل وسيلة، الباقي، ورقم الإيصال بباركود Code128. ورمز QR إن تطلّبه الملف الضريبي (القرار 1)
- إعادة الطباعة من الإيصال المحفوظ في IndexedDB، لا من أسعار اليوم

**الاختبارات:**
- [ ] `every golden basket matches` — نفس ملفات `tests/Fixtures/pos/baskets/` **(محور المراجعة 2، الوجه الثاني)**
- [ ] `a basket is rounded once per rate group`
- [ ] `an unknown barcode adds no line` — وكذلك باركود متغيّر موقوف، مع رسالة تسمّي السبب **(محور المراجعة 4)**
- [ ] `nothing in the sale path calls the network` — `fetch` مستبدل بدالة ترمي استثناءً، والبيع من المسح حتى الإيصال ينجح
- [ ] `the outbox keeps a sale until the server acknowledges its client id`
- [ ] `a reprint reads the stored sale, not today's prices`
- [ ] `the sequence is the register's next number and survives a reload`

### R14 · الوردية على الكاشير
`Pos` · **5 نقاط** · يعتمد على: PF-264

- [ ] فتح الوردية بعدّ العهدة (إدخال الفئات اختياري)
- [ ] تقرير X خلال الوردية (قراءة فقط)، وتقرير Z عند الإغلاق يُطبع
- [ ] الإغلاق عدّ أعمى لكل وسيلة: **المتوقع لا يظهر قبل إدخال المعدود** (`the expected cash is hidden until the count is entered`)
- [ ] الإيداع والسحب والتوريد بسبب إجباري
- [ ] فتح الوردية وإغلاقها يعملان بلا شبكة، كبنود في صندوق الصادر

### R15 · المرتجع والاستبدال على الكاشير
`Pos` · **5 نقاط** · يعتمد على: PF-264، PF-252

- [ ] مسح باركود الإيصال أو كتابة رقمه ← أسطره ← اختيار ما يُرجَع ← `SalesReturns`
- [ ] الاستبدال: أسطر مرتجعة وأسطر جديدة **في بند واحد ومعاملة واحدة**، والفرق يُدفع أو يُردّ (`an exchange is one transaction`)
- [ ] الردّ بوسيلة الدفع الأصلية افتراضيًا، والنقد محدود بما في الدرج، أو رصيد متجر (دائن في `store_credit`)
- [ ] إيصال من الصندوق نفسه يُرجَع بلا شبكة. إيصال من صندوق آخر يحتاج الخادم، والشاشة تقول ذلك (ADR-0012)
- [ ] استخدام رصيد المتجر وسيلةً للدفع يحتاج الخادم (`store credit as a tender needs the server`)
- [ ] `a refund cannot exceed what the receipt sold` — قائم في قاعدة البيانات منذ PF-252، ويُثبَت هنا من طريق الكاشير

### R16 · الخصومات والتجاوزات
`Pos` · **3 نقاط** · يعتمد على: PF-264

- [ ] خصم على السطر أو السلة، نسبةً أو مبلغًا
- [ ] حدّ الكاشير إعداد للكيان `pos.max_discount_percent` عبر `SettingsRegistry`. ما فوقه يحتاج رمز مدير (`a discount above the cashier's limit needs a manager`)
- [ ] رمز المدير مخزّن مُجزَّأً (bcrypt) في إعداد الصندوق، يُتحقق منه بلا شبكة، وكل استخدام يُسجَّل
- [ ] كل تجاوز يُسجَّل على البيع: من، ولماذا، وكم (`every override names who and why`)
- [ ] التخفيضات الموسمية **لا آلية جديدة لها:** تغيير سعر مجدول من PF-254 يأخذ أثره في موعده

---

## المرحلة 5 — تقارير المتجر

كل التقارير في وحدة `Reporting`، بشاشة Livewire، وتصدير CSV بـ `CsvExport` القائم (وXLSX حين تُغلق PF-197). الفحوص في `php bin/verify reporting`.

### R17 · تحليل المبيعات
**5 نقاط** · يعتمد على: R3، R12

**الواجهة:** `SalesAnalysis::run(SalesAnalysisQuery $query): SalesAnalysisResult`

- المرشّحات: الفترة، المخزن، الصندوق، الكاشير، التصنيف، الصنف، **قيمة محور** (مثلًا: اللون = أحمر)
- المقاييس: الكمية، الصافي، الضريبة، التكلفة (من الحركة المجمّدة)، الهامش ونسبته، المرتجعات
- التجميع: صنف، متغيّر، محور، مخزن، صندوق، كاشير، ساعة، يوم
- **منحنى المقاسات:** توزيع الوحدات المباعة على المقاسات لكل صنف — به يُطلب الموسم القادم

**الفحوص:**
- [ ] `the sales analysis ties to profit or loss` — الإيراد والتكلفة للفترة والكيان يساويان قائمة الأرباح والخسائر
- [ ] `returns reduce the item that was returned` — لا الصنف الذي بيع بعده

### R18 · تقارير المخزون
**5 نقاط** · يعتمد على: R2، R6

- مصفوفة الرصيد (مقاس × لون) لكل مخزن، بالقيمة
- **التقييم لكل مخزن**، ومجموعه يساوي حساب المخزون (`stock value by location sums to the control account`)
- البضاعة الراكدة: بلا صرف منذ N يومًا، وN معامل
- **نسبة التصريف** = المباع ÷ (المباع + الرصيد) للفترة، لكل صنف
- حدّ إعادة الطلب: جدول جديد `stock_levels` (المخزن، المتغيّر، الحد الأدنى، الحد الأعلى) ← قائمة اقتراحات تتحول إلى مسودة أمر شراء أو تحويل (`a suggestion becomes a draft, never an order`)

### R19 · تقارير الورديات والكاشيرية
**3 نقاط** · يعتمد على: R12، R14

- أرشيف تقارير Z، وملخّص يومي لكل فرع: المبيعات لكل وسيلة، المرتجعات، الخصومات، التجاوزات، الفروقات
- ترتيب الكاشيرية: المبيعات، متوسط السلة، القطع لكل سلة، الإلغاءات، التجاوزات
- [ ] `the Z report's totals equal the shift's postings`

---

## المرحلة 6 — التشغيل الفعلي

### R20 · استيراد الأصناف والأرصدة الافتتاحية
`Inventory` + `Catalog` · **5 نقاط** · يعتمد على: R1، R2

**الملفات:**
- إنشاء: `app/Tenant/Inventory/Import/OpeningStockImport.php`، و`app/Tenant/Catalog/Import/ItemImport.php` — على نمط `Ledger/Manual/JournalImport.php` و`ImportReport.php`
- إنشاء: قوالب CSV في `docs/operations/import-templates/`: الأصناف، والمتغيرات (رمز الصنف، قيم المحاور، الباركود، السعر الخاص)، والرصيد الافتتاحي (المخزن، الصنف، المتغيّر أو باركوده، الكمية، تكلفة الوحدة)
- إنشاء: نوع حركة `opening` (تعديل قيد `stock_movements_kind_is_known` وقيد الإشارة)، و`OpeningStockRule`

**القيد:** مدين المخزون / دائن الحساب المقابل الذي تستخدمه بقية الأرصدة الافتتاحية، ويُمرَّر معاملًا. **داخل نافذة الأرصدة الافتتاحية فقط** (`Ledger/Services/OpeningBalances.php`): قبل أي ترحيل آخر في الكيان، وإلا رفض يقول ذلك.

**الفحوص:**
- [ ] `an import with one bad row imports nothing and names the row`
- [ ] `the opening stock ties to the control account`
- [ ] `opening stock after the window is refused`

### R21 · مستأجر تجريبي لمتجر ملابس
**2 نقطة** · يعتمد على: كل ما سبق

**الملفات:** إنشاء `database/seeders/ClothingStoreDemoSeeder.php` على نمط `DemoTenantSeeder.php` بقواعده الثلاث: **كل شيء عبر الخدمات لا الجداول** (فالتجربة متّسقة بالبناء ويستطيع مراجع إعادة تنفيذها)، **أرقام وتواريخ ثابتة لا عشوائية**، **محلي فقط ومرة واحدة**.

**المحتوى:** المحوران (المقاس XS–XXL، واللون: أسود، أبيض، كحلي، أحمر، بيج)، و20 صنفًا بمتغيراتها وباركودها، ومخزن رئيسي وفرعان وموقع «في الطريق»، وصندوقان، والأدوار الثلاثة، ورصيد افتتاحي، وأسبوع مبيعات يغذّي التقارير.

- [ ] `the demo store passes ledger:verify` — بعد التوليد، `php artisan ledger:verify` ينجح

---

## التحقق الختامي

- [ ] `php bin/verify` كاملًا ينجح (ويشمل `arch` لاتجاهات الطبقات)، و`composer check` ينجح
- [ ] `node --test resources/js/pos/` ينجح
- [ ] جولة يدوية في المستأجر التجريبي: صنف جديد بـ 6 متغيرات ← طباعة ملصقاته ← أمر شراء ← استلام في المخزن ← تحويل إلى فرع ← فتح وردية ← بيع بالباركود **بلا شبكة** ← دفع مختلط ← إيصال ← عودة الشبكة والمزامنة ← مرتجع ← إغلاق الوردية بعجز ← تقرير Z ← تحليل المبيعات ← تقييم المخزون يساوي حسابه
- [ ] ما لم يُغطَّ يُكتب صراحةً في ملف المرحلة كمعيار مفتوح، مع المهمة التي ستأتي به
