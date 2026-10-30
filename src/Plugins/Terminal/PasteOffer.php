<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal;

use MyPHP\Transfer\{Data, Format, FormatUnavailable};

/** What a paste offered a program that reads the clipboard itself (mygo's pasteOffer). @internal */
final class PasteOffer
{
    /** @var list<string> the MIME types offered */
    public array $mimes = [];

    public function __construct(public Data $data)
    {
        foreach ($data->formats() as $f) {
            if (($m = self::mime($f)) !== '' && !in_array($m, $this->mimes, true)) { $this->mimes[] = $m; }
        }
    }

    /** The MIME type a paste offers $format as, or '' for a format the protocol does not carry: one of MyGo's own, or no MIME type (mygo's offerMIME). */
    public static function mime(string $format): string
    {
        if ($format === Format::FILE_LIST) {
            return Format::URI_LIST;
        }
        if (!str_contains($format, '/') || strpbrk($format, " \t") !== false || str_starts_with($format, 'application/x-mygo-')) {
            return '';
        }
        return $format;
    }

    /** What the offer has of a MIME type, '' for nothing. */
    public function read(string $mime): string
    {
        return in_array($mime, $this->mimes, true) ? self::readFormat($this->data, $mime) : '';
    }

    /** What $d holds of a MIME type, as the protocols carry it: a file list answers text/uri-list; '' for nothing (mygo's readFormat). */
    public static function readFormat(Data $d, string $mime): string
    {
        try {
            return $d->read($mime);
        } catch (FormatUnavailable) {
            if ($mime !== Format::URI_LIST) { return ''; }
        } catch (\Throwable) {
            return '';
        }
        try {
            return $d->read(Format::FILE_LIST);
        } catch (\Throwable) {
            return '';
        }
    }
}
