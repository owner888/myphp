<?php
declare(strict_types=1);

namespace MyPHP\UI\Editor;

/**
 * Commands of code editors, as Zed's editor actions (mygo's
 * editor.Command): what a key binding runs, `$state->run(Command::TOGGLE_COMMENT)`.
 */
final class Command
{
    public const NO_COMMAND = 0;
    // Moving the carets, and with SELECT_*, extending the selections.
    public const MOVE_LEFT = 1;
    public const MOVE_RIGHT = 2;
    public const MOVE_UP = 3;
    public const MOVE_DOWN = 4;
    public const MOVE_WORD_LEFT = 5;
    public const MOVE_WORD_RIGHT = 6;
    public const MOVE_SUBWORD_LEFT = 7;
    public const MOVE_SUBWORD_RIGHT = 8;
    public const MOVE_LINE_START = 9;
    public const MOVE_LINE_END = 10;
    public const MOVE_DOC_START = 11;
    public const MOVE_DOC_END = 12;
    public const MOVE_PARAGRAPH_UP = 13;
    public const MOVE_PARAGRAPH_DOWN = 14;
    public const MOVE_PAGE_UP = 15;
    public const MOVE_PAGE_DOWN = 16;
    public const MOVE_TO_BRACKET = 17;
    public const SELECT_LEFT = 18;
    public const SELECT_RIGHT = 19;
    public const SELECT_UP = 20;
    public const SELECT_DOWN = 21;
    public const SELECT_WORD_LEFT = 22;
    public const SELECT_WORD_RIGHT = 23;
    public const SELECT_SUBWORD_LEFT = 24;
    public const SELECT_SUBWORD_RIGHT = 25;
    public const SELECT_LINE_START = 26;
    public const SELECT_LINE_END = 27;
    public const SELECT_DOC_START = 28;
    public const SELECT_DOC_END = 29;
    public const SELECT_PARAGRAPH_UP = 30;
    public const SELECT_PARAGRAPH_DOWN = 31;
    public const SELECT_PAGE_UP = 32;
    public const SELECT_PAGE_DOWN = 33;
    public const SELECT_TO_BRACKET = 34;
    // Selections.
    public const SELECT_ALL = 35;
    public const SELECT_LINE = 36;
    public const SELECT_NEXT = 37;
    public const SELECT_PREVIOUS = 38;
    public const SELECT_ALL_MATCHES = 39;
    public const ADD_CURSOR_ABOVE = 40;
    public const ADD_CURSOR_BELOW = 41;
    public const SELECT_LARGER = 42;
    public const SELECT_SMALLER = 43;
    public const UNDO_SELECTION = 44;
    public const REDO_SELECTION = 45;
    public const SPLIT_SELECTION_INTO_LINES = 46;
    public const CANCEL = 47;
    // Editing.
    public const UNDO = 48;
    public const REDO = 49;
    public const CUT = 50;
    public const COPY = 51;
    public const PASTE = 52;
    public const BACKSPACE = 53;
    public const DELETE = 54;
    public const DELETE_WORD_LEFT = 55;
    public const DELETE_WORD_RIGHT = 56;
    public const DELETE_SUBWORD_LEFT = 57;
    public const DELETE_SUBWORD_RIGHT = 58;
    public const DELETE_TO_LINE_START = 59;
    public const DELETE_TO_LINE_END = 60;
    public const CUT_TO_LINE_END = 61;
    public const DELETE_LINE = 62;
    public const NEWLINE = 63;
    public const NEWLINE_ABOVE = 64;
    public const NEWLINE_BELOW = 65;
    public const MOVE_LINES_UP = 66;
    public const MOVE_LINES_DOWN = 67;
    public const DUPLICATE_LINES_UP = 68;
    public const DUPLICATE_LINES_DOWN = 69;
    public const DUPLICATE_SELECTION = 70;
    public const JOIN_LINES = 71;
    public const TRANSPOSE = 72;
    public const INDENT = 73;
    public const OUTDENT = 74;
    public const TAB = 75;
    public const BACKTAB = 76;
    public const TOGGLE_COMMENT = 77;
    public const TOGGLE_BLOCK_COMMENT = 78;
    public const SORT_LINES = 79;
    public const SORT_LINES_CASE_INSENSITIVE = 80;
    public const REVERSE_LINES = 81;
    public const UNIQUE_LINES = 82;
    public const UPPERCASE = 83;
    public const LOWERCASE = 84;
    public const TITLE_CASE = 85;
    public const SNAKE_CASE = 86;
    public const KEBAB_CASE = 87;
    public const CAMEL_CASE = 88;
    public const PASCAL_CASE = 89;
    public const TRIM_TRAILING_WHITESPACE = 90;
    public const FOLD = 91;
    public const UNFOLD = 92;
    // Folding.
    public const TOGGLE_FOLD = 93;
    public const FOLD_ALL = 94;
    public const UNFOLD_ALL = 95;
    public const FOLD_RECURSIVE = 96;
    public const UNFOLD_RECURSIVE = 97;
    public const FOLD_SELECTION = 98;
    public const FOLD_LEVEL1 = 99;
    public const FOLD_LEVEL2 = 100;
    public const FOLD_LEVEL3 = 101;
    public const FOLD_LEVEL4 = 102;
    public const FOLD_LEVEL5 = 103;
    public const FOLD_LEVEL6 = 104;
    public const FOLD_LEVEL7 = 105;
    public const FOLD_LEVEL8 = 106;
    public const FOLD_LEVEL9 = 107;
    public const FIND = 108;
    public const FIND_REPLACE = 109;
    // Finding.
    public const FIND_NEXT = 110;
    public const FIND_PREVIOUS = 111;
    public const USE_SELECTION_FOR_FIND = 112;
    public const FIND_IN_SELECTION = 113;
    public const GO_TO_LINE = 114;
    public const SCROLL_CURSOR_CENTER = 115;
    // Going places.
    public const SCROLL_LINE_UP = 116;
    public const SCROLL_LINE_DOWN = 117;
    public const GO_TO_DEFINITION = 118;
    public const SHOW_COMPLETIONS = 119;
    public const SHOW_HOVER = 120;
    public const NEXT_HUNK = 121;
    public const PREVIOUS_HUNK = 122;
    // Changes against the diff base, or the hunks of a diff.
    public const REVERT_HUNK = 123;
    public const TOGGLE_SOFT_WRAP = 124;
    public const TOGGLE_LINE_NUMBERS = 125;
    public const COUNT = 126;

    /** The commands' names, as "toggleComment", by command. */
    private const NAMES = [
        'none', 'moveLeft', 'moveRight', 'moveUp', 'moveDown', 'moveWordLeft', 'moveWordRight', 'moveSubwordLeft',
        'moveSubwordRight', 'moveLineStart', 'moveLineEnd', 'moveDocStart', 'moveDocEnd', 'moveParagraphUp', 'moveParagraphDown', 'movePageUp',
        'movePageDown', 'moveToBracket', 'selectLeft', 'selectRight', 'selectUp', 'selectDown', 'selectWordLeft', 'selectWordRight',
        'selectSubwordLeft', 'selectSubwordRight', 'selectLineStart', 'selectLineEnd', 'selectDocStart', 'selectDocEnd', 'selectParagraphUp', 'selectParagraphDown',
        'selectPageUp', 'selectPageDown', 'selectToBracket', 'selectAll', 'selectLine', 'selectNext', 'selectPrevious', 'selectAllMatches',
        'addCursorAbove', 'addCursorBelow', 'selectLarger', 'selectSmaller', 'undoSelection', 'redoSelection', 'splitSelectionIntoLines', 'cancel',
        'undo', 'redo', 'cut', 'copy', 'paste', 'backspace', 'delete', 'deleteWordLeft',
        'deleteWordRight', 'deleteSubwordLeft', 'deleteSubwordRight', 'deleteToLineStart', 'deleteToLineEnd', 'cutToLineEnd', 'deleteLine', 'newline',
        'newlineAbove', 'newlineBelow', 'moveLinesUp', 'moveLinesDown', 'duplicateLinesUp', 'duplicateLinesDown', 'duplicateSelection', 'joinLines',
        'transpose', 'indent', 'outdent', 'tab', 'backtab', 'toggleComment', 'toggleBlockComment', 'sortLines',
        'sortLinesCaseInsensitive', 'reverseLines', 'uniqueLines', 'uppercase', 'lowercase', 'titleCase', 'snakeCase', 'kebabCase',
        'camelCase', 'pascalCase', 'trimTrailingWhitespace', 'fold', 'unfold', 'toggleFold', 'foldAll', 'unfoldAll',
        'foldRecursive', 'unfoldRecursive', 'foldSelection', 'foldLevel1', 'foldLevel2', 'foldLevel3', 'foldLevel4', 'foldLevel5',
        'foldLevel6', 'foldLevel7', 'foldLevel8', 'foldLevel9', 'find', 'findReplace', 'findNext', 'findPrevious',
        'useSelectionForFind', 'findInSelection', 'goToLine', 'scrollCursorCenter', 'scrollLineUp', 'scrollLineDown', 'goToDefinition', 'showCompletions',
        'showHover', 'nextHunk', 'previousHunk', 'revertHunk', 'toggleSoftWrap', 'toggleLineNumbers',
    ];

    /** The command's name, as "toggleComment". */
    public static function name(int $c): string
    {
        return self::NAMES[$c] ?? 'unknown';
    }

    /** The command of a name name() returns, or null. */
    public static function named(string $name): ?int
    {
        $i = array_search($name, self::NAMES, true);
        return $i === false ? null : $i;
    }
}
