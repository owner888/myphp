# Native UI

A window can show a user interface that MyPHP draws itself instead of a web
page. You write it in PHP with the classes of `MyPHP\UI`: there is no
HTML, no JavaScript and no frontend build, and the window starts no
webview, so it opens at once and uses little memory. MyPHP draws it on the
GPU, with Metal on macOS and OpenGL on Linux, reaching the system through
`ext-ffi` alone.

<!-- no-test -->
```php
<?php
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\{Content, Context};

require __DIR__ . '/vendor/autoload.php';

/** The app's state, made once. */
final class Counter
{
    public int $n = 0;

    /** Builds the window's interface from the state, for every frame. */
    public function view(Context $c): void
    {
        $c->column(gap: 12)->fill()->center();
          $c->text((string)$this->n)->fontSize(40)->bold();
          if ($c->clicked($c->primaryButton('Increment'))) {
              $this->n++;
          }
        $c->end();
    }
}

$app = new Counter();
App::whenReady(function () use ($app) {
    new Window(new WindowOptions(
        title: 'Counter',
        width: 320,
        height: 240,
        content: Content::view(fn(Context $c) => $app->view($c)),
    ));
});
App::run();
```

**Reading the examples.** The examples in these guides are parts of a view
such as `Counter::view`: `$c` is the view's `Context`. Containers
(`$c->column()`, `$c->row()`, `$c->scroll()`…) take what is built after
them until `$c->end()`; every builder returns its `Node`, which calls
after it style (`->padding(8)->radius(6)`), and `$c->clicked($node)`,
`$c->changed($node)` and the like ask what the user did to it. Widgets
that edit a value take it by reference (`$c->checkbox('Sync',
$app->sync)`), and text widgets a `TextState`. State lives outside the
view, made once: the examples make it first, as an object `$app` of an
anonymous class, and give it to the view with `use`; a `$view` closure is
what `Content::view()` takes. Every example on these pages runs, headless,
in `tests/docs_examples_test.php`; those marked `<!-- no-test -->` need a
window.

`myphp init -template native my-app` starts a native UI project (see
[the CLI](../cli.md#myphp-init)). The [counter
example](../../examples/counter-native) has a headless test in
`tests/examples_test.php`, and the [widgets](../../examples/widgets) and
[gallery](../../examples/gallery) examples show the controls and layouts.

One app can have windows of both kinds. Native UI suits tools, settings,
inspectors and utilities, and apps that must start instantly; a web page
suits rich documents, existing web code and anything that needs what only
a browser has. Screen readers and other assistive technology read native
UI as they read other apps (see [Accessibility](accessibility.md)).

## Guides

- [Views](views.md): build lifetimes, event queries and callbacks, keys,
  and changing the state from outside the view.
- [Layout](layout.md): rows and columns, sizes, spacing, alignment and
  positioning.
- [Text](text.md): text and its style, rich text, links within sentences,
  and fonts.
- [Styling and themes](styling.md): backgrounds, borders, shadows and
  gradients, and the theme that follows the desktop.
- [Input](input.md): the pointer, the keyboard focus, shortcuts, input
  methods, dropped files, and widgets that take every key.
- [Drag and drop](drag-and-drop.md): values dragged within the window, rows
  reordered by dragging, and data exchanged with other apps.
- [Pages and navigation](navigation.md): a router of pages with a history,
  layouts, transitions, and going back and forward.
- [Overlays](overlays.md): what dialogs, popovers and the layer above the
  window share: the focus, Escape, and what is behind them.
- [Accessibility](accessibility.md): what screen readers see, naming
  nodes, roles, states and announcements.
- [Right to left](right-to-left.md): interfaces laid out from the right for
  Arabic and Hebrew, logical edges, and what mirrors.
- [Languages](../i18n.md#native-ui): the window's language, and the
  controls' own strings.
- [Drawing and animation](drawing.md): painting on nodes, canvases,
  shaping text, and values that ease to their targets.
- [Shaders](shaders.md): pixels computed on the GPU by shaders written in
  Go's syntax, with values and images the app passes them.
- [Transitions](transitions.md): nodes that move, resize and recolor
  smoothly as the layout changes, and come and go.
- [Motion](motion.md): springs that keep their velocity, curves, delays,
  values thrown by the pointer, keyframes, timelines, and knowing when
  they are done.
- [Transforms](transforms.md): visual translation, scaling and rotation of
  any node, which the pointer, text input and accessibility follow.
- [Custom widgets](custom-widgets.md): the widgets without a look that
  every widget is built on, and widgets of your own.
- [Windows with native UI](windows.md): window options, title bars drawn
  by the view, zooming the content, and vibrancy.
- [Testing](testing.md): running a view without a window, as fast as a
  unit test.
- [Inspector](../inspector.md): the nodes of a window, their boxes and
  styles, and the time frames take, beside the content.
- [Rendering](rendering.md): how MyPHP draws, and the frame statistics.
- [Performance](performance.md): measured frame costs, and keeping frames
  cheap.

## Actions

- [Button](button.md): a push button, plain or in the accent color.
- [Link](link.md): text that opens a URL, or a page of a router.
- [Menu button](menu-button.md): a button opening a menu below it, the
  system's or one MyPHP draws in the window with the theme's look.
- [Context menu](context-menu.md): a menu of the system's for any node, on
  a right click, or one MyPHP draws in the window with the theme's look.
- [Toggle](toggle.md): a button that stays pressed, and toggles joined in a
  group.
- [Segmented control](segmented.md): segments choosing one of a few views.
- [Toolbar](toolbar.md): a row of controls along a window, which overflow
  into a menu.

## Choices

- [Checkbox](checkbox.md): a check box, and a check box over a group of
  others.
- [Switch](switch.md): a switch that turns a setting on and off.
- [Radio](radio.md): radio buttons choosing one value, in a group.
- [Select](select.md): a drop-down choosing one of a list.
- [Slider](slider.md): a slider setting a value in a range, with or without
  steps.
- [Range slider](range-slider.md): two knobs setting a low and a high value.
- [Stepper](stepper.md): arrows stepping a value up and down.
- [Rating](rating.md): stars setting a rating.

## Text input

- [Text input](text-input.md): text on one line or several, with
  selection, undo, the clipboard and input methods.
- [Text-input primitives](text-input-client.md): application-owned text,
  native composition and queries, and retained text geometry for custom
  controls.
- [Number input](number-input.md): a number in a range, typed or stepped.
- [Decimal input](decimal-input.md): an exact decimal, as an amount of
  money, typed or stepped, never rounded.
- [Search field](search-field.md): a field for searching, which Escape
  clears.
- [Combobox](combobox.md): a field choosing one of a list, which typing
  filters.
- [Autocomplete](autocomplete.md): a text input suggesting what was typed.
- [Token field](token-field.md): a list of strings as chips, as tags or
  recipients.
- [Editable text](editable-text.md): a text renamed in place, as a file's
  name in Finder.
- [Find bar](find-bar.md): a bar for finding text, with the count of
  matches and the next and previous ones.
- [Rich text editor](rich-text-editor.md): attributed text with formats,
  links, headings, lists, tasks and images, the shortcuts and Format menu
  of word processors, rich copy and paste, and Markdown.

## Code

- [Code editor](code-editor.md): an editor of code as Zed's, with
  highlighting, several carets, folding, completions and diagnostics, for
  files of any size.
- [Diff viewer](diff-viewer.md): the changes between two texts, side by
  side or unified, with the new text editable and changes to review.

## Documents

- [Markdown](markdown.md): CommonMark and GitHub's Markdown, as finished
  documents or a language model's replies streaming in, selectable and
  copied as text or Markdown.

## Dates and colors

- [Calendar](calendar.md): a month choosing a day.
- [Date input](date-input.md): a date in a field, chosen in a calendar
  below it.
- [Time input](time-input.md): the time of day, its hours and minutes
  typed or stepped.
- [Color picker](color-picker.md): a color well, and the picker it opens.

## Collections

- [List](list.md): rows of any number and height, built only while in
  view.
- [Table](table.md): rows in columns that the user sorts, resizes and
  moves.
- [Tree](tree.md): items that open and close, built inside the items they
  belong to.
- [Outline](outline.md): a tree of any size, as a list or a table.
- [Grid view](grid-view.md): items in a grid of any size, as a photo
  library.

## Containers

- [Scroll view](scroll.md): containers that scroll their children.
- [Grid](grid.md): children in columns and rows, as CSS grid lays them out.
- [Split view](split.md): two panes with a divider the user drags.
- [Collapsible](collapsible.md): content that a label shows and hides.
- [Accordion](accordion.md): sections that open and close, one above the
  other.
- [Form](form.md): labeled controls with descriptions and errors.
- [Web view](web-view.md): a web page in the window, under the native UI
  painted after it, as menus and dialogs.

## Navigation

- [Tabs](tabs.md): a row of tabs choosing a page.
- [Sidebar](sidebar.md): a source list of items in sections, as Finder's.
- [Breadcrumbs](breadcrumbs.md): a path whose items take the user back.
- [Back and forward buttons](history-buttons.md): buttons going through a
  router's history.

## Dialogs and messages

- [Dialog](dialog.md): a dialog over the dimmed window.
- [Alert dialog](alert-dialog.md): a question about something important,
  with buttons.
- [Popover](popover.md): a panel below a node.
- [Tooltip](tooltip.md): a tip shown as the pointer rests on a node, or the
  keyboard focus comes to it.
- [Toast](toast.md): a short message near the bottom of the window, with
  an action or not.

## Status

- [Progress bar](progress.md): work done so far, or of unknown length.
- [Spinner](spinner.md): spokes turning while work of unknown length goes
  on.
- [Meter](meter.md): a value in a range, colored by its level.
- [Badge](badge.md): a short text in a pill, as a count.

## Charts

- [Charts](chart.md): scales, axes and marks without a look, for bars,
  lines, areas, stacks and donuts, and a selection the pointer and the
  keyboard move.

## Images

- [Image](image.md): bitmaps and SVGs in their own colors.
- [Video](video.md): clips that play, as animated GIFs, behind the content
  or as previews.
- [Icon](icon.md): SVG icons in the color of the text.
- [Avatar](avatar.md): a picture of a person, or their initials.
