# Diff viewer

`DiffView($diff)` shows two versions side by side or unified, taking the
room it is given: `$diff` a `DiffState` the app keeps.

<!-- no-test -->
```php
$view = fn() => DiffView($this->diff);
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](custom-widgets.md)).

`MyPHP\UI\Editor\DiffState` shows the changes between two texts, as
Pierre's diffs and code review tools do: side by side or one over the
other, with the words changed within lines highlighted, the unchanged
lines between changes collapsed, and the new text editable where it shows.
It is a [code editor](code-editor.md) on each side, with the editor's
highlighting, selections and commands, laid out and painted only where in
view.

```php
use MyPHP\UI\Editor\{Buffer, DiffState, Languages};

$lang = Languages::for('main.php');
[$before, $after] = [new Buffer("<?php\necho 'a';\necho 'b';\n"), new Buffer("<?php\necho 'a';\necho 'c';\n")];
$before->setLanguage($lang);
$after->setLanguage($lang);
$diff = new DiffState($before, $after);
$diff->editable = true;

$view = function (Context $c) use ($diff) {
    $diff->view($c)->grow(1)->fillWidth();
};
```

A `DiffState` is the viewer's state, kept across frames: the buffers it
compares (`old` and `new`), its options, and what it keeps of its own, as
the lines expanded and its scroll position. Its defaults are those of
Pierre's diffs.

The diff follows the buffers: an edit in either shows at once, the viewer
diffing again only the lines around it. `new` can be the buffer of an
editor too, as a file's, so that the diff shows its changes as they are
typed.

## Layout

- `layout` shows the old text on the left and the new on the right, their
  lines aligned (`DiffLayout::DiffSplit`), the two scrolling together; or
  the new text, with the lines deleted above where they were
  (`DiffLayout::DiffUnified`).
- `inline` highlights what changed within changed lines: words, joining
  those one character apart so that a phrase reads as one change
  (`DiffInline::DiffInlineWordAlt`, the default), each word
  (`DiffInlineWord`), from the first word to the last (`DiffInlineWordLine`),
  characters (`DiffInlineChar`), or nothing (`DiffInlineNone`).
- `indicators` marks changed lines with bars at the gutter's edge, striped
  for deletions (`DiffIndicators::DiffBars`), with + and − before them
  (`DiffClassic`), or by their background alone (`DiffNoIndicators`).
  `noBackground` leaves out the tints of changed lines, and
  `noLineNumbers` the numbers.
- `contextLines` unchanged lines show around each change, 3 by default.
  The others collapse into separators of "N unmodified lines", whose
  buttons show `expansionLines` more at a click (100 by default), and all
  of them with Shift; `expandUnchanged`, `expandAll()` and `collapseAll()`
  show or collapse them all.
- `wrap` wraps long lines, rows of each side keeping their partners
  aligned; `fitContent` makes the viewer as high as its lines, as the files
  of a review in a scroll view.
- `fontSize`, `fontStyle`, `lineHeight`, `tabSize` and `theme` are those of
  the code editor. Changes are tinted with the theme's `added`, `deleted`
  and `modified` colors, mixed with the background in a perceptual color
  space so that they read alike in light and dark.

Lines are compared as git's histogram diff compares them, with Myers' for
what it cannot split, and changes slide to where git's indent heuristic
puts them, so that a function added shows as that function rather than
its last lines and the next one's first. Changed lines are paired with
those most like them for highlighting words, rather than in order.

## Editing and review

With `editable`, the new side edits as the code editor does: typing,
several carets, undo, finding and every command, the diff following each
keystroke. Cmd+F8 and Shift+Cmd+F8 go to the next and previous changes,
and `goToHunk()` goes to one.

- `hunkActions` shows Revert and Keep on the change under the pointer, or
  the caret's: Revert puts the old lines back in `new`, Keep makes the new
  lines `old`'s, as an agent's changes are reviewed one at a time.
  `revert($i)` and `accept($i)` do the same from the app; `hunks()` lists
  the changes, and `stats()` counts the lines added and deleted.
- `lineSelection` lets the numbers of lines be clicked, and dragged or
  Shift-clicked, to select lines, which `onLineSelection` reports and
  `selectedLines()` and `setSelectedLines()` read and set, as a link to
  lines of a review does.
- `onGutterButton` shows a + on the gutter of the line under the pointer,
  as to comment on it, and is called with the line when it is clicked.
- `annotations` leave room under lines for what `renderAnnotation` builds
  there, as the comments of a review, each as high as it asks; the other
  side of a split view leaves the room empty, so that the lines stay
  aligned.

```php
use MyPHP\UI\Editor\{Buffer, DiffAnnotation, DiffLineRange, DiffState};

$app = new class {
    public DiffState $diff;
    public array $comments = [];
    public function __construct() { $this->diff = new DiffState(new Buffer("a\nb\n"), new Buffer("a\nc\n")); }
};
$app->diff->hunkActions = true;
$app->diff->onGutterButton = function (DiffLineRange $r) use ($app) {
    $app->comments[] = ['side' => $r->side, 'line' => $r->end];
};
$app->diff->renderAnnotation = function (Context $c, DiffAnnotation $a) use ($app) {
    $c->text('Comment ' . $a->key);
};
$view = function (Context $c) use ($app) {
    $app->diff->annotations = [];
    foreach ($app->comments as $i => $cm) {
        $app->diff->annotations[] = new DiffAnnotation($cm['side'], $cm['line'], 72, $i);
    }
    $app->diff->view($c)->grow(1);
};
```

`activeSide()` is the side that has the keyboard, or had it last: the new
side until the old one of a split view has it. `cursor()` and `cursorPos()`
return the offset and the position of its newest caret, as the code
editor's do, and `currentHunk()` the change the caret is in, with
`inside` true, or else the last before it, -1 before the first, as "2 of
5" counts them; `goToHunk()` moves that caret, so Next Change can step
from it. `run()` runs a command in its editor as its key would, and
`find()` opens its find bar on a query, as an app's menu items do where the
menu takes the keys first:

```php
use MyPHP\UI\Editor\{Buffer, Command, DiffState};

$diff = new DiffState(new Buffer("a\nb\nc\n"), new Buffer("a\nB\nc\nd\n"));
$view = function (Context $c) use ($diff) {
    if ($c->clicked($c->button('Find'))) {
        $diff->run(Command::FIND);
    }
    [$i, $inside] = $diff->currentHunk();
    $n = count($diff->hunks());
    $c->text($i >= 0 ? sprintf('%d of %d', $i + 1, $n) : sprintf('%d changes', $n)); // before the first
    if ($c->clicked($c->button('Next change'))) {
        $diff->goToHunk($i + 1);
    }
    if ($inside && $c->clicked($c->button('Revert this change'))) {
        $diff->revert($i);
    }
    $diff->view($c)->grow(1);
};
```

## Styling

`theme` colors the diff as it does the [code
editor](code-editor.md#themes), changed lines included. The parts around
the lines are the app's to build, as Pierre's diffs render them:
`renderSeparator`, `renderHunkActions` and `renderGutterButton` are given
the node the viewer places, open, and what the part shows and does, and
the viewer's own looks are `DiffSeparator::default()`,
`DiffHunkActions::default()` and `DiffState::defaultGutterButton()`, which
they can call. `renderFindBar` and `renderGoToLine` build the bars of the
sides' editors, as the code editor's do.

```php
use MyPHP\UI\Editor\{Buffer, DiffHunkActions, DiffSeparator, DiffState};

$diff = new DiffState(new Buffer(implode("\n", range(1, 40)) . "\n"), new Buffer(implode("\n", range(1, 39)) . "\nforty\n"));
$diff->renderSeparator = function (Context $c, Node $sep, DiffSeparator $s) {
    $sep->padding(4);
    if ($s->lines === 0) { // a patch's hunk
        $c->text($s->header)->font('monospace');
        return;
    }
    if ($c->clicked($c->button(sprintf('Show %d hidden lines', $s->lines)))) {
        $s->expandAll();
    }
};
$diff->renderHunkActions = function (Context $c, Node $bar, DiffHunkActions $a) {
    $bar->gap(4);
    if ($c->clicked($c->button('Reject'))) {
        $a->revert();
    }
    if ($c->clicked($c->primaryButton('Accept'))) {
        $a->keep();
    }
};
$view = fn(Context $c) => $diff->view($c)->grow(1);
```

- A `DiffSeparator` spans the viewer, both sides of a split one, 32 points
  high. It collapses `lines` unchanged lines, which `expandUp()`,
  `expandDown()` and `expandAll()` show, `step` lines at a time; `first`
  and `last` mark those before the first change and after the last, which
  expand one way. A patch's hunk has a separator of no lines, with its
  `header`.
- `DiffHunkActions` sit at the top right of the change under the pointer,
  or the caret's: `index` and `hunk` say which, and `revert()` and `keep()`
  act.
- The gutter button is a button on the gutter of the line under the
  pointer, which calls `onGutterButton` when clicked; its render function
  gives it a look and a label.

## Patches

`FilePatch::parse()` reads the files a unified diff changes, as `git diff`
and `diff -u` print them, with renames, modes and new and deleted files;
`apply()` applies one to the old file's text. `setPatch()` shows a file's
patch without the files, as a pull request's diff arrives: its hunks'
lines, numbered as in the files, each hunk under its header.

```php
use MyPHP\UI\Editor\{DiffState, FilePatch};

$output = "--- a/x.txt\n+++ b/x.txt\n@@ -1,2 +1,2 @@\n a\n-b\n+c\n";
$files = [];
foreach (FilePatch::parse($output) as $f) { // of git diff
    $d = new DiffState();
    $d->setPatch($f);
    $files[] = $d;
}
```

## Accessibility

Assistive technology sees each side as a text field, "Old text" and "New
text", the old one read only, with its text and selection. A separator is a
group named by the lines it collapses, with buttons expanding them up, down
or all; Revert and Keep are buttons, "Revert change" and "Keep change".
These names, and what the viewer writes ("4 unmodified lines"), are in the
window's language ([MyPHP's own strings](../i18n.md#myphps-own-strings)).
