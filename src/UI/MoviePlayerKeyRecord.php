<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;

/**
 * IINA's key mapping alert (KeyRecordViewController in an NSAlert, its
 * accessory 480 by 155): the key pressed in its box recorded as mpv names
 * it; an action chosen in its row of choices (IINA's NSRuleEditor of
 * KeyBindingDataLoader's commands: a choice at each step, a field where a
 * value goes), which writes the mpv command in the field under it, or one
 * typed there; OK once both are there.
 *
 * @internal MoviePlayerSettingsPages
 */
final class MoviePlayerKeyRecord
{
    /** The properties of set, cycle, add, multiply and cycle-values, and their kinds (KeyBindingDataLoader.propertyList), '---' between groups. */
    private const PROPERTIES = [['pause', 'bool'], ['speed', 'num'], ['---', ''], ['video', 'num'], ['video-aspect-override', 'string'], ['contrast', 'num'], ['brightness', 'num'],
        ['gamma', 'num'], ['saturation', 'num'], ['deinterlace', 'bool'], ['---', ''], ['audio', 'num'], ['volume', 'num'], ['mute', 'bool'], ['audio-delay', 'num'], ['---', ''],
        ['sub', 'num'], ['sub-delay', 'num'], ['sub-pos', 'num'], ['sub-scale', 'num'], ['sub-visibility', 'bool'], ['---', ''], ['fullscreen', 'bool'], ['ontop', 'bool'], ['---', ''],
        ['chapter', 'num']];
    /** IINA's properties cycled by its own toggles (toggleableIINAProperties). */
    private const IINA_TOGGLES = ['flip', 'mirror'];

    /** @param Closure(string, string): void $done told the key and the action with OK */
    public static function open(Context $c, string $key, string $action, Closure $done): void
    {
        $s = (object)['key' => $key, 'action' => new TextState($action), 'path' => [], 'values' => [], 'recording' => false];
        MoviePlayerAlert::open(MoviePlayerSettings::iina($c, 'keymapping.title'), MoviePlayerSettings::iina($c, 'keymapping.message'), $s,
            fn(Context $c, object $s) => self::view($c, $s), fn(object $s) => trim($s->key) !== '' && trim($s->action->text) !== '',
            fn(object $s) => $done(trim($s->key), trim($s->action->text)), 480, 330);
    }

    private static function view(Context $c, object $s): void
    {
        $t = $c->theme;
        $k = MoviePlayerSettings::colors($t);
        // The box the key is pressed in: focused, it records any key (Escape and Return too).
        $box = $c->column()->height(46)->fillWidth()->center()->radius(8)->focusable()->role(Role::BUTTON)->label(MoviePlayerSettings::iina($c, 'keymapping.message'))
            ->background($k['field'], $k['fieldAlpha'] / 2)->borderWidth(1);
        $box->borderColor($c->focused($box) ? $t->accent : $k['line']);
          $c->text($s->key, 24, $t->text)->textAlign(Node::CENTER)->singleLine();
        $c->end();
        $box->autoFocus();
        $s->recording = $c->focused($box);
        if ($s->recording) {
            $c->takeKeys(function (string $chars, int $code, int $mods, string $plain) use ($s): bool {
                $name = MoviePlayerKeys::fromEvent($code, $mods, $plain);
                if ($name !== '') { $s->key = $name; }
                return true;
            });
        }
        $c->text($c->texts()->get('keyRecordSelectAction'), 11, $t->text)->margin(8, 0, 0, 0);
        $c->row(0, 4)->fillWidth()->alignItems(Node::CENTER);
          $chosen = self::row($c, $s, self::tree(), 0, 'cmd');
        $c->end();
        if ($chosen !== null) { $s->action->text = self::command($chosen); }
        $c->text($c->texts()->get('keyRecordEnterCommand'), 11, $t->text)->margin(8, 0, 0, 0);
        $c->row()->height(22)->fillWidth()->radius(6)->background($k['field'], $k['fieldAlpha'] / 2)->borderWidth(1)->borderColor($k['line']);
          $f = $c->input($s->action, '', 0, 13)->height(22);
          $f->bordered = false;
        $c->end();
    }

    /**
     * The choices from $items on, at step $depth: a pop-up where there are
     * more than one, the one alone shown (a label, or a field of a value);
     * the criteria chosen when one changed this frame, else null.
     * @param list<array<string, mixed>> $items
     * @return ?list<array<string, mixed>>
     */
    private static function row(Context $c, object $s, array $items, int $depth, ?string $l10n): ?array
    {
        if ($items === []) { return null; }
        $changed = false;
        if (count($items) > 1) {
            $options = [];
            foreach ($items as $i => $it) { if ($it['type'] !== 'separator') { $options[$i] = self::label($c, $it, $l10n); } }
            $i = $s->path[$depth] ?? array_key_first($options);
            $picked = MoviePlayerSettings::popUp($c, $options, $i, small: true);
            if ($picked !== null && $picked !== $i) { $s->path = array_slice($s->path, 0, $depth); $s->path[$depth] = (int)$picked; $i = (int)$picked; $changed = true; }
        } else {
            $i = 0;
            $it = $items[0];
            if ($it['type'] === 'string' || $it['type'] === 'number') {
                $field = $s->values[$depth] ??= new TextState();
                $k = MoviePlayerSettings::colors($c->theme);
                $c->row()->size(50, 20)->radius(5)->background($k['field'], $k['fieldAlpha'] / 2)->borderWidth(1)->borderColor($k['line']);
                  $n = $c->key("value:$depth")->input($field, '', 50, 11)->height(20);
                  $n->bordered = false;
                $c->end();
                $changed = $c->changed($n);
            } else {
                $c->text(self::label($c, $it, $l10n), 12, $c->theme->text);
            }
        }
        $it = $items[$i];
        $below = self::row($c, $s, $it['children'], $depth + 1, null);
        if (!$changed && $below === null) { return null; }
        return self::criteria($s, self::tree(), 0, 'cmd');
    }

    /** The criteria chosen, from the first step: each its item and, for a field, its text. @return list<array<string, mixed>> */
    private static function criteria(object $s, array $items, int $depth, ?string $l10n): array
    {
        if ($items === []) { return []; }
        $i = count($items) > 1 ? ($s->path[$depth] ?? self::firstChoice($items)) : 0;
        $it = $items[$i] + ['value' => isset($s->values[$depth]) ? $s->values[$depth]->text : ''];
        return [$it, ...self::criteria($s, $items[$i]['children'], $depth + 1, null)];
    }

    private static function firstChoice(array $items): int
    {
        foreach ($items as $i => $it) { if ($it['type'] !== 'separator') { return $i; } }
        return 0;
    }

    /** An item's words (KeyBindingItem.toCriterion's): IINA's command's, "<l10n>.<name>", else its name's own. */
    private static function label(Context $c, array $it, ?string $l10n): string
    {
        $key = $it['type'] === 'iinaCmd' ? 'iina' : ($l10n ?? $it['l10n']);
        $path = $key === null ? $it['name'] : "$key.{$it['name']}";
        $id = 'keyBinding' . implode('', array_map('ucfirst', preg_split('/[^A-Za-z0-9]+/', $path, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        return \MyPHP\L10n::catalog()->message('en', $id) !== null ? $c->texts()->get($id) : $it['name'];
    }

    /** The mpv command of the criteria chosen (KeyBindingTranslator.string(fromCriteria:)). @param list<array<string, mixed>> $criteria */
    private static function command(array $criteria): string
    {
        $mapped = [];
        foreach ($criteria as $cr) {
            if ($cr['type'] === 'placeholder') { continue; }
            $mapped[] = $cr['type'] === 'string' || $cr['type'] === 'number' ? $cr['value'] : $cr['name'];
        }
        $iina = $criteria[0]['type'] === 'iinaCmd';
        if ($iina) { array_unshift($mapped, '@iina'); }
        $name = $criteria[0]['name'];
        $pretty = fn(float $v): string => rtrim(rtrim(sprintf('%.6f', $v), '0'), '.') ?: '0';
        if ($name === 'add') { // add property add|minus value
            $v = array_pop($mapped);
            $sign = array_pop($mapped);
            if (is_numeric($v)) { $mapped[] = $pretty($sign === 'minus' ? -(float)$v : (float)$v); }
        } elseif ($name === 'seek' && count($mapped) >= 4) { // seek forward|backward|seek-to value flag
            if ($mapped[3] === 'relative') { array_pop($mapped); }
            if (is_numeric($mapped[2])) { $mapped[2] = $pretty($mapped[1] === 'backward' ? -(float)$mapped[2] : (float)$mapped[2]); }
            array_splice($mapped, 1, 1);
        } elseif ($name === 'cycle' && in_array($criteria[1]['name'] ?? '', self::IINA_TOGGLES, true)) {
            return '@iina toggle-' . $criteria[1]['name'];
        }
        return implode(' ', $mapped);
    }

    /** IINA's tree of commands (KeyBindingDataLoader.commands). @return list<array<string, mixed>> */
    private static function tree(): array
    {
        static $tree = null;
        if ($tree !== null) { return $tree; }
        $i = fn(string $name, string $type = 'label', array $children = [], ?string $l10n = null): array => ['name' => $name, 'type' => $type, 'children' => $children, 'l10n' => $l10n];
        $sep = fn(): array => $i('---', 'separator');
        $choose = fn(string $list, array $children = []): array => array_map(fn(string $o) => $i($o, 'label', $children), explode('|', $list));
        $props = function (Closure $filter, Closure $make) use ($i, $sep): array {
            $out = [];
            foreach (self::PROPERTIES as [$name, $kind]) {
                if ($name === '---') { $out[] = $sep(); continue; }
                if ($filter($kind)) { $out[] = $make($name, $kind); }
            }
            return $out;
        };
        $all = fn(string $k): bool => true;
        $set = $props($all, fn(string $n, string $k) => $i($n, 'label', [$i('to', 'placeholder', $k === 'bool' ? $choose('yes|no') : [$i('value', 'string')])], 'opt'));
        $numbers = fn(string $k): bool => $k === 'num';
        $multiply = $props($numbers, fn(string $n) => $i($n, 'label', [$i('by', 'placeholder', [$i('value', 'string')])], 'opt'));
        $add = $props($numbers, fn(string $n) => $i($n, 'label', $choose('add|minus', [$i('value', 'string')]), 'opt'));
        $cycle = [...$props(fn(string $k) => $k !== 'string', fn(string $n) => $i($n, 'label', [], 'opt')), $sep(), ...array_map(fn(string $p) => $i($p, 'iinaCmd', [], 'opt'), self::IINA_TOGGLES)];
        $values = $props(fn(string $k) => $k !== 'bool', fn(string $n) => $i($n, 'label', [$i('in', 'placeholder', [$i('value', 'string')])], 'opt'));
        $iina = fn(string $n) => $i($n, 'iinaCmd');
        return $tree = [
            $i('ignore'), $sep(),
            $i('seek', 'label', [...$choose('forward|backward', [$i('value', 'number', $choose('relative|relative-percent|relative+exact|relative-percent+exact'))]),
                ...$choose('seek-to', [$i('value', 'number', $choose('absolute|absolute-percent|absolute+keyframe|absolute-percent+keyframe'))])]),
            $i('frame-step'), $i('frame-back-step'), $i('ab-loop'), $sep(),
            $i('set', 'label', $set), $i('cycle', 'label', $cycle), $i('cycle-values', 'label', $values), $i('add', 'label', $add), $i('multiply', 'label', $multiply), $sep(),
            $i('playlist-next'), $i('playlist-prev'), $i('playlist-clear'), $i('playlist-remove'), $i('playlist-shuffle'), $sep(),
            $iina('video-panel'), $iina('audio-panel'), $iina('sub-panel'), $iina('playlist-panel'), $iina('chapter-panel'), $sep(),
            $iina('open-file'), $iina('open-url'), $iina('save-playlist'), $iina('show-current-file-in-finder'), $iina('delete-current-file'), $iina('delete-current-file-hard'), $sep(),
            $iina('find-online-subs'), $iina('save-downloaded-sub'), $sep(),
            $i('write-watch-later-config'), $i('stop'), $i('quit'),
        ];
    }
}
