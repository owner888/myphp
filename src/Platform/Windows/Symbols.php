<?php
declare(strict_types=1);

namespace MyPHP\Platform\Windows;

use MyPHP\Logger;
use MyPHP\Native\{DWrite, Mem};
use MyPHP\Platform;
use MyPHP\Text\Style;

/**
 * The system's icons on Windows (Platform\Symbols; mygo draws none of the
 * system's): the SF Symbol names the core asks for drawn as glyphs of
 * Windows's icon font, Segoe Fluent Icons (Windows 11's) or else Segoe MDL2
 * Assets (Windows 10's), whose code points are the same for the icons both
 * have (GLYPHS), rasterized by DirectWrite at the size in pixels, the font's
 * em box the icon's square, in the color asked. Their weight has no
 * equivalent and is not drawn. A name GLYPHS maps to none (no Fluent icon
 * has its meaning), or whose glyph the installed font lacks, is drawn as
 * Android's are, a Material Symbol (Android\Symbols), and the latter told
 * once in the log: every name draws, some in Google's design.
 *
 * @internal Platform\Windows
 */
final class Symbols implements Platform\Symbols
{
    /** The icon fonts, the first the system has. */
    public const FONTS = ['Segoe Fluent Icons', 'Segoe MDL2 Assets'];

    /**
     * Each SF Symbol name the core draws (Linux\Symbols::NAMES's) and its
     * icon's code point in Segoe Fluent Icons and Segoe MDL2 Assets, null
     * where none has its meaning.
     * @var array<string, ?int>
     */
    public const GLYPHS = [
        // Playing
        'play.fill' => 0xE768, 'play.circle' => 0xE768, 'play.circle.fill' => 0xE768, 'pause.fill' => 0xE769, 'pause.circle.fill' => 0xE769,
        'playpause' => null, 'playpause.circle' => null,
        'backward.end.fill' => 0xE892, 'forward.end.fill' => 0xE893, 'backward.fill' => 0xEB9E, 'forward.fill' => 0xEB9D, 'goforward.15' => 0xE7A6,
        'repeat' => 0xE8EE, 'repeat.1' => 0xE8ED, 'shuffle' => 0xE8B1, 'play.rectangle' => 0xE714,
        // Sound
        'speaker.slash.fill' => 0xE74F, 'speaker.wave.1.fill' => 0xE993, 'speaker.wave.2.fill' => 0xE994, 'speaker.wave.3' => 0xE995, 'speaker.wave.3.fill' => 0xE995, 'speaker.plus' => 0xE767,
        'hifispeaker.and.homepod' => 0xE7F5, 'waveform' => 0xE9E9, 'music.note' => 0xEC4F, 'music.note.list' => 0xE90B,
        'archivebox' => 0xE7B8, 'checkmark' => 0xE73E, 'flag' => 0xE7C1, 'sidebar.left' => 0xE8A0, 'tray' => null,
        'airpods' => 0xE7F6, 'headphones' => 0xE7F6, 'antenna.radiowaves.left.and.right' => 0xE702,
        'microphone.dynamic.on.stand' => 0xE720, 'music.microphone' => 0xE720, 'music.mic' => 0xE720,
        // Windows and views
        'arrow.up.left.and.arrow.down.right' => 0xE740, 'arrow.up.backward.and.arrow.down.forward' => 0xE740, 'arrow.up.backward.and.arrow.down.forward.rectangle' => 0xE740,
        'arrow.up.left.and.arrow.down.right.rectangle' => 0xE740, 'rectangle.expand.diagonal' => 0xE740,
        'arrow.down.right.and.arrow.up.left' => 0xE73F, 'arrow.down.forward.and.arrow.up.backward.rectangle' => 0xE73F,
        'list.bullet' => 0xE8FD, 'list.bullet.rectangle' => 0xE8FD, 'list.bullet.rectangle.fill' => 0xE8FD,
        'rectangle.grid.3x2.fill' => 0xE8A9, 'ellipsis' => 0xE712, 'ellipsis.rectangle' => 0xE712,
        'pin' => 0xE718, 'pin.fill' => 0xE840, 'pin.square' => 0xE718, 'eye.circle.fill' => 0xE890,
        'captions.bubble' => 0xE7F0, 'captions.bubble.fill' => 0xE7F0,
        'camera.fill' => 0xE722, 'camera.shutter.button' => 0xE722, 'camera.on.rectangle' => null,
        'photo' => 0xE8B9, 'photo.fill' => 0xE8B9, 'photo.tv' => 0xE7F4, 'sun.max' => 0xE706, 'rotate.right' => 0xE7AD,
        // Settings and tools
        'gear' => 0xE713, 'gearshape' => 0xE713, 'gearshape.fill' => 0xE713, 'gearshape.circle' => 0xE713,
        'puzzlepiece' => 0xEA86, 'puzzlepiece.fill' => 0xEA86, 'puzzlepiece.extension' => 0xEA86, 'puzzlepiece.extension.fill' => 0xEA86,
        'wrench' => 0xE90F, 'wrench.adjustable' => 0xE90F, 'wrench.and.screwdriver' => 0xE90F, 'flask' => null,
        'keyboard' => 0xE765, 'computermouse' => 0xE962, 'magicmouse.fill' => 0xE962,
        'globe' => 0xE774, 'network' => 0xE968, 'server.rack' => null, 'cylinder.split.1x2' => 0xEDA2,
        'textformat' => 0xE8D2, 'textformat.size' => 0xE8E9, 'bold' => 0xE8DD, 'italic' => 0xE8DB, 'paintpalette' => 0xE790,
        'pencil' => 0xE70F, 'square.and.pencil' => 0xE70F, 'lock' => 0xE72E, 'lock.display' => 0xE72E,
        'character.book.closed' => 0xE82D, 'battery.0' => 0xE850, 'progress.indicator' => null,
        'person.crop.circle' => 0xE77B, 'person.crop.circle.fill' => 0xE77B,
        'star.fill' => 0xE735, 'star.leadinghalf.fill' => 0xE7C6, 'star.leadinghalf.filled' => 0xE7C6,
        // Files and time
        'doc.on.doc' => 0xE8C8, 'doc' => 0xE8A5, 'doc.fill' => 0xE8A5, 'folder' => 0xE8B7, 'folder.fill' => 0xE8B7, 'square.grid.3x1.folder.badge.plus' => 0xE8F4,
        'square.and.arrow.down' => 0xE896, 'trash' => 0xE74D, 'trash.fill' => 0xE74D,
        'clock' => 0xE917, 'clock.fill' => 0xE917, 'calendar.badge.clock' => 0xE787,
        'clock.arrow.circlepath' => 0xE81C, 'clock.arrow.trianglehead.counterclockwise.rotate.90' => 0xE81C, 'doc.badge.clock' => 0xE81C, 'document.badge.clock' => 0xE81C,
        // Actions and states
        'plus' => 0xE710, 'plus.circle.fill' => 0xE710, 'minus' => 0xE738,
        'clear' => 0xE750, 'xmark.circle.fill' => 0xE711, 'magnifyingglass' => 0xE721, 'text.magnifyingglass' => 0xE721,
        'info.circle' => 0xE946, 'info.bubble' => 0xE946, 'questionmark.circle' => 0xE897,
        'exclamationmark.triangle' => 0xE7BA, 'exclamationmark.circle' => 0xE783, 'checkmark.circle.fill' => 0xE930,
        'nosign' => 0xE733, 'slash.circle.fill' => 0xE733,
        'arrow.clockwise' => 0xE72C, 'arrow.clockwise.circle' => 0xE72C, 'arrow.clockwise.circle.fill' => 0xE72C, 'arrow.trianglehead.2.clockwise.rotate.90' => 0xE895,
        'arrow.counterclockwise' => 0xE7A7, 'arrow.counterclockwise.circle.fill' => 0xE7A7, 'arrow.uturn.backward.circle.fill' => 0xE7A7,
        'arrow.down.to.line' => 0xE896, 'arrow.up.to.line' => 0xE898, 'arrow.left.to.line' => null, 'arrow.right' => 0xE72A,
        'arrow.up.circle' => 0xE74A, 'arrowshape.up.circle' => 0xE74A, 'arrow.turn.down.right' => null, 'arrow.up.arrow.down' => 0xE8CB,
        'chevron.down' => 0xE70D, 'chevron.down.circle' => 0xE70D, 'chevron.down.circle.fill' => 0xE70D,
        'chevron.up' => 0xE70E, 'chevron.up.circle' => 0xE70E,
        'chevron.left' => 0xE76B, 'chevron.right' => 0xE76C, 'chevron.forward.circle.fill' => 0xE76C,
        'crop' => 0xE7A8, 'aspectratio' => 0xE799, 'app.badge' => 0xE71D,
        // No Fluent icon means these: Material Symbols draw them
        'chevron.forward.2' => null, 'chevron.up.chevron.down' => null, 'chevron.left.forwardslash.chevron.right' => null,
        'pip.enter' => null, 'pip.exit' => null, 'pip.swap' => null,
        'doc.viewfinder' => null, 'doc.text.viewfinder' => null, 'document.viewfinder' => null, 'text.viewfinder' => null, 'viewfinder.circle.fill' => null,
        'cpu' => null, 'slider.vertical.3' => null, 'slider.horizontal.below.square.and.square.filled' => null,
        'sun.lefthalf.filled' => null, 'moonphase.first.quarter' => null, 'pencil.slash' => null, 'paintbrush.pointed.fill' => null, 'shadow' => null,
        'textformat.characters.arrow.left.and.right' => null, 'character.textbox.badge.sparkles' => null, 'sparkles' => null, 'book.and.wrench' => null,
        'macwindow' => null, 'macwindow.on.rectangle' => null, 'macwindow.and.cursorarrow' => null, 'macwindow.and.pointer.arrow' => null,
        'menubar.rectangle' => null, 'menubar.dock.rectangle' => null, 'filemenu.and.selection' => null, 'cursorarrow.click' => null, 'pointer.arrow.click' => null,
        'app.badge.checkmark.fill' => null, 'bolt.badge.a' => null, 'bolt.badge.automatic' => null,
        'circle.fill' => null, 'rectangle.fill' => null, 'square.stack.3d.up' => null, 'square.2.layers.3d.top.filled' => null, 'square.dashed' => null,
        'square.dashed.inset.filled' => null, 'square.dotted' => null, 'circle.dashed.inset.fill' => null, 'circle.dashed.inset.filled' => null, 'inset.filled.circle.dashed' => null,
        'inset.filled.bottomthird.rectangle' => null, 'inset.filled.topleft.rectangle' => null, 'inset.filled.topthird.middlethird.bottomthird.rectangle' => null,
        'rectangle.bottomthird.inset.fill' => null, 'rectangle.bottomthird.inset.filled' => null, 'rectangle.connected.to.line.below' => null,
        'capsule.on.rectangle' => null, 'capsule.on.rectangle.liquid.glass' => null, 'liquid.glass' => null, 'geometry.size' => null, 'text.below.photo' => null,
        'list.and.film' => null, 'list.clipboard' => null, 'point.3.filled.connected.trianglepath.dotted' => null,
        'arrow.up.left.circle' => null, 'arrow.up.left.circle.fill' => null, 'arrow.up.and.down' => null, 'arrow.up.and.down.and.arrow.left.and.right' => null,
        'arrow.right.and.line.vertical.and.arrow.left' => null, 'arrow.up.left.bottomright.rectangle' => null, 'arrow.up.left.and.down.right.and.arrow.up.right.and.down.left' => null,
    ];

    private ?DWrite $dw = null;
    private ?\MyPHP\Platform\Android\Symbols $material = null;
    /** The icon font the system has, '' for none; null till asked. */
    private ?string $font = null;
    /** @var array<int, ?array{0: int, 1: int}> the face and glyph of each code point in the icon font, null for none */
    private array $glyphs = [];
    /** @var array<string, true> the names told in the log as drawn as Material Symbols, their glyph missing */
    private array $told = [];

    public function exists(string $name): bool
    {
        return $this->glyph($name) !== null || $this->material()->exists($name);
    }

    public function draw(string $name, float $size, float $weight, int $rgb, float $scale): array
    {
        $px = max(1, (int)round($size * $scale));
        $glyph = $this->glyph($name);
        if ($glyph === null) {
            if ((self::GLYPHS[$name] ?? null) !== null && !isset($this->told[$name])) {
                $this->told[$name] = true;
                Logger::info(sprintf('the system symbol "%s" draws as a Material Symbol on Windows: %s has no U+%04X', $name, $this->font() ?: 'no icon font', self::GLYPHS[$name]), 'windows');
            }
            return $this->material()->draw($name, $size, $weight, $rgb, $scale);
        }
        [$fontId, $glyphId] = $glyph;
        $dw = $this->dw();
        $font = $dw->engine()->font(new Style($this->font(), $size)); // the size rasterize() draws the face at
        $bitmap = $dw->rasterize($fontId, $glyphId, $scale);
        $out = str_repeat("\0", $px * $px * 4);
        if ($bitmap === null || $font === null) {
            return [$px, $px, $out]; // a glyph of no ink
        }
        try {
            $alpha = Mem::read($bitmap['addr'], $bitmap['w'] * $bitmap['h']);
        } finally {
            Mem::free($bitmap['rows']);
        }
        // The em box the icon's square: the baseline where the ascent ends, the pen at its left.
        $baseline = (int)round($px * $font->ascent / max($font->ascent + $font->descent, 1e-6));
        [$x0, $y0] = [$bitmap['ox'], $baseline - $bitmap['oy'] - $bitmap['h']];
        [$r, $g, $b] = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
        for ($y = 0; $y < $bitmap['h']; $y++) {
            $cy = $y0 + $y;
            if ($cy < 0 || $cy >= $px) { continue; }
            for ($x = 0; $x < $bitmap['w']; $x++) {
                $cx = $x0 + $x;
                $a = ord($alpha[$y * $bitmap['w'] + $x]);
                if ($a === 0 || $cx < 0 || $cx >= $px) { continue; }
                $out = substr_replace($out, chr(intdiv($r * $a + 127, 255)) . chr(intdiv($g * $a + 127, 255)) . chr(intdiv($b * $a + 127, 255)) . chr($a), ($cy * $px + $cx) * 4, 4);
            }
        }
        return [$px, $px, $out];
    }

    /** The icon font's face and $name's glyph in it, null where GLYPHS maps it to none or the font lacks it. @return ?array{0: int, 1: int} */
    private function glyph(string $name): ?array
    {
        $cp = self::GLYPHS[$name] ?? null;
        return $cp === null ? null : $this->codePoint($cp);
    }

    /** The icon font's face and the glyph of code point $cp in it, null where the font lacks it (DirectWrite falling back to another font). @return ?array{0: int, 1: int} */
    public function codePoint(int $cp): ?array
    {
        if ($this->font() === '') {
            return null;
        }
        if (!array_key_exists($cp, $this->glyphs)) {
            $this->glyphs[$cp] = null;
            $style = new Style($this->font(), 12);
            $engine = $this->dw()->engine();
            $id = $engine->font($style)?->id;
            foreach ($engine->shape([$cp], $style, [], 0, false, false) as $line) {
                foreach ($line['runs'] as $run) {
                    foreach ($run['glyphs'] as $g) {
                        if ($id !== null && $run['font']?->id === $id && $g->id !== 0) { $this->glyphs[$cp] = [$id, $g->id]; } // its own font's glyph, not a fallback font's
                    }
                }
            }
        }
        return $this->glyphs[$cp];
    }

    /** The first of FONTS the system has, '' for none. */
    public function font(): string
    {
        if ($this->font === null) {
            $this->font = '';
            foreach (self::FONTS as $family) {
                if ($this->dw()->engine()->hasSystem($family)) { $this->font = $family; break; }
            }
        }
        return $this->font;
    }

    private function dw(): DWrite
    {
        return $this->dw ??= new DWrite(1.0);
    }

    private function material(): \MyPHP\Platform\Android\Symbols
    {
        return $this->material ??= new \MyPHP\Platform\Android\Symbols();
    }
}
