<?php
declare(strict_types=1);

namespace MyPHP\Native;

use MyPHP\I18n;

/**
 * The pseudo locales' transforms of a message, as Android's (mygo's
 * pseudo.go): en-XA accents the letters, pads the text by 40% and brackets
 * it; ar-XB shows each word right to left. One formats one message.
 *
 * @internal
 */
final class Pseudo
{
    /** en-XA's letters, from Android's pseudolocalizer. */
    private const ACCENTED = ['a' => 'å', 'b' => 'ƀ', 'c' => 'ç', 'd' => 'ð', 'e' => 'é', 'f' => 'ƒ', 'g' => 'ĝ', 'h' => 'ĥ', 'i' => 'î', 'j' => 'ĵ', 'k' => 'ķ', 'l' => 'ļ', 'm' => 'ɱ', 'n' => 'ñ', 'o' => 'ö', 'p' => 'þ', 'q' => 'ǫ', 'r' => 'ŕ', 's' => 'š', 't' => 'ţ', 'u' => 'û', 'v' => 'ṽ', 'w' => 'ŵ', 'x' => 'ẋ', 'y' => 'ý', 'z' => 'ž',
        'A' => 'Å', 'B' => 'Ɓ', 'C' => 'Ç', 'D' => 'Ð', 'E' => 'É', 'F' => 'Ƒ', 'G' => 'Ĝ', 'H' => 'Ĥ', 'I' => 'Î', 'J' => 'Ĵ', 'K' => 'Ķ', 'L' => 'Ļ', 'M' => 'Ṁ', 'N' => 'Ñ', 'O' => 'Ö', 'P' => 'Þ', 'Q' => 'Ǫ', 'R' => 'Ŕ', 'S' => 'Š', 'T' => 'Ţ', 'U' => 'Û', 'V' => 'Ṽ', 'W' => 'Ŵ', 'X' => 'Ẋ', 'Y' => 'Ý', 'Z' => 'Ž'];
    /** The words that lengthen en-XA's messages. */
    private const PAD = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'];
    private const RLM = "\u{200F}", RLO = "\u{202E}", PDF = "\u{202C}";
    /** The characters of en-XA's text, which pad by. */
    private int $length = 0;

    private function __construct(private readonly bool $bidi) {}

    /** The transform of a pseudo locale, or null for another. */
    public static function for(string $tag): ?self
    {
        return match (I18n::stripExtensions(I18n::normalize($tag))) { I18n::PSEUDO_ACCENTS => new self(false), I18n::PSEUDO_BIDI => new self(true), default => null };
    }

    /** The text of a message transformed. */
    public function text(string $s): string
    {
        if (!$this->bidi) {
            $this->length += mb_strlen($s);
            return strtr($s, self::ACCENTED);
        }
        // Each word, each run of characters other than spaces, right to left: an override reverses its letters, marks set it in a right-to-left run.
        return (string)preg_replace_callback('/(*UCP)\S+/u', fn($m) => self::RLM . self::RLO . $m[0] . self::PDF . self::RLM, $s);
    }

    /** What an argument wrote: as it is, in a right-to-left run for ar-XB. */
    public function placeholder(string $s): string
    {
        return $this->bidi ? self::RLM . $s . self::RLM : $s;
    }

    /** The formatted message: en-XA brackets it and pads it by 40% of its text, rounded up, with words, as translations run longer than English. */
    public function wrap(string $s): string
    {
        if ($this->bidi) {
            return $s;
        }
        $pad = intdiv($this->length * 2 + 4, 5);
        for ($i = 0, $n = 0; $n < $pad; $i++) {
            $w = self::PAD[$i % count(self::PAD)];
            $s .= " $w";
            $n += 1 + strlen($w);
        }
        return "[$s]";
    }
}
