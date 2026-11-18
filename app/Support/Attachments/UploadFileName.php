<?php

declare(strict_types=1);

namespace App\Support\Attachments;

/**
 * The name an upload is allowed to carry - PF-073, PF-236.
 *
 * ## A filename is shown to a person, and that is the whole problem
 *
 * The stored path never comes from the name - {@see AttachmentStore} derives
 * it from the content hash, so a traversal cannot reach the filesystem. What
 * the name still decides is what somebody READS: the row in the attachment
 * list an approver clicks, and the name their browser writes to disk from
 * `Content-Disposition`. Every rule below exists because a name can be
 * written so that those two disagree.
 *
 * ## The override attack, which is the one worth the whole file
 *
 * `U+202E RIGHT-TO-LEFT OVERRIDE` reverses the rendering of everything after
 * it. A file called `invoice\u{202E}sj.nosj` is displayed by every list, mail
 * client and file manager as `invoice json.js`... read the other way round:
 * the bytes end `.nosj` and the screen says `.json`. Pick the characters
 * carefully and a `.js` or `.html` file displays as `.pdf`, next to thirty
 * genuine PDFs, and the person about to open it has been told the truth by
 * nothing.
 *
 * ## Why refusing the controls does not hurt Arabic
 *
 * This is an Arabic-first product and most attachment names here are Arabic,
 * so a rule that refused right-to-left text would be unusable. It does not:
 * Arabic letters carry their own direction, the Unicode Bidirectional
 * Algorithm lays them out from that, and `فاتورة.pdf` needs no control
 * character to render correctly. What is refused is the *explicit override* -
 * the character whose only function is to make the rendering disagree with
 * the bytes. Nothing legitimate asks for that in a filename.
 *
 * ## Mixed script, for the same reason one step further out
 *
 * `invoiсe.pdf` with a Cyrillic `с` is a different file from `invoice.pdf`
 * and the two are indistinguishable in every font. So a name whose letters
 * come from more than one of the three mutually-confusable alphabets -
 * Latin, Cyrillic, Greek - is refused. A name entirely in one of them is
 * fine, and Arabic, Hebrew, Chinese and the rest are fine alongside anything,
 * because nobody mistakes `ف` for an `f`.
 *
 * ## Normalization happens, and is not the defence
 *
 * The accepted name is normalized to NFC, because `é` written as one code
 * point and as `e` + combining acute are the same name and storing both
 * produces two rows a person cannot tell apart. Normalization is done AFTER
 * the refusals, never as a way of achieving one: a rule that sanitizes a
 * dangerous name into a safe one hands back a name the uploader did not
 * choose, and the uploader is the person who has to recognise their own file.
 */
final class UploadFileName
{
    /**
     * The longest name that survives the trip.
     *
     * 255 bytes is the per-component limit on ext4, APFS and NTFS alike, and
     * a name past it is one the person's own browser cannot write to disk -
     * so the download would fail after the file had been stored, accepted and
     * listed.
     */
    private const MAX_BYTES = 255;

    /**
     * Characters whose only purpose is to make the rendering lie.
     *
     * The bidirectional overrides and isolates, the zero-width joiners and
     * spaces, the soft hyphen, the word joiner and a byte-order mark that has
     * wandered into the middle of a string. Each one is invisible, and each
     * one lets two different names look identical.
     *
     * @var list<string>
     */
    private const INVISIBLE = [
        "\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}",
        "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}",
        "\u{2060}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}",
        "\u{061C}", "\u{00AD}", "\u{180E}", "\u{FEFF}",
    ];

    /**
     * Names MS-DOS gave to devices, which Windows still honours.
     *
     * `NUL.pdf` opens the null device rather than a file, on a path with any
     * directory in front of it and with any extension after it. A download
     * that writes one does not produce a file and does not say why.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'con', 'prn', 'aux', 'nul',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    /**
     * Why this name is refused, or null when it is acceptable.
     *
     * A sentence rather than a boolean, and a sentence naming the character
     * rather than "invalid filename": the uploader is usually a person who
     * was sent this file by somebody else, and "the name contains a
     * right-to-left override, which makes the extension on screen differ from
     * the extension in the name" is the only form of this message that tells
     * them something true about the file they were sent.
     */
    public static function refusal(string $declared): ?string
    {
        if (! mb_check_encoding($declared, 'UTF-8')) {
            return 'The name is not valid UTF-8. A name whose bytes are not text cannot be compared, '.
                'normalized or put in a download header, and the bytes that arrive are not reliably the '.
                'bytes that were sent.';
        }

        $name = trim($declared);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'The file has no usable name.';
        }

        if (\strlen($name) > self::MAX_BYTES) {
            return sprintf(
                'The name is %d bytes and the limit is %d. A longer one is a name the browser cannot '.
                'write to disk, so the download would fail after the file had been stored and listed.',
                \strlen($name),
                self::MAX_BYTES,
            );
        }

        if (str_contains($name, '/') || str_contains($name, '\\')) {
            return sprintf(
                'The name "%s" contains a path separator. A name is user input, and a path built from '.
                'user input is a path traversal - which is why the stored path is derived from the '.
                'content hash and never from the name.',
                $name,
            );
        }

        /*
         | Every control character, not only NUL.
         |
         | NUL truncates the name for any C library that touches it, so
         | `invoice.pdf\0.html` is two names at once. CR and LF end a header
         | and begin another, which is response splitting through a filename.
         | The rest are refused together because a name containing a vertical
         | tab is not a name anybody typed.
         */
        if (preg_match('/[\x00-\x1F\x7F\x{0080}-\x{009F}]/u', $name) === 1) {
            return 'The name contains a control character. A NUL truncates the name for anything written '.
                'in C, so "invoice.pdf\0.html" is two names at once, and a carriage return ends one HTTP '.
                'header and starts another.';
        }

        foreach (self::INVISIBLE as $character) {
            if (str_contains($name, $character)) {
                return sprintf(
                    'The name contains U+%04X, which renders as nothing. Characters like it exist to make '.
                    'the name on screen differ from the name in the file: a right-to-left override turns '.
                    '"…sj.nosj" into "…json.js" on every screen that shows it, so the extension somebody '.
                    'reads is not the extension the file has.',
                    mb_ord($character, 'UTF-8'),
                );
            }
        }

        /*
         | A leading dot, and a trailing dot or space.
         |
         | A leading dot is a hidden file on every Unix, which is a file
         | somebody downloads and then cannot find. A TRAILING dot or space is
         | worse: Windows silently strips them when opening a path, so
         | `report.pdf.` is stored under one name and opened as another - and
         | `report.html.pdf ` opens as `report.html.pdf`.
         |
         | They are refused rather than trimmed, because trimming changes
         | which extension the name ends in, and this class does not get to
         | decide that.
         */
        if (str_starts_with($name, '.')) {
            return sprintf(
                'The name "%s" begins with a dot, which makes it a hidden file on every Unix - a file '.
                'somebody downloads and then cannot find.',
                $name,
            );
        }

        if (str_ends_with($name, '.') || str_ends_with($name, ' ') || str_ends_with($declared, ' ')) {
            return sprintf(
                'The name "%s" ends in a dot or a space. Windows strips both when it opens a path, so '.
                'the file is stored under one name and opened under another - which is how '.
                '"report.html.pdf " comes to be opened as "report.html.pdf".',
                $name,
            );
        }

        $stem = strtolower(pathinfo($name, \PATHINFO_FILENAME));

        if (\in_array($stem, self::RESERVED, true)) {
            return sprintf(
                'The name "%s" is an MS-DOS device name, which Windows still honours with any extension '.
                'after it. Writing it opens the device rather than creating a file, and the download '.
                'fails without saying why.',
                $name,
            );
        }

        /*
         | The mixed-script test looks at the STEM, not the whole name.
         |
         | Every permitted extension is Latin - `.pdf` is `.pdf` in every
         | language - so a name written entirely in Cyrillic still ends in
         | Latin letters, and testing the whole name would refuse `отчет.pdf`
         | for mixing scripts with its own extension. That is not a
         | confusable name, it is a Russian supplier's invoice, and refusing
         | it would make this rule a refusal of foreign names rather than of
         | forged ones.
         |
         | The extension needs no confusable check of its own: it is
         | constrained to a Latin allowlist by
         | {@see ContentTypeSniffer::extensionRefusal()}, so a Cyrillic
         | look-alike extension is refused for not being on the list.
         */
        return self::mixedScriptRefusal($name, pathinfo($name, \PATHINFO_FILENAME));
    }

    /**
     * The name as it will be stored, once {@see refusal()} has passed.
     *
     * NFC, and nothing else. No stripping, no replacing, no transliteration:
     * every name that reaches here has already been found acceptable, and a
     * normalizer that also sanitized would be a second, silent allowlist
     * whose decisions nobody could see.
     */
    public static function normalize(string $declared): string
    {
        $name = trim($declared);

        if (! class_exists(\Normalizer::class)) {
            /*
             | ext-intl is a hard requirement in composer.json, so this is
             | unreachable in a supported deployment. It returns the name
             | rather than throwing because a missing extension is an
             | operator's problem and refusing every upload is not how
             | somebody should find out about it.
             */
            return $name;
        }

        $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);

        return \is_string($normalized) ? $normalized : $name;
    }

    /**
     * Letters from more than one mutually-confusable alphabet.
     *
     * Latin, Cyrillic and Greek only. Those three share letterforms - `а`,
     * `е`, `о`, `р`, `с`, `х` are Cyrillic and `ο`, `ν`, `ρ` are Greek, and
     * none is distinguishable from its Latin twin in any font a person reads
     * a file list in.
     *
     * Arabic, Hebrew, Han, Hangul, Devanagari and the rest are deliberately
     * absent: they are not confusable with Latin, and an Arabic name with a
     * Latin extension - `فاتورة.pdf` - is the single most common name this
     * product sees.
     */
    private static function mixedScriptRefusal(string $name, string $stem): ?string
    {
        $scripts = [];

        foreach ([
            'Latin' => '/[A-Za-z\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{024F}]/u',
            'Cyrillic' => '/[\x{0400}-\x{04FF}\x{0500}-\x{052F}]/u',
            'Greek' => '/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]/u',
        ] as $script => $pattern) {
            if (preg_match($pattern, $stem) === 1) {
                $scripts[] = $script;
            }
        }

        if (\count($scripts) < 2) {
            return null;
        }

        return sprintf(
            'The name "%s" mixes %s letters. Those alphabets share letterforms - a Cyrillic "с" is not a '.
            'Latin "c" and no font tells them apart - so this name can sit in a list beside a genuine one '.
            'and be indistinguishable from it. A name written entirely in any one of them is accepted.',
            $name,
            implode(' and ', $scripts),
        );
    }
}
