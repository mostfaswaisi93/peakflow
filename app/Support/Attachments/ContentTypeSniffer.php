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

    /** @return list<string> */
    public static function permittedTypes(): array
    {
        return array_keys(self::PERMITTED);
    }
}
