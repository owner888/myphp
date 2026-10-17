<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

use MyPHP\UI\Key;

/**
 * The key bindings of code editors (mygo's keymap.go): Zed's on macOS,
 * the app's first, then the defaults.
 */
final class Keymap
{
    /** @var ?list<Binding> */
    private static ?array $defaults = null;

    /** @param list<Binding> $app the app's bindings, which replace the defaults for their keys */
    public function __construct(private readonly array $app = []) {}

    /** The default key bindings of code editors on macOS, as Zed's. @return list<Binding> */
    public static function defaults(): array
    {
        if (self::$defaults === null) {
            self::$defaults = [];
            foreach (self::MAC as [$mods, $key, $thenMods, $thenKey, $command]) { self::$defaults[] = new Binding($mods, $key, $thenMods, $thenKey, $command); }
            foreach ([Key::N1, Key::N2, Key::N3, Key::N4, Key::N5, Key::N6, Key::N7, Key::N8, Key::N9] as $i => $k) {
                self::$defaults[] = new Binding(Key::COMMAND, Key::K, Key::COMMAND, $k, Command::FOLD_LEVEL1 + $i);
            }
        }
        return self::$defaults;
    }

    /**
     * The binding of $mods+$key, after the first key of a chord when
     * $chord is set; [binding, prefix], prefix telling that the key
     * starts a chord.
     * @return array{0: ?Binding, 1: bool}
     */
    public function lookup(?Binding $chord, int $mods, int $key): array
    {
        $prefix = false;
        foreach ([$this->app, self::defaults()] as $list) {
            foreach ($list as $c) {
                if ($chord !== null) {
                    if ($c->thenKey !== null && $c->mods === $chord->mods && $c->key === $chord->key && $c->thenMods === $mods && $c->thenKey === $key) { return [$c, false]; }
                    continue;
                }
                if ($c->mods === $mods && $c->key === $key) {
                    if ($c->thenKey !== null) { $prefix = true; continue; }
                    return [$c, false];
                }
            }
            if ($prefix) { break; }
        }
        return [null, $prefix];
    }

    /** [mods, key, thenMods, thenKey, command] */
    private const MAC = [
            [0, Key::LEFT, 0, null, Command::MOVE_LEFT], [0, Key::RIGHT, 0, null, Command::MOVE_RIGHT], [0, Key::UP, 0, null, Command::MOVE_UP],
            [0, Key::DOWN, 0, null, Command::MOVE_DOWN], [Key::CONTROL, Key::B, 0, null, Command::MOVE_LEFT], [Key::CONTROL, Key::F, 0, null, Command::MOVE_RIGHT],
            [Key::CONTROL, Key::P, 0, null, Command::MOVE_UP], [Key::CONTROL, Key::N, 0, null, Command::MOVE_DOWN], [Key::OPTION, Key::LEFT, 0, null, Command::MOVE_WORD_LEFT],
            [Key::OPTION, Key::RIGHT, 0, null, Command::MOVE_WORD_RIGHT], [Key::CONTROL | Key::OPTION, Key::LEFT, 0, null, Command::MOVE_SUBWORD_LEFT],
            [Key::CONTROL | Key::OPTION, Key::RIGHT, 0, null, Command::MOVE_SUBWORD_RIGHT], [Key::COMMAND, Key::LEFT, 0, null, Command::MOVE_LINE_START],
            [0, Key::HOME, 0, null, Command::MOVE_LINE_START], [Key::CONTROL, Key::A, 0, null, Command::MOVE_LINE_START], [Key::COMMAND, Key::RIGHT, 0, null, Command::MOVE_LINE_END],
            [0, Key::END, 0, null, Command::MOVE_LINE_END], [Key::CONTROL, Key::E, 0, null, Command::MOVE_LINE_END], [Key::COMMAND, Key::UP, 0, null, Command::MOVE_DOC_START],
            [Key::COMMAND, Key::HOME, 0, null, Command::MOVE_DOC_START], [Key::COMMAND, Key::DOWN, 0, null, Command::MOVE_DOC_END], [Key::COMMAND, Key::END, 0, null, Command::MOVE_DOC_END],
            [Key::CONTROL, Key::UP, 0, null, Command::MOVE_PARAGRAPH_UP], [Key::CONTROL, Key::DOWN, 0, null, Command::MOVE_PARAGRAPH_DOWN],
            [0, Key::PAGE_UP, 0, null, Command::MOVE_PAGE_UP], [0, Key::PAGE_DOWN, 0, null, Command::MOVE_PAGE_DOWN],
            [Key::COMMAND | Key::SHIFT, Key::BACKSLASH, 0, null, Command::MOVE_TO_BRACKET], [Key::CONTROL, Key::M, 0, null, Command::MOVE_TO_BRACKET],
            [Key::SHIFT, Key::LEFT, 0, null, Command::SELECT_LEFT], [Key::SHIFT, Key::RIGHT, 0, null, Command::SELECT_RIGHT], [Key::SHIFT, Key::UP, 0, null, Command::SELECT_UP],
            [Key::SHIFT, Key::DOWN, 0, null, Command::SELECT_DOWN], [Key::OPTION | Key::SHIFT, Key::LEFT, 0, null, Command::SELECT_WORD_LEFT],
            [Key::OPTION | Key::SHIFT, Key::RIGHT, 0, null, Command::SELECT_WORD_RIGHT], [Key::CONTROL | Key::OPTION | Key::SHIFT, Key::LEFT, 0, null, Command::SELECT_SUBWORD_LEFT],
            [Key::CONTROL | Key::OPTION | Key::SHIFT, Key::RIGHT, 0, null, Command::SELECT_SUBWORD_RIGHT], [Key::COMMAND | Key::SHIFT, Key::LEFT, 0, null, Command::SELECT_LINE_START],
            [Key::SHIFT, Key::HOME, 0, null, Command::SELECT_LINE_START], [Key::COMMAND | Key::SHIFT, Key::RIGHT, 0, null, Command::SELECT_LINE_END],
            [Key::SHIFT, Key::END, 0, null, Command::SELECT_LINE_END], [Key::COMMAND | Key::SHIFT, Key::UP, 0, null, Command::SELECT_DOC_START],
            [Key::COMMAND | Key::SHIFT, Key::HOME, 0, null, Command::SELECT_DOC_START], [Key::COMMAND | Key::SHIFT, Key::DOWN, 0, null, Command::SELECT_DOC_END],
            [Key::COMMAND | Key::SHIFT, Key::END, 0, null, Command::SELECT_DOC_END], [Key::CONTROL | Key::SHIFT, Key::UP, 0, null, Command::SELECT_PARAGRAPH_UP],
            [Key::CONTROL | Key::SHIFT, Key::DOWN, 0, null, Command::SELECT_PARAGRAPH_DOWN], [Key::SHIFT, Key::PAGE_UP, 0, null, Command::SELECT_PAGE_UP],
            [Key::SHIFT, Key::PAGE_DOWN, 0, null, Command::SELECT_PAGE_DOWN], [Key::COMMAND, Key::A, 0, null, Command::SELECT_ALL], [Key::COMMAND, Key::L, 0, null, Command::SELECT_LINE],
            [Key::COMMAND, Key::D, 0, null, Command::SELECT_NEXT], [Key::CONTROL | Key::COMMAND, Key::D, 0, null, Command::SELECT_PREVIOUS],
            [Key::COMMAND | Key::SHIFT, Key::L, 0, null, Command::SELECT_ALL_MATCHES], [Key::COMMAND, Key::F2, 0, null, Command::SELECT_ALL_MATCHES],
            [Key::COMMAND | Key::OPTION, Key::UP, 0, null, Command::ADD_CURSOR_ABOVE], [Key::COMMAND | Key::OPTION, Key::DOWN, 0, null, Command::ADD_CURSOR_BELOW],
            [Key::CONTROL | Key::SHIFT, Key::RIGHT, 0, null, Command::SELECT_LARGER], [Key::CONTROL | Key::COMMAND, Key::RIGHT, 0, null, Command::SELECT_LARGER],
            [Key::CONTROL | Key::SHIFT, Key::LEFT, 0, null, Command::SELECT_SMALLER], [Key::CONTROL | Key::COMMAND, Key::LEFT, 0, null, Command::SELECT_SMALLER],
            [Key::COMMAND, Key::U, 0, null, Command::UNDO_SELECTION], [Key::COMMAND | Key::SHIFT, Key::U, 0, null, Command::REDO_SELECTION], [0, Key::ESCAPE, 0, null, Command::CANCEL],
            [Key::COMMAND, Key::Z, 0, null, Command::UNDO], [Key::COMMAND | Key::SHIFT, Key::Z, 0, null, Command::REDO], [Key::COMMAND, Key::X, 0, null, Command::CUT], [Key::COMMAND, Key::C, 0, null, Command::COPY],
            [Key::COMMAND, Key::V, 0, null, Command::PASTE], [0, Key::DELETE, 0, null, Command::BACKSPACE], [Key::SHIFT, Key::DELETE, 0, null, Command::BACKSPACE],
            [Key::CONTROL, Key::H, 0, null, Command::BACKSPACE], [0, Key::FORWARD_DELETE, 0, null, Command::DELETE], [Key::CONTROL, Key::D, 0, null, Command::DELETE],
            [Key::OPTION, Key::DELETE, 0, null, Command::DELETE_WORD_LEFT], [Key::CONTROL, Key::W, 0, null, Command::DELETE_WORD_LEFT],
            [Key::OPTION, Key::FORWARD_DELETE, 0, null, Command::DELETE_WORD_RIGHT], [Key::CONTROL | Key::OPTION, Key::DELETE, 0, null, Command::DELETE_SUBWORD_LEFT],
            [Key::CONTROL | Key::OPTION, Key::FORWARD_DELETE, 0, null, Command::DELETE_SUBWORD_RIGHT], [Key::COMMAND, Key::DELETE, 0, null, Command::DELETE_TO_LINE_START],
            [Key::COMMAND, Key::FORWARD_DELETE, 0, null, Command::DELETE_TO_LINE_END], [Key::CONTROL, Key::K, 0, null, Command::CUT_TO_LINE_END],
            [Key::COMMAND | Key::SHIFT, Key::K, 0, null, Command::DELETE_LINE], [0, Key::RETURN, 0, null, Command::NEWLINE], [Key::SHIFT, Key::RETURN, 0, null, Command::NEWLINE],
            [Key::COMMAND, Key::RETURN, 0, null, Command::NEWLINE_BELOW], [Key::COMMAND | Key::SHIFT, Key::RETURN, 0, null, Command::NEWLINE_ABOVE],
            [Key::OPTION, Key::UP, 0, null, Command::MOVE_LINES_UP], [Key::OPTION, Key::DOWN, 0, null, Command::MOVE_LINES_DOWN],
            [Key::OPTION | Key::SHIFT, Key::UP, 0, null, Command::DUPLICATE_LINES_UP], [Key::OPTION | Key::SHIFT, Key::DOWN, 0, null, Command::DUPLICATE_LINES_DOWN],
            [Key::CONTROL, Key::J, 0, null, Command::JOIN_LINES], [Key::CONTROL, Key::T, 0, null, Command::TRANSPOSE], [Key::COMMAND, Key::BRACKET_RIGHT, 0, null, Command::INDENT],
            [Key::COMMAND, Key::BRACKET_LEFT, 0, null, Command::OUTDENT], [0, Key::TAB, 0, null, Command::TAB], [Key::SHIFT, Key::TAB, 0, null, Command::BACKTAB],
            [Key::COMMAND, Key::SLASH, 0, null, Command::TOGGLE_COMMENT], [Key::COMMAND, Key::K, Key::COMMAND, Key::SLASH, Command::TOGGLE_BLOCK_COMMENT],
            [Key::COMMAND | Key::OPTION, Key::BRACKET_LEFT, 0, null, Command::FOLD], [Key::COMMAND | Key::OPTION, Key::BRACKET_RIGHT, 0, null, Command::UNFOLD],
            [Key::COMMAND, Key::K, Key::COMMAND, Key::L, Command::TOGGLE_FOLD], [Key::COMMAND, Key::K, Key::COMMAND, Key::N0, Command::FOLD_ALL], [Key::COMMAND, Key::K, Key::COMMAND, Key::J, Command::UNFOLD_ALL],
            [Key::COMMAND, Key::K, Key::COMMAND, Key::BRACKET_LEFT, Command::FOLD_RECURSIVE], [Key::COMMAND, Key::K, Key::COMMAND, Key::BRACKET_RIGHT, Command::UNFOLD_RECURSIVE],
            [Key::COMMAND, Key::F, 0, null, Command::FIND], [Key::COMMAND | Key::OPTION, Key::F, 0, null, Command::FIND_REPLACE], [Key::COMMAND | Key::SHIFT, Key::H, 0, null, Command::FIND_REPLACE],
            [Key::COMMAND, Key::G, 0, null, Command::FIND_NEXT], [Key::COMMAND | Key::SHIFT, Key::G, 0, null, Command::FIND_PREVIOUS], [Key::COMMAND, Key::E, 0, null, Command::USE_SELECTION_FOR_FIND],
            [Key::COMMAND | Key::OPTION, Key::L, 0, null, Command::FIND_IN_SELECTION], [Key::CONTROL, Key::G, 0, null, Command::GO_TO_LINE],
            [Key::CONTROL, Key::L, 0, null, Command::SCROLL_CURSOR_CENTER], [Key::CONTROL, Key::PAGE_UP, 0, null, Command::SCROLL_LINE_UP],
            [Key::CONTROL, Key::PAGE_DOWN, 0, null, Command::SCROLL_LINE_DOWN], [0, Key::F12, 0, null, Command::GO_TO_DEFINITION],
            [Key::CONTROL, Key::SPACE, 0, null, Command::SHOW_COMPLETIONS], [Key::COMMAND, Key::K, Key::COMMAND, Key::I, Command::SHOW_HOVER], [Key::COMMAND, Key::F8, 0, null, Command::NEXT_HUNK],
            [Key::COMMAND | Key::SHIFT, Key::F8, 0, null, Command::PREVIOUS_HUNK], [Key::COMMAND | Key::OPTION, Key::Z, 0, null, Command::REVERT_HUNK],
            [Key::COMMAND, Key::K, 0, Key::Z, Command::TOGGLE_SOFT_WRAP], [Key::COMMAND, Key::SEMICOLON, 0, null, Command::TOGGLE_LINE_NUMBERS],
    ];
}
