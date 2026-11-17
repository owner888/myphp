# Diff viewer

`DiffView($diff)` shows the differences between two `Buffer`s side by side or unified, taking the room it is given:

```php
use MyPHP\UI\Editor\{Buffer, DiffLayout, DiffState, Languages};

$lang = Languages::for('main.php');
$before = new Buffer("<?php\necho 'a';\necho 'b';\n");
$after = new Buffer("<?php\necho 'a';\necho 'c';\n");
$before->setLanguage($lang);
$after->setLanguage($lang);

$diff = new DiffState($before, $after);
$diff->layout = DiffLayout::DiffSplit;
$diff->editable = true;

$view = fn() => DiffView($diff)->frame(height: 240);
```

A `DiffState` is kept across frames in your state object. Because `new` is an `Editor\Buffer`, it can be the same buffer a [`CodeEditor`](code-editor.md) edits: typing in the editor updates the diff incrementally around the edited lines.

## Layout and options

- **`layout`** — `DiffLayout::DiffSplit` (side by side, scrolling together) or `DiffLayout::DiffUnified` (single column with deletions above additions).
- **`inline`** — highlights changed words or characters within modified lines (`DiffInline::DiffInlineWordAlt`, `DiffInlineWord`, `DiffInlineChar`, `DiffInlineNone`).
- **`indicators`** — `DiffIndicators::DiffBars` (colored bars in the gutter, striped for deletions), `DiffClassic` (`+` and `-` signs), or `DiffNoIndicators`.
- **`contextLines`** — unchanged lines shown around each hunk (`3` by default); the rest collapse into expandable separator bars (`expandAll()` / `collapseAll()`).
- **`editable`** — allows editing the new buffer directly inside the diff view, with per-hunk Revert and Keep actions when `hunkActions` is enabled.
