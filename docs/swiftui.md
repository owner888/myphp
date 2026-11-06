# Coming from SwiftUI

A guide for people and coding agents who know SwiftUI and are about to
write a myphp view. Left is SwiftUI, right is the same thing in myphp;
every PHP block runs headless in `tests/docs_examples_test.php` (see
[Native UI](ui/README.md) for how to read the examples: `$c` is the view's
`Context`, `$app` your state). `docs/mobile.md` covers what a phone adds;
`docs/mygo-deviations.md` lists where myphp differs from mygo, the Go
framework it ports.

## Three things to unlearn first

SwiftUI keeps a tree of views with identity, diffs it, and calls back into
your closures. myphp is **immediate mode**: the view function runs again
for every frame, builds the tree from scratch, and the controls report what
happened as return values.

| SwiftUI | myphp |
|---|---|
| `body` returns a value; SwiftUI diffs it against the last one. | The view *builds*: `$c->column(); … $c->end();` adds nodes as it runs. Nothing is diffed; the whole tree is cheap to rebuild. |
| `@State` lives on the view; SwiftUI owns it by the view's identity. | State is yours, in a plain object made once before the window opens. The view only reads and writes it. |
| `Button("Save") { save() }` — an action closure, called later. | `if ($c->clicked($c->button('Save'))) { save(); }` — the click is this frame's return value; ordinary `if`, `return`, `break` work around it. |
| A view's identity comes from its position, or `.id()`. | The same: a node is the one at the same place among its siblings, or `$c->key($id)->…` for rows that reorder. |
| Modifiers return new views: `Text("a").bold()`. | Modifiers mutate and return the same node: `$c->text('a')->bold()`. Call them on the node the builder returned, in the same build. |

```swift
struct Counter: View {
    @State var n = 0
    var body: some View {
        VStack(spacing: 12) {
            Text("\(n)").font(.largeTitle).bold()
            Button("Increment") { n += 1 }
        }
    }
}
```

```php
$app = new class { public int $n = 0; };
$view = function (Context $c) use ($app) {
    $c->column(gap: 12)->fill()->center();
      $c->text((string)$app->n)->fontSize(34)->bold();
      if ($c->clicked($c->primaryButton('Increment'))) {
          $app->n++;
      }
    $c->end();
};
```

Indent what is inside a container by two spaces more, as above: the
indentation is the view hierarchy, since there are no braces.

In an app the view is a file of its own, `view.php`, which returns that
closure with `$this` for `$app` (`Content::file(__DIR__ . '/view.php',
$app)`): saved, it hot reloads with the state kept, as SwiftUI's previews
and Flutter's hot reload do ([view files](ui/views.md#view-files-and-hot-reload)).

## State and data flow

| SwiftUI | myphp |
|---|---|
| `@State var on = false` | A property on your state object: `public bool $on = false;` |
| `@Binding var on: Bool`, passed as `$on` | Pass by reference: controls take `&$ref` (`$c->switch('Wi-Fi', $app->on)` writes through). A helper that edits a value takes `bool &$on`. |
| `@StateObject` / `@ObservedObject` | An object held by your state, made once: `ListState`, `TextState`, `Router`, your own classes. Nothing to observe: the next frame reads it. |
| `@Environment(\.colorScheme)` | `$c->theme->dark`; the theme is the environment: `$c->theme->accent`, `->text`, `->surface`. |
| `.environment(\.theme, t)` for a subtree | `$c->themed($t); … $c->end();` |
| `@FocusState` | `$c->focused($node)`; `Handle` + `->bind($h)` to move the focus from outside (see [Input](ui/input.md)). |
| `onChange(of: value) { }` | `if ($c->changed($node)) { }` right after the control, or compare with the value before. |
| `@AppStorage` | Read and write a file under `App::path(PathName::UserData)` yourself; nothing is magic. |

The control writes the user's edit through the reference **before its
builder returns**, so the next statement already sees the new value:

```php
$app = new class { public bool $wifi = true; public float $volume = 0.5; };
$view = function (Context $c) use ($app) {
    $c->column(16, 8);
      $c->switch('Wi-Fi', $app->wifi);
      if ($app->wifi) {                       // sees this frame's edit
          $c->slider($app->volume, 0, 1);
      }
      if ($c->changed($c->slider($app->volume, 0, 1))) {
          // like onChange(of: volume)
      }
    $c->end();
};
```

A model object the view cannot reach by reference (an array element) is
edited through a local, then stored back: see "Local copies" in
[Views](ui/views.md).

## Layout

| SwiftUI | myphp |
|---|---|
| `VStack(spacing: 8) { }` | `$c->column(gap: 8); … $c->end();` |
| `HStack(spacing: 8) { }` | `$c->row(gap: 8); … $c->end();` |
| `ZStack { }` | A container with `->absolute()` children, or a `->draw()` callback; there is no stacking container (see [Layout](ui/layout.md)). |
| `Spacer()` | `$c->spacer()`; or `->grow(1)` on the child that should take the room. |
| `.frame(width: 200, height: 44)` | `->size(200, 44)`; `->width(200)`, `->height(44)` alone. |
| `.frame(maxWidth: .infinity)` | `->fillWidth()`; `->fill()` both ways; `->maxWidth(600)` a cap. |
| `.frame(minWidth: 100)` | `->minWidth(100)` |
| `.padding()` / `.padding(16)` / `.padding(.horizontal, 12)` | `->padding(8)` / `->padding(16)` / `->paddingX(12)`; `->padding($top, $right, $bottom, $left)` |
| `alignment: .leading` on a VStack | `->alignItems(Node::START)`; `CENTER`, `END`, `STRETCH`, `BASELINE` |
| `Spacer()` between to push apart | `->justify(Node::SPACE_BETWEEN)` on the container |
| `.aspectRatio(16/9)` | `->aspectRatio(16 / 9)` |
| `.offset(x: 10)` | `->translate(10, 0)` (visual only, as offset) |
| `.overlay(alignment: .topTrailing) { Badge() }` | A child with `->absolute()->top(-4)->right(-6)` |
| `.clipped()` | `->clip()` |
| `GeometryReader` | `$c->width`, `$c->height` for the window; `$c->bounds($node)` for a node's last-frame rectangle. |
| `LazyVGrid(columns:)` | `$c->grid(3, gap: 8)`, or `$c->gridView()` for thousands of items. |
| `ScrollView { }` | `$c->scroll(); … $c->end();`, with a `ScrollState` to control the position. |

```php
$view = function (Context $c) {
    $c->row(12, 12)->alignItems(Node::CENTER)->fillWidth();
      $c->text('Title')->bold();
      $c->spacer();
      $c->button('Edit');
    $c->end();
};
```

## Text and fonts

| SwiftUI | myphp |
|---|---|
| `Text("Hi")` | `$c->text('Hi')` |
| `.font(.title)`, `.font(.caption)` | `->preferredFont(Font::TITLE1)`, `->preferredFont(Font::CAPTION1)`: UIKit's names (`LARGE_TITLE`, `TITLE1`–`TITLE3`, `HEADLINE`, `BODY`, `CALLOUT`, `SUBHEADLINE`, `FOOTNOTE`, `CAPTION1`, `CAPTION2`), at the HIG's size for the platform, scaled with the system's text size; `->fontSize(28)` for a size in points |
| `.bold()` / `.fontWeight(.semibold)` | `->bold()` / `->fontWeight(600)` |
| `.italic()`, `.underline()`, `.strikethrough()` | the same names |
| `.foregroundStyle(.secondary)` | `->textColor($c->theme->textMuted)` |
| `.foregroundStyle(.red)` | `->textColor(0xff3b30)`; prefer the theme's `danger`. |
| `.multilineTextAlignment(.center)` | `->textAlign(Node::CENTER)` |
| `.lineLimit(2)` | `->maxLines(2)`; `->singleLine()` cuts with an ellipsis. |
| `.monospaced()` | `$c->text($s, style: Font::MONO)` |
| `.fontDesign(.rounded)`, custom fonts | `->font('Family')` after `Font::register()` (see [Text](ui/text.md)). |
| `.kerning(1)` | `->letterSpacing(1)` |
| `Text` with markdown / attributed strings | `$c->richText()` (see [Text](ui/text.md#rich-text)) |
| `Label("Save", systemImage: "square.and.arrow.down")` | A row of `$c->icon($svg)` and `$c->text()`; `Image::symbol('square.and.arrow.down', 17)` is the SF Symbol itself on Apple platforms, the GTK theme's icon on Linux. |

```php
$view = function (Context $c) {
    $t = $c->theme;
    $c->column(gap: 4);
      $c->text('Settings')->fontSize(28)->bold();
      $c->text('12 files, 3.4 MB')->fontSize(15)->textColor($t->textMuted);
      $c->text('A long description that wraps and is cut after two lines.')->maxLines(2);
    $c->end();
};
```

## Colors, backgrounds and materials

| SwiftUI | myphp |
|---|---|
| `Color.accentColor` | `$c->theme->accent` (follows the system's accent) |
| `Color(.label)` / `.secondaryLabel` | `$c->theme->text` / `->textMuted` |
| `Color(.systemBackground)` / `.secondarySystemBackground` | `$c->theme->background` / `->surface` |
| `Color(.separator)` | `$c->theme->border` |
| `.red`, `.orange`, `.green` as meaning | `->danger`, `->warning`, `->success` |
| `.background(.blue)` | `->background(0x0a84ff)`; colors are `0xRRGGBB` ints, alpha a second argument. |
| `.background(.regularMaterial)` / `.glassEffect()` | `->systemMaterial('sidebar')`, `'popover'`, `'hud'`… (an NSVisualEffectView on macOS), `->systemMaterial('glass')` (Liquid Glass on macOS 26; myphp's own glass elsewhere) |
| `.cornerRadius(12)` / `.clipShape(RoundedRectangle(cornerRadius: 12))` | `->radius(12)`; `->radius($tl, $tr, $br, $bl)` per corner |
| `.border(.gray)` | `->border(1, $c->theme->border)` |
| `.shadow(radius: 8, y: 4)` | `->shadow(0, 4, 8, 0, 0x000000, 0.2)` |
| `.opacity(0.5)` | `->opacity(0.5)` |
| `LinearGradient(...)` | `->gradient($from, $to, $angle)`, `->linearGradient(new LinearGradient(…))` |
| `preferredColorScheme(.dark)` | `App::setTheme(Theme::dark())` for the app; `$c->themed(Theme::dark())` for a part. |

Take colors from `$c->theme` rather than literals: the theme switches with
the system's appearance, accent, contrast and text size (see
[Styling](ui/styling.md#themes)).

```php
$view = function (Context $c) {
    $t = $c->theme;
    $c->column(16, 8)->background($t->surface)->radius(12)->border(1, $t->border);
      $c->text('Card')->bold();
      $c->text('Follows light and dark mode')->textColor($t->textMuted);
    $c->end();
};
```

## Controls

| SwiftUI | myphp |
|---|---|
| `Button("Save") { }` | `if ($c->clicked($c->button('Save'))) { }` |
| `Button("Save") { }.buttonStyle(.borderedProminent)` | `$c->primaryButton('Save')` |
| `.disabled(busy)` | `->disabled($busy)` |
| `Toggle("Wi-Fi", isOn: $on)` | `$c->switch('Wi-Fi', $app->on)`; `$c->checkbox()` for a check box |
| `Slider(value: $v, in: 0...1)` | `$c->slider($app->v, 0, 1)` |
| `Stepper(value: $n, in: 1...10)` | `$c->stepper($app->n, 1, 10)` |
| `Picker(selection:) { }.pickerStyle(.segmented)` | `$c->segmented(['Day', 'Week'], $app->index)` |
| `Picker(selection:) { }.pickerStyle(.menu)` | `$c->select(['Small', 'Large'], $app->index)` |
| `TextField("Name", text: $name)` | `$c->input($app->name, 'Name')` with a `TextState` (its `->text` is the string) |
| `.onSubmit { }` | `if ($c->submitted($field)) { }` |
| `SecureField` | `$c->input($state)->password()` |
| `TextEditor(text:)` | `$c->textarea($app->notes)` |
| `DatePicker` | `$c->dateInput()`, `$c->calendar()`, `$c->timeInput()` |
| `ColorPicker` | `$c->colorPicker()`, `$c->colorWell()` |
| `ProgressView()` / `ProgressView(value:)` | `$c->spinner()` / `$c->progress($fraction)` |
| `Link("Site", destination:)` | `$c->link('Site', 'https://…')` |
| `Menu("Sort") { Button("Name") { } }` | `$c->menuButton('Sort', function (Menu $m) { if ($m->item('Name')->chosen()) { … } })` |
| `.contextMenu { }` | `$node->contextMenu(function (Menu $m) { … })` |
| `Image(systemName:)` / `Image("asset")` | `$c->icon($svg)` for an SVG in the text's color; `$c->image(Image::fromFile(…))` for a picture |
| `.help("tip")` | `->tooltip('tip')` |
| `.accessibilityLabel("Close")` | `->label('Close')`; `->role(Role::BUTTON)` (see [Accessibility](ui/accessibility.md)) |
| `Form { Section("Account") { } }` | `$c->form(fn() => …)`, `$c->field('Label', fn() => …)`, `$c->fieldset()` (see [Form](ui/form.md)) |

```php
$app = new class {
    public TextState $name; public int $plan = 0; public bool $news = false;
    public function __construct() { $this->name = new TextState(); }
};
$view = function (Context $c) use ($app) {
    $c->form(function () use ($c, $app) {
        $c->field('Name', fn() => $c->input($app->name, 'Your name'));
        $c->field('Plan', fn() => $c->segmented(['Free', 'Pro'], $app->plan));
        $c->field('', fn() => $c->checkbox('Send me news', $app->news));
    });
};
```

## Lists and collections

| SwiftUI | myphp |
|---|---|
| `List(items) { Row($0) }` | `$c->list($state, count($items), fn(int $i) => …)` builds only the rows in view, for millions of rows |
| `ForEach(items, id: \.id) { }` in a `VStack` | `foreach ($items as $it) { $c->key($it['id'])->row(); … $c->end(); }` — the key keeps identity as rows reorder |
| `.swipeActions { Button(role: .destructive) }`, `.onDelete` | `$c->swipeActions($action, fn() => …, 'Flag', '!Delete')` on the row; `$action` is the index tapped, a full swipe performs the first (see [mobile.md](mobile.md)) |
| `List` selection | `ListState::$selected` and the state's selection methods (see [List](ui/list.md)) |
| `Section("Today") { }` in a `List`/`Form` | `$c->section('Today', fn() => …, $footer)`: the inset grouped section of Settings, with `$c->cell($title, $detail, $disclosure)` rows (see [mobile.md](mobile.md)) |
| `LabeledContent("Size", value: "2 KB")`, a `NavigationLink` row | `$c->cell('Size', '2 KB')`; `$c->cell('About', disclosure: true)` and `$c->clicked()` on it |
| `LazyVGrid` of photos | `$c->gridView($gridState, $n, 140, 120, fn(int $i) => …)` |
| `.refreshable { await load() }` | `$c->refreshable($scrollState, $app->refreshing, fn() => …)`: pulled down past 60 points it sets `$refreshing`, the app clears it when its work is done (see [mobile.md](mobile.md)) |
| `.searchable(text:)` | `$c->searchField($state)` above the list |
| `Table(rows) { TableColumn("Name") }` | `$c->table()` (see [Table](ui/table.md)) |
| `OutlineGroup` / `DisclosureGroup` | `$c->tree()`, `$c->outline()`, `$c->collapsible()` |

```php
$app = new class {
    public ListState $list; public array $files;
    public function __construct() { $this->list = new ListState(); $this->files = array_map(fn($i) => "file-$i.txt", range(1, 10_000)); }
};
$view = function (Context $c) use ($app) {
    $c->list($app->list, count($app->files), function (int $i) use ($c, $app) {
        $c->row(gap: 8)->padding(6, 12);
          $c->text($app->files[$i])->grow(1);
          $c->text('2 KB')->textColor($c->theme->textMuted);
        $c->end();
    })->grow(1);
};
```

## Navigation

| SwiftUI | myphp |
|---|---|
| `NavigationStack(path: $path) { }` | `new Router('/')` held in your state; `$router->view($c, fn(Route $r) => …)` builds the page shown |
| `.navigationDestination(for:)` | `if ($r->match('/note/{id}')) { … $r->param('id') … }` inside the view closure |
| `NavigationLink(value:)` / `path.append()` | `$router->push('/note/42')` |
| `dismiss()` / the back button | `$router->back()`; `$c->backButton()` draws one; `navigationBar()` on phones |
| `.navigationTitle("Notes")` | `$r->title('Notes')` |
| `.navigationBarTitleDisplayMode(.large)` | `$c->largeTitle('Notes')` first in the page's scroll, and that scroll's `ScrollState` as `navigationBar()`'s fourth argument |
| The edge swipe back | Built in: on a touch screen a `Router` with a page to go back to follows a finger from the start edge |
| `NavigationSplitView { sidebar } detail: { }` | A row of `$c->sidebar($selected, fn() => $c->sidebarItem('/notes', 'Notes'))` and `$router->view()` (see [Navigation](ui/navigation.md)) |
| `TabView { }` | `$c->tabs(['A', 'B'], $index)` on the desktop; `$c->tabView($items, $index, $content)` adapts as `TabView(.sidebarAdaptable)` does: bottom bar on a phone, top on an iPad upright, sidebar otherwise |
| `.transition(.slide)` on pages | Built in: pages slide from the right and the one behind dims; `$router->transition`, `$router->animation` change it |

```php
$app = new class {
    public Router $router;
    public function __construct() { $this->router = new Router('/notes'); }
};
$view = function (Context $c) use ($app) {
    $app->router->view($c, function (Route $r) use ($c, $app) {
        if ($r->match('/notes')) {
            $r->title('Notes');
            if ($c->clicked($c->button('Open note 42'))) { $app->router->push('/notes/42'); }
        } elseif ($r->match('/notes/{id}')) {
            $r->title('Note ' . $r->param('id'));
            if ($c->clicked($c->button('Back'))) { $app->router->back(); }
        }
    });
};
```

## Sheets, alerts, popovers and menus

A SwiftUI presentation is driven by an `isPresented` binding; so is
myphp's, as `bool &$open`. The control sets it back to false when the user
dismisses it.

| SwiftUI | myphp |
|---|---|
| `.sheet(isPresented: $open) { }` | `$c->modal($open, fn() => …)` on the desktop; `$c->sheet($open, fn() => …)` rises from the bottom on phones |
| `.fullScreenCover` | A page of the router |
| `.alert("Title", isPresented:) { Button("OK") {} }` | `$c->alertDialog($open, 'Title', 'Message', 'Cancel', 'Delete')` returns the index of the button pressed, −1 while open |
| `.confirmationDialog` | `$c->actionSheet($open, 'Title', 'Save', '!Delete')` on phones (`!` marks destructive) |
| `.popover(isPresented:)` | `$c->popover($anchor, $open, fn() => …)` |
| `Menu { }` | `$c->menuButton()`, `$node->menu()`, `$node->contextMenu()` |
| A toast / banner (no SwiftUI equivalent) | `$c->toast('Saved')` |
| `.interactiveDismissDisabled()` | Not yet. |

```php
$app = new class { public bool $confirm = false; public int $deleted = 0; };
$view = function (Context $c) use ($app) {
    if ($c->clicked($c->button('Delete…'))) { $app->confirm = true; }
    $choice = $c->alertDialog($app->confirm, 'Delete the note?', 'This cannot be undone.', 'Cancel', 'Delete');
    if ($choice === 1) { $app->deleted++; }
};
```

## Animation

SwiftUI animates the difference between two states. myphp does the same
in two ways: a **transition** on a node animates its position, size and
colors as the layout changes them; an **animated value** eases a number
you then use anywhere.

| SwiftUI | myphp |
|---|---|
| `withAnimation { open.toggle() }` | Set the state; put `$c->transition($node, $t)` on the nodes that should move smoothly |
| `.animation(.spring, value: x)` | `$c->animateTo($node, 'x', $target, Animation::snappy())` returns this frame's value |
| `.spring(duration: 0.5, bounce: 0.2)` | `new Animation(spring: new SpringOptions(duration: 0.5, bounce: 0.2))` — the same parameters |
| `.easeOut(duration: 0.2)` | `new Animation(duration: 0.2, ease: Easing::easeOut(...))`; `$c->animate($n, 'k', $target, 0.2)` for short |
| `.smooth`, `.snappy`, `.bouncy` | `Animation::smooth()`, `::snappy()`, `::bouncy()` |
| `.transition(.move(edge:))` / `.opacity` | `new ElementTransition(enter: new TransitionMotion(collapse: true), exit: …)` (see [Transitions](ui/transitions.md)) |
| `.matchedGeometryEffect` | A `$c->key()` kept across two places and a transition on the node |
| `.scaleEffect`, `.rotationEffect`, `.offset` | `->scale()`, `->rotate()`, `->translate()`, fed an animated value |
| `TimelineView`, `.phaseAnimator`, `.keyframeAnimator` | `Timeline`, `KeyframeSequence` (see [Motion](ui/motion.md)) |
| `UIAccessibility.isReduceMotionEnabled` | Honored by every built-in motion; your own reads `$c->runtime->reduceMotion` |
| `.sensoryFeedback(.success, trigger: saved)` | `Haptics::success()` in the action that saved; `Haptics::impact('light')`, `::selection()`, `::warning()`, `::error()` |

```php
$app = new class { public bool $open = false; };
$view = function (Context $c) use ($app) {
    if ($c->clicked($c->button('Toggle'))) { $app->open = !$app->open; }
    $card = $c->column()->size(120, 80)->radius(12)->background($c->theme->accent);
    $c->end();
    $x = $c->animateTo($card, 'x', $app->open ? 200 : 0, Animation::snappy());
    $card->translate($x, 0);
};
```

## Lifecycle, time and async

| SwiftUI | myphp |
|---|---|
| `.onAppear { }` | The first frame the node is built: keep a flag in your state, or do the work when the state is made. |
| `.task { await load() }` | `App::go(function () { $data = Http::get($url); … })` runs in a fiber; what waits yields to the run loop. Start it from an action or when the state is made, not from the view, which runs every frame. |
| `Timer.publish` / `.task` with `sleep` | `$c->after(1.0)` asks for a frame in a second; `$c->now()` is the frame's time in ms; `$c->animationFrame()` the next frame. |
| `@MainActor` | Everything already is: PHP runs the app on one thread. Never block it; what waits goes in `App::go()`, heavy CPU work in a child process (see [app.md](app.md#one-thread)). |
| `URLSession` | `Http::get()`, `Http::post()`, whose response has `->status`, `->body`, `->json()`; the fetch plugin for a browser-like API |
| `scenePhase` | `App::onActivate()`, the window's events (see [The application](app.md)) |

<!-- no-test -->
```php
$app = new class { public array $items = []; public bool $loading = false; };
$view = function (Context $c) use ($app) {
    if ($c->clicked($c->button('Reload')) && !$app->loading) {
        $app->loading = true;
        App::go(function () use ($app) {
            $app->items = Http::get('https://example.com/items')->json();
            $app->loading = false;              // the next frame shows it
        });
    }
    if ($app->loading) { $c->spinner(); }
};
```

## Platform behavior you get for free

- **Light and dark mode**, the system's accent, higher contrast and larger
  text: the theme follows them (`Theme::follow`). On phones, Dynamic Type
  (iOS's Text Size, Android's Font size) scales the theme's text and every
  `preferredFont()` style as the setting changes.
- **Reduce Motion** (iOS; Android's "Remove animations"): springs,
  transitions and page slides settle at once.
- **Right to left**: `paddingStart()`, `marginEnd()`, `Node::START` are
  logical; rows mirror (see [Right to left](ui/right-to-left.md)).
- **Accessibility**: nodes with a role and label are read by VoiceOver on
  macOS (see [Accessibility](ui/accessibility.md)).
- **On phones**: touch, the safe area, the keyboard, Back, the pickers
  (see [Android and iOS](mobile.md)).

## Not in myphp yet

What a SwiftUI developer will reach for and not find, and what to do
instead. Each is a candidate for the framework; until then:

| SwiftUI | Instead |
|---|---|
| `ZStack` | `->absolute()` children, `->draw()` |
| `.interactiveDismissDisabled`, `.presentationDetents` | A `modal()`/`sheet()` has one size and dismisses on tap outside |
| `Canvas` | `->draw(fn(Painter $p, array $rect) => …)` and `Canvas` (see [Drawing](ui/drawing.md)) |
| `Chart` | `MyPHP\UI\Chart` (see [Chart](ui/chart.md)) |
| `PhotosPicker`, `fileImporter` | `Dialog::open()` with filters: pictures open the photo picker on phones |
| `ShareLink` | Not yet |
| `WidgetKit`, `App Intents`, `Live Activities` | Out of scope |

## Checklist for an agent writing a myphp view

1. State is a class made once; the view is `view.php`, a file of
   closures returning `function (Context $c): void`, `$this` the state
   (`Content::file()`). Never make a `TextState`, `ListState` or `Router`
   inside the view, nor a named class or function in `view.php`.
2. Open containers with `$c->column()` / `row()` / `scroll()`, close each
   with `$c->end()`, indent the inside by two spaces.
3. Controls that edit take `&$ref`; ask `$c->clicked()`, `changed()`,
   `submitted()`, `dismissed()` on the node the builder returned, in the
   same build.
4. Rows that reorder get `$c->key($id)` before the builder.
5. Colors and sizes come from `$c->theme`; literal hex only for data (a
   chart's series, a user's tag color).
6. Mobile-only widgets (`tabView`, `navigationBar`, `sheet`,
   `actionSheet`, `safeArea`) are named and sized after UIKit; check
   `docs/mobile.md` before inventing one.
7. Run on a device with the CLI only: `myphp run -d macos|ios|android <app>`.
