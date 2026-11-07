<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\App;
use MyPHP\UI\Context;

/** A label leading a control in a row. */
$row = fn(string $title, View ...$views) => HStack(spacing: 10)(Text($title)->frame(width: 70, alignment: Alignment::leading), ...$views);

/** A badge: a short text on a capsule. */
$badge = fn(string $text) => Text(verbatim: $text)->font(Font::caption)->padding(Edge::horizontal, 8)->padding(Edge::vertical, 2)->background(Color::gray->opacity(0.18))->clipShape(Capsule());

/** A file of the tree, chosen by a click; a folder, a disclosure group of $files. */
$file = function (string $path, ?Closure $files = null) use (&$file): View {
    if ($files === null) {
        return Button(basename($path), action: fn() => $this->chosen = $path)->buttonStyle(ButtonStyle::plain)->fontWeight($this->chosen === $path ? Weight::semibold : Weight::regular)
            ->foregroundStyle($this->chosen === $path ? Color::accentColor : Color::primary);
    }
    $this->open[$path] ??= false;
    return DisclosureGroup(basename($path), isExpanded: $this->open[$path])(VStack(alignment: HorizontalAlignment::leading, spacing: 4)(...$files()));
};

$general = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
    Stepper("Copies: {$this->count}", value: $this->count, in: [1, 10]),
    Stepper(sprintf('Scale: %g', $this->scale), value: $this->scale, in: [0.5, 4], step: 0.25),
    $row('Disk', Gauge(value: $this->count * 10, in: [0, 100])->frame(width: 160), Text(verbatim: "{$this->count}0 % used")->foregroundStyle(Color::secondary)),
    // Icons in the text's color, or a size and color of their own; avatars from names.
    $row('Icons',
        Image($this->icons['check']), Image($this->icons['star'])->foregroundStyle(Color::orange), Image($this->icons['bell'])->frame(width: 28, height: 28),
        Image($this->icons['star'])->rotationEffect(45), Image($this->icons['palette'])->resizable()->frame(width: 28, height: 28),
        Image($this->icons['palette'])->resizable()->frame(width: 28, height: 28)->grayscale(1),
        Avatar('Ada Lovelace'), Avatar('Grace Hopper'), Avatar('Linus'),
    ),
    ComboBox('Fruit', text: $this->fruit, items: FRUITS),
    TextField('City', text: $this->city)->textInputSuggestions(CITIES),
    DatePicker('When', selection: $this->when),
    TokenField('Tags', tokens: $this->tags, suggestions: TAGS),
    DisclosureGroup('More options', isExpanded: $this->more)(VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
        Text('Shown while the group is open; its header opens and closes it.')->foregroundStyle(Color::secondary),
        HStack(spacing: 8)($badge('new'), $badge('3'), $badge('beta')),
        // A toggle of toggles: mixed while some are on; switching it switches them all.
        Toggle('Notifications', sources: [&$this->mail, &$this->calendar]),
        VStack(alignment: HorizontalAlignment::leading, spacing: 6)(Toggle('Mail', isOn: $this->mail), Toggle('Calendar', isOn: $this->calendar))->padding(Edge::leading, 20),
    )),
);

$advanced = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    // A tree of disclosure groups: a click chooses a file.
    VStack(alignment: HorizontalAlignment::leading, spacing: 4)(
        $file('src', fn() => [$file('src/App.php'), $file('src/UI', fn() => [$file('src/UI/Context.php'), $file('src/UI/Node.php')])]),
        $file('examples', fn() => [$file('examples/widgets')]),
        $file('README.md'),
    ),
    GroupBox()(VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
        TextField('Name', text: $this->name, prompt: 'Jane Doe'),
        Text(trim($this->name) === '' ? 'Required' : 'Shown on receipts.')->font(Font::caption)->foregroundStyle(trim($this->name) === '' ? Color::red : Color::secondary),
        Picker('Size', selection: $this->size)(Text('Small')->tag(0), Text('Medium')->tag(1), Text('Large')->tag(2)),
        Toggle('Send me mail', isOn: $this->notify),
    )),
    HStack(alignment: VerticalAlignment::top, spacing: 10)(
        // Rows that drag to another place (Cmd-click chooses several to drag together); the choice follows them.
        Text('Steps')->frame(width: 70, alignment: Alignment::leading),
        ListView($this->steps, selection: $this->step)(fn(string $s) => Text($s))->onMove($this->move(...))->frame(width: 200, height: 5 * 28 + 2)->border(Color::separator)->cornerRadius(6),
        DatePicker('Calendar', selection: $this->when, displayedComponents: [DatePickerComponents::date])->datePickerStyle(DatePickerStyle::graphical)->labelsHidden(),
    ),
);

$language = new Binding(get: fn() => App::locale(), set: function (string $l) { App::setLocale($l); $this->last = "language $l"; });
$about = fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 10)(
    Text([Text('MyPHP ')->bold(), Text(verbatim: App::VERSION)->foregroundStyle(Color::secondary)]),
    Breadcrumbs(array_slice(CRUMBS, 0, $this->crumb + 1), selection: $this->crumb),
    HStack(spacing: 10)(ColorPicker('Color', selection: $this->color, supportsOpacity: true, opacity: $this->alpha), Text(verbatim: Context::hexOf($this->color, $this->alpha))->foregroundStyle(Color::secondary)),
    // The language the app shows: MyPHP's own strings (the search field, the menus) and the date and time formats follow it.
    HStack(spacing: 10)(
        Picker('Language', selection: $language)(Each(LANGUAGES)(fn(string $name, string $code) => Text(verbatim: $name)->tag($code)))->pickerStyle(PickerStyle::segmented),
        Text(verbatim: 'region ' . (App::region()->locale ?: '—') . ', ' . App::region()->hourCycle . 'h')->foregroundStyle(Color::secondary),
    ),
    Rating('Rating', value: $this->stars),
    $row('Range', RangeSlider(lower: $this->low, upper: $this->high, in: [0, 100], step: 5)->frame(width: 240), Text(verbatim: sprintf('%d–%d', $this->low, $this->high))->foregroundStyle(Color::secondary)),
    // An accordion: groups in a box, each opening by itself.
    GroupBox()(VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
        DisclosureGroup('Appearance', isExpanded: $this->sections[0])(Text('Theme, accent, text size.')->foregroundStyle(Color::secondary)),
        Divider(),
        DisclosureGroup('Shortcuts', isExpanded: $this->sections[1])(Text('Cmd+N new, Cmd+S save.')->foregroundStyle(Color::secondary)),
        Divider(),
        DisclosureGroup('About', isExpanded: $this->sections[2])(Text('The widgets example.')->foregroundStyle(Color::secondary)),
    )),
);

return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
    HStack(spacing: 10)(
        Text('Widgets')->font(Font::title2)->bold(),
        SearchField(text: $this->query)->frame(width: 180),
        Spacer(),
        ProgressView(), Text('working…')->foregroundStyle(Color::secondary),
    ),
    Divider(),
    // A toolbar: toggles and buttons without a face until hovered.
    HStack(spacing: 4)(
        Toggle('Bold', isOn: $this->bold)->toggleStyle(ToggleStyle::button),
        Toggle('Italic', isOn: $this->italic)->toggleStyle(ToggleStyle::button),
        Divider()->frame(height: 16),
        Button('Share', action: fn() => $this->toast = 'Shared'),
        Menu('View')(Button('Icons', action: fn() => $this->last = 'icons'), Button('List', action: fn() => $this->last = 'list')),
    )->buttonStyle(ButtonStyle::borderless),
    HStack(spacing: 16)(
        Toggle('Wi-Fi', isOn: $this->wifi)->toggleStyle(ToggleStyle::switch),
        $badge($this->wifi ? 'connected' : 'off'),
        Spacer(),
        Picker('View', selection: $this->view)(Text('List')->tag(0), Text('Grid')->tag(1), Text('Map')->tag(2))->pickerStyle(PickerStyle::segmented),
    ),
    Divider(),
    TabView(selection: $this->tab)(
        Tab('General', value: 0)($general()),
        Tab('Advanced', value: 1)($advanced()),
        Tab('About', value: 2)($about()),
    ),
    HStack(spacing: 8)(
        Button('Save', action: function () { $this->toast = 'Saved'; $this->last = 'saved'; })->buttonStyle(ButtonStyle::borderedProminent),
        Button('Delete…', action: fn() => $this->asking = true),
        Button('Reset…', action: fn() => $this->resetting = true),
        Menu('Sort by')(Picker('Sort by', selection: $this->sort)(Text('Name')->tag('Name'), Text('Date')->tag('Date'))),
        // A popover below the Options button: a click elsewhere closes it.
        Button('Options', action: fn() => $this->options = !$this->options)->popover(isPresented: $this->options, content: fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 8)(
            Toggle('Bold titles', isOn: $this->bold),
            Text("Sorted by {$this->sort}")->font(Font::caption)->foregroundStyle(Color::secondary),
        )->padding(12)),
    ),
    Spacer(),
    Divider(),
    Text(verbatim: 'last change: ' . $this->last)->font(Font::caption)->foregroundStyle(Color::secondary),
)
    ->padding(20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading)
    ->onChange(of: $this->values(), action: fn(array $old, array $new) => $this->changed($old, $new))
    ->toast($this->toast)
    // An alert: Return chooses its default button, Escape cancels.
    ->alert('Delete the draft?', isPresented: $this->asking, actions: [
        Button('Cancel', role: ButtonRole::cancel, action: fn() => $this->last = 'dismissed'),
        Button('Delete', role: ButtonRole::destructive, action: function () { $this->toast = 'Draft deleted'; $this->last = 'deleted'; }),
    ], message: "You can't undo this.")
    ->alert('Reset all settings?', isPresented: $this->resetting, actions: [
        Button('Cancel', role: ButtonRole::cancel, action: fn() => $this->last = 'reset dismissed'),
        Button('Reset', action: function () { $this->toast = 'Settings reset'; $this->last = 'reset'; }),
    ], message: 'Every control goes back to how it started.');
