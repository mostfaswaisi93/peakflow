<?php

declare(strict_types=1);

namespace App\Support\Attachments;

/**
 * What the bytes are, not what the uploader said - PF-073.
 *
 * ## The whole point
 *
 * A browser's `Content-Type` is whatever the uploader's client claimed. An
 * uploader who renames `payload.html` to `invoice.pdf` gets
 * `application/pdf` from every client there is, and a system that stores
 * that and later serves it with that type has stored an HTML file it will
 * execute on request.
 *
 * So the type is read from the content, and the declared one is kept only as
 * a note of what the uploader believed.
 *
 * ## The extension is checked against the sniffed type, not trusted either
 *
 * Both are user input. {@see mismatch()} exists so the upload path can say
 * "this file is named .pdf and is an HTML document", which is a far more
 * useful refusal than "this type is not allowed" - and it is the message
 * that tells somebody they have been sent something odd.
 */
final class ContentTypeSniffer
{
    /**
     * The types a tenant may attach, and the extensions each may carry.
     *
     * An allowlist, because the alternative - a denylist - is a list of the
     * dangerous formats somebody thought of. SVG and HTML are absent
     * deliberately: both execute script in a browser, and no accounting
     * document needs either.
     *
     * @var array<string, list<string>>
     */
    private const PERMITTED = [
        'application/pdf' => ['pdf'],
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/tiff' => ['tif', 'tiff'],
        'text/plain' => ['txt', 'csv', 'md'],
        'text/csv' => ['csv'],
        'text/xml' => ['xml'],
        'application/xml' => ['xml'],
        'application/json' => ['json'],
        'application/zip' => ['zip', 'xlsx', 'docx', 'pptx', 'odt', 'ods'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/msword' => ['doc'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
        'application/vnd.oasis.opendocument.text' => ['odt'],
    ];

    /**
     * The sniffed type of a file on disk.
     *
     * `finfo` reads the magic bytes. It returns `application/octet-stream`
     * for anything it cannot place, which is a refusal rather than a type.
     */
    public function sniff(string $absolutePath): string
    {
        $info = new \finfo(\FILEINFO_MIME_TYPE);
        $type = $info->file($absolutePath);

        if ($type === false || trim($type) === '') {
            return 'application/octet-stream';
        }

        return strtolower(trim($type));
    }

    public function isPermitted(string $sniffedType): bool
    {
        return isset(self::PERMITTED[$sniffedType]);
    }

    /**
     * Whether a declared name's extension matches the sniffed type.
     *
     * Returns null when they agree, or a sentence when they do not - which
     * is the refusal worth showing somebody: "this file is named .pdf and
     * is an HTML document" tells them they have been sent something odd,
     * where "this type is not allowed" does not.
     *
     * ## An Office file IS a zip, and that is not a mismatch
     *
     * `finfo` reports `.xlsx` as `application/zip` on most builds, because
     * that is exactly what it is. A check that called that a mismatch would
     * refuse every spreadsheet anybody attaches, so `application/zip`
     * legitimately carries the Office extensions.
     */
    public function mismatch(string $declaredName, string $sniffedType): ?string
    {
        $extension = strtolower(pathinfo($declaredName, \PATHINFO_EXTENSION));

        if ($extension === '') {
            return null;
        }

        $permittedExtensions = self::PERMITTED[$sniffedType] ?? [];

        if ($permittedExtensions === [] || \in_array($extension, $permittedExtensions, true)) {
            return null;
        }

        return sprintf(
            'The file is named ".%s" and its contents are %s. Either it has been renamed or it is not '.
            'what the sender believed it was.',
            $extension,
            $sniffedType,
        );
    }

    /**
     * Why this name's extension is refused outright, or null.
     *
     * ## Two questions, and {@see mismatch()} only answers one
     *
     * `mismatch()` asks whether the extension AGREES with the bytes, which
     * needs a sniffed type and is silent when there is no extension to
     * disagree. This asks whether the extension is one the product serves at
     * all, which is a property of the name alone.
     *
     * The gap between them was real: a file with NO extension passed both
     * checks. `isPermitted()` had cleared its bytes - plain text is on the
     * list - and `mismatch()` returned null because there was nothing to
     * compare. It was then stored and handed back by the download route under
     * whatever name the uploader chose, with no extension for the browser to
     * decide anything from, which is the one case `X-Content-Type-Options:
     * nosniff` cannot help with: there is nothing to sniff against.
     *
     * PF-236 asks for an extension allowlist, and an allowlist that admits
     * the empty string is not one.
     */
    public function extensionRefusal(string $declaredName): ?string
    {
        $extension = strtolower(pathinfo($declaredName, \PATHINFO_EXTENSION));

        if ($extension === '') {
            return sprintf(
                'The name "%s" has no extension. The extension is what a person reads before opening a '.
                'file and what their operating system uses to decide what to open it with, and a name '.
                'without one leaves both to guesswork. Add the extension the file actually is: %s.',
                $declaredName,
                implode(', ', self::permittedExtensions()),
            );
        }

        if (! \in_array($extension, self::permittedExtensions(), true)) {
            return sprintf(
                'The extension ".%s" is not one an accounting document carries. The list is an allowlist '.
                'rather than a list of the dangerous formats somebody thought of: %s.',
                $extension,
                implode(', ', self::permittedExtensions()),
            );
        }

        return null;
    }

    /** @return list<string> */
    public static function permittedTypes(): array
    {
        return array_keys(self::PERMITTED);
    }

    /**
     * Every extension any permitted type may carry, deduplicated.
     *
     * Derived from {@see PERMITTED} rather than written out a second time: a
     * separate list is one that drifts the first time a type is added, and
     * the direction it drifts in is "the extension is allowed and the type is
     * not", which refuses a legitimate file for a reason nobody can read.
     *
     * @return list<string>
     */
    public static function permittedExtensions(): array
    {
        $extensions = [];

        foreach (self::PERMITTED as $allowed) {
            foreach ($allowed as $extension) {
                $extensions[$extension] = true;
            }
        }

        $list = array_keys($extensions);
        sort($list);

        return $list;
    }
}
