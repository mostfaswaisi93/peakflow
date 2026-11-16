<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Http\Controllers;

use App\Tenant\Banking\Exceptions\ImportRefused;
use App\Tenant\Banking\Exceptions\StatementUnreadable;
use App\Tenant\Banking\Models\BankAccount;
use App\Tenant\Banking\Models\CsvImportTemplate;
use App\Tenant\Banking\Parsers\CsvDialect;
use App\Tenant\Banking\Parsers\CsvSniffer;
use App\Tenant\Banking\Parsers\CsvStatementReader;
use App\Tenant\Banking\Parsers\StatementParsers;
use App\Tenant\Banking\Services\StatementImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Uploading a statement, and mapping a CSV's columns - PF-154.
 *
 * ## Nothing here is applied without a preview
 *
 * A CSV's decimal separator is a guess that cannot be made safely from the
 * file alone: `1.234` is a thousand in Frankfurt and one in London. So the
 * upload step detects, the preview step shows the rows **as they will be
 * interpreted** beside the raw cells they came from, and only a second,
 * deliberate submission writes anything.
 *
 * The other formats need no mapping and go straight through - but they go
 * through the same preview, because "what will this do" is a reasonable
 * question about any file and the answer costs one parse.
 *
 * ## Why it is three plain forms and no JavaScript
 *
 * Native controls are keyboard-operable, announce themselves to a screen
 * reader and work before the script loads. A mapping built from drag-and-drop
 * cards is the version that looks modern in a screenshot and cannot be used
 * without a mouse - and the person doing a month's bank import is doing it
 * from a keyboard, at speed, having done it eleven times before.
 *
 * ## The file is held between the two steps, and let go afterwards
 *
 * It has to be: a file input cannot be re-populated, so asking for the file
 * again after the preview would mean uploading it twice. It is written to
 * the private disk under a key held in the session, and removed as soon as
 * the import succeeds or the person starts another one.
 */
final class StatementImportController
{
    private const SESSION_KEY = 'banking.import.pending';

    public function __construct(
        private readonly StatementImport $imports,
        private readonly StatementParsers $parsers,
        private readonly CsvSniffer $sniffer,
        private readonly CsvStatementReader $csv,
    ) {}

    /** The upload form, with any saved templates for this account. */
    public function create(BankAccount $account): View
    {
        $this->forget();

        return view('banking.import-upload', [
            'account' => $account,
            'formats' => array_keys((array) config('banking.formats', [])),
            'templates' => CsvImportTemplate::query()
                ->forAccount((string) $account->getKey())
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Read the file and show what importing it would do.
     *
     * @no-permission-required: nothing is written. This parses an uploaded
     * file and renders what it contains, and the `bank.import` permission is
     * checked on `store()`, which is the step that writes. Requiring it here
     * as well would be defensible, and would also mean somebody who may not
     * import cannot see why a file is being refused - which is the question
     * they are usually asked to answer.
     */
    public function preview(Request $request, BankAccount $account): View|RedirectResponse
    {
        $validated = $request->validate([
            'statement' => ['required', 'file', 'max:20480'],
            'format' => ['nullable', 'string', 'max:16'],
            'template' => ['nullable', 'string', 'size:26'],
        ]);

        $file = $request->file('statement');
        $content = (string) file_get_contents($file->getRealPath());
        $name = (string) $file->getClientOriginalName();

        $key = $this->remember($content, $name);
        $format = ($validated['format'] ?? '') === '' ? null : (string) $validated['format'];

        if ($format !== 'csv') {
            return $this->previewOfAStatement($account, $content, $name, $format, $key);
        }

        $template = $validated['template'] ?? null;

        return $this->previewOfACsv(
            $account,
            $content,
            $name,
            $key,
            $template === null ? null : CsvImportTemplate::query()->find($template),
        );
    }

    /**
     * Re-read the CSV with whatever the person corrected.
     *
     * The same screen as `preview()`, which is the point: a correction shows
     * its effect in the rows below it rather than being accepted silently
     * and discovered after the import.
     *
     * @no-permission-required: as `preview()` - nothing is written.
     */
    public function remap(Request $request, BankAccount $account): View|RedirectResponse
    {
        $held = $this->held();

        if ($held === null) {
            return redirect()
                ->route('banking.import.create', $account)
                ->with('import_expired', true);
        }

        return $this->previewOfACsv(
            $account,
            $held['content'],
            $held['name'],
            (string) $held['key'],
            null,
            $this->dialectFrom($request),
            $this->mapFrom($request),
        );
    }

    /**
     * Import, having been shown what it would do.
     */
    public function store(Request $request, BankAccount $account): RedirectResponse
    {
        $this->authorizeImport($request);

        $held = $this->held();

        if ($held === null) {
            return redirect()
                ->route('banking.import.create', $account)
                ->with('import_expired', true);
        }

        $format = (string) $request->input('format', '');

        try {
            $report = $format === 'csv'
                ? $this->imports->importParsed(
                    account: $account,
                    parsed: $this->csv->read(
                        $held['content'],
                        $this->dialectFrom($request),
                        $this->mapFrom($request),
                        (string) $account->currency,
                    ),
                    content: $held['content'],
                    fileName: $held['name'],
                    userId: (string) $request->user()?->getAuthIdentifier(),
                    checked: false,
                )
                : $this->imports->importFile(
                    account: $account,
                    content: $held['content'],
                    format: $format === '' ? null : $format,
                    fileName: $held['name'],
                    userId: (string) $request->user()?->getAuthIdentifier(),
                );
        } catch (StatementUnreadable|ImportRefused $e) {
            /*
             | Back to the preview with the refusal, not to a blank form.
             |
             | The refusals here are long on purpose - they name the figures
             | and say what to do - and a person sent back to an empty upload
             | box has to re-upload before they can read it again.
             */
            return back()->withInput()->withErrors(['statement' => $e->getMessage()]);
        }

        if ($format === 'csv' && $request->filled('save_template_as')) {
            $this->saveTemplate($request, $account);
        }

        $this->forget();

        return redirect()
            ->route('banking.import.create', $account)
            ->with('import_report', $report);
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    private function authorizeImport(Request $request): void
    {
        $user = $request->user();

        if ($user === null || $user->cannot('bank.import')) {
            abort(403, __('ليس لديك صلاحية استيراد كشوف الحساب.'));
        }
    }

    private function previewOfAStatement(
        BankAccount $account,
        string $content,
        string $name,
        ?string $format,
        string $key,
    ): View|RedirectResponse {
        try {
            $statements = $this->parsers->parse($content, $format);
        } catch (StatementUnreadable $e) {
            return back()->withErrors(['statement' => $e->getMessage()]);
        }

        return view('banking.import-preview', [
            'account' => $account,
            'fileName' => $name,
            'key' => $key,
            'format' => $format ?? ($statements[0]->sourceFormat ?? ''),
            'statements' => $statements,
            'isCsv' => false,
        ]);
    }

    /**
     * @param  array<string, int>|null  $map
     */
    private function previewOfACsv(
        BankAccount $account,
        string $content,
        string $name,
        string $key,
        ?CsvImportTemplate $template = null,
        ?CsvDialect $dialect = null,
        ?array $map = null,
    ): View|RedirectResponse {
        try {
            $sniffed = $this->sniffer->sniff($content);
        } catch (StatementUnreadable $e) {
            return back()->withErrors(['statement' => $e->getMessage()]);
        }

        /*
         | The person's corrections win, then a saved template, then the
         | detector.
         |
         | In that order because each is more deliberate than the last: a
         | template was confirmed against a preview once already, and the
         | detector has never been confirmed at all.
         */
        $dialect ??= $template?->dialect() ?? $sniffed['dialect'];
        $map ??= $template?->columnMap() ?? $this->guessTheMap($content, $dialect);

        $preview = $this->csv->preview($content, $dialect, $map, (string) $account->currency);

        return view('banking.import-preview', [
            'account' => $account,
            'fileName' => $name,
            'key' => $key,
            'format' => 'csv',
            'isCsv' => true,
            'dialect' => $dialect,
            'evidence' => $dialect->explain($sniffed['evidence']),
            'warnings' => $sniffed['warnings'],
            'map' => $map,
            'preview' => $preview,
            'fields' => $this->mappableFields(),
            'template' => $template,
        ]);
    }

    /**
     * A first guess at which column is what, from the header names.
     *
     * Only a guess, and shown as one: every select is editable and the
     * preview below it says what the guess produces. A header called
     * "Amount" is usually the amount and occasionally the running balance,
     * which is why nothing here is applied without being looked at.
     *
     * @return array<string, int>
     */
    private function guessTheMap(string $content, CsvDialect $dialect): array
    {
        $first = strtok($this->sniffer->toUtf8($content, $dialect->encoding), "\r\n");

        if ($first === false || ! $dialect->hasHeader) {
            return [];
        }

        $header = array_map(
            static fn (string $cell): string => strtolower(trim($cell)),
            str_getcsv($first, $dialect->delimiter, $dialect->enclosure, '\\'),
        );

        $hints = [
            'booking_date' => ['booking', 'buchung', 'date', 'datum', 'posted', 'التاريخ', 'تاريخ'],
            'value_date' => ['value', 'wert', 'valuta'],
            'amount' => ['amount', 'betrag', 'المبلغ'],
            'debit' => ['debit', 'soll', 'withdrawal', 'paid out', 'مدين'],
            'credit' => ['credit', 'haben', 'deposit', 'paid in', 'دائن'],
            'remittance' => ['description', 'verwendungszweck', 'reference', 'memo', 'narrative', 'البيان'],
            'counterparty_name' => ['name', 'beguenstigter', 'begünstigter', 'payee', 'counterparty', 'الطرف'],
            'counterparty_account' => ['iban', 'account', 'konto'],
            'bank_reference' => ['transaction id', 'bank reference', 'ref'],
            'currency' => ['currency', 'waehrung', 'währung', 'العملة'],
        ];

        $map = [];
        $taken = [];

        foreach ($hints as $field => $needles) {
            foreach ($header as $index => $name) {
                if ($name === '' || \in_array($index, $taken, true)) {
                    continue;
                }

                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        $map[$field] = $index;
                        $taken[] = $index;

                        continue 3;
                    }
                }
            }
        }

        return $map;
    }

    private function dialectFrom(Request $request): CsvDialect
    {
        return (new CsvDialect())->with([
            'delimiter' => $this->delimiterFrom((string) $request->input('delimiter', ',')),
            'decimal_separator' => (string) $request->input('decimal_separator', '.'),
            'thousands_separator' => (string) $request->input('thousands_separator', ''),
            'date_format' => (string) $request->input('date_format', 'Y-m-d'),
            'encoding' => (string) $request->input('encoding', 'UTF-8'),
            'has_header' => $request->boolean('has_header'),
            'sign_convention' => (string) $request->input('sign_convention', CsvDialect::SIGN_SIGNED),
        ]);
    }

    /** A tab cannot survive a form field, so it travels by name. */
    private function delimiterFrom(string $value): string
    {
        return $value === 'tab' ? "\t" : $value;
    }

    /** @return array<string, int> */
    private function mapFrom(Request $request): array
    {
        $map = [];

        foreach (array_keys($this->mappableFields()) as $field) {
            $value = $request->input('map.'.$field);

            if ($value !== null && $value !== '' && is_numeric($value)) {
                $map[$field] = (int) $value;
            }
        }

        return $map;
    }

    private function saveTemplate(Request $request, BankAccount $account): void
    {
        $name = trim((string) $request->input('save_template_as'));

        if (mb_strlen($name) < 3) {
            return;
        }

        $dialect = $this->dialectFrom($request);
        $held = $this->held();

        CsvImportTemplate::query()->updateOrCreate(
            ['entity_id' => (string) $account->entity_id, 'name' => $name],
            [
                'id' => (string) Str::ulid(),
                'bank_account_id' => (string) $account->getKey(),
                'institution_name' => (string) $account->institution_name,
                'dialect' => $dialect->toArray(),
                'column_map' => $this->mapFrom($request),
                'header_row' => $held === null ? null : $this->headerOf($held['content'], $dialect),
                'is_shared' => $request->boolean('share_template'),
                'created_by' => (string) $request->user()?->getAuthIdentifier(),
                'last_used_at' => now(),
            ],
        );
    }

    /** @return list<string> */
    private function headerOf(string $content, CsvDialect $dialect): array
    {
        if (! $dialect->hasHeader) {
            return [];
        }

        $first = strtok($this->sniffer->toUtf8($content, $dialect->encoding), "\r\n");

        return $first === false
            ? []
            : array_map(trim(...), str_getcsv($first, $dialect->delimiter, $dialect->enclosure, '\\'));
    }

    /**
     * The fields a column can be mapped to, and what each one is for.
     *
     * @return array<string, string>
     */
    private function mappableFields(): array
    {
        return [
            'booking_date' => __('تاريخ القيد'),
            'value_date' => __('تاريخ الاستحقاق'),
            'amount' => __('المبلغ'),
            'debit' => __('مدين'),
            'credit' => __('دائن'),
            'indicator' => __('اتجاه الحركة'),
            'remittance' => __('البيان'),
            'counterparty_name' => __('اسم الطرف الآخر'),
            'counterparty_account' => __('حساب الطرف الآخر'),
            'bank_reference' => __('مرجع البنك'),
            'end_to_end_id' => __('المرجع الطرفي'),
            'transaction_type' => __('نوع الحركة'),
            'currency' => __('العملة'),
        ];
    }

    private function remember(string $content, string $name): string
    {
        $this->forget();

        $key = (string) Str::ulid();

        Storage::disk('local')->put($this->path($key), $content);

        session([self::SESSION_KEY => ['key' => $key, 'name' => $name]]);

        return $key;
    }

    /** @return array{key: string, name: string, content: string}|null */
    private function held(): ?array
    {
        $pending = session(self::SESSION_KEY);

        if (! \is_array($pending) || ! isset($pending['key'])) {
            return null;
        }

        $path = $this->path((string) $pending['key']);

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return [
            'key' => (string) $pending['key'],
            'name' => (string) ($pending['name'] ?? ''),
            'content' => (string) Storage::disk('local')->get($path),
        ];
    }

    private function forget(): void
    {
        $pending = session(self::SESSION_KEY);

        if (\is_array($pending) && isset($pending['key'])) {
            Storage::disk('local')->delete($this->path((string) $pending['key']));
        }

        session()->forget(self::SESSION_KEY);
    }

    private function path(string $key): string
    {
        return 'statement-imports/'.$key;
    }
}
