<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use MyPHP\Logger;
use MyPHP\Platform;
use MyPHP\UI\Svg\Doc;

/**
 * The system's icons on Android (Platform\Symbols): Android has no icons to
 * ask for by name, so the SF Symbol names an app gives (Label(systemImage:),
 * Tab(systemImage:), Image(systemName:)) are drawn as Google's Material
 * Symbols, Android's own design, which MaterialSymbols holds as paths, by
 * myphp's SVG renderer in the color asked. SF Symbols' weight is not drawn.
 * A name MaterialSymbols maps to no icon is drawn as a transparent square of
 * the size, so that the UI lays out as it would, and told once in the log,
 * as Linux\Symbols does.
 *
 * @internal
 */
final class Symbols implements Platform\Symbols
{
    /** @var array<string, Doc> the icons parsed, by their name in MaterialSymbols::ICONS */
    private array $docs = [];
    /** @var array<string, true> the names of no icon told in the log */
    private array $told = [];

    public function exists(string $name): bool
    {
        return isset(MaterialSymbols::NAMES[$name]);
    }

    public function draw(string $name, float $size, float $weight, int $rgb, float $scale): array
    {
        $px = max(1, (int)round($size * $scale));
        $icon = MaterialSymbols::NAMES[$name] ?? null;
        if ($icon === null) {
            if (!isset($this->told[$name])) {
                $this->told[$name] = true;
                Logger::warn("the system symbol \"$name\" draws empty on Android: no Material Symbol is mapped to it (Android\\MaterialSymbols::NAMES)", 'android');
            }
            return [$px, $px, str_repeat("\0", $px * $px * 4)];
        }
        if (!isset($this->docs[$icon])) {
            [$box, $d] = MaterialSymbols::ICONS[$icon];
            $this->docs[$icon] = new Doc("<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"$box\"><path d=\"$d\" fill=\"currentColor\"/></svg>");
        }
        $bytes = $this->docs[$icon]->pixelBytes($px, $px, [(($rgb >> 16) & 255) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255, 1.0]);
        return [$px, $px, $bytes];
    }
}
