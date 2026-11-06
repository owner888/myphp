<?php
// The window's view of the Profile main.php makes, $this in it (Content::file): saved, it hot reloads. Its text is
// the key of its translation in locales/, said in the language the Profile chose (environment(locale:)).
declare(strict_types=1);

/** A field led by its label, muted, the labels one width. */
$labeled = fn(string $label, View $field): View => HStack(alignment: VerticalAlignment::top, spacing: 12)(
    Text($label)->foregroundStyle(Color::secondary)->frame(width: 70, alignment: Alignment::leading),
    $field,
);

return fn() => ScrollView()(VStack(alignment: HorizontalAlignment::leading, spacing: 14)(
    Text('Profile')->font(Font::title2),
    $labeled('Name', TextField('Name', text: $this->name)->onSubmit(fn() => $this->submit())->frame(width: 320)),
    $labeled('City', TextField('City (try an input method)', text: $this->city)->onSubmit(fn() => $this->submit())->frame(width: 320)),
    $labeled('Note', TextEditor(text: $this->note)->frame(width: 320, height: 96)), // wraps at the width; Enter breaks a line
    // A message field: a line that grows with what is typed, Return sends it, Shift+Return breaks a line.
    $labeled('Message', TextField('Return sends, Shift+Return breaks a line', text: $this->draft, axis: Axis::vertical)
        ->onKeyPress(KeyEquivalent::return, function (KeyPress $key): KeyPressResult {
            if (in_array(EventModifiers::shift, $key->modifiers, true)) { return KeyPressResult::ignored; }
            $this->send();
            return KeyPressResult::handled;
        })
        ->frame(width: 320)),
    $labeled('Sent', ScrollView()(Text(verbatim: $this->log === '' ? 'Nothing sent yet' : $this->log)->textSelection(TextSelectability::enabled)->frame(maxWidth: INF, alignment: Alignment::topLeading))->frame(width: 320, height: 64)),
    // A strip wider than the window: scrolls sideways with the trackpad or its thumb.
    ScrollView(Axis::horizontal)(HStack(spacing: 8)(
        Each(range(0, 29))(fn(int $i) => VStack(alignment: HorizontalAlignment::leading, spacing: 2)(
            Text('Tag {i}', ['i' => $i]),
            Text(verbatim: sprintf('#%06x', ($i * 2654435761) & 0xffffff))->font(Font::caption)->foregroundStyle(Color::secondary),
        )->padding(6)->background(Color::background)),
    )->padding(8))->background(Color::gray->opacity(0.12)),
    // Controls, each writing straight into the Profile, two rows of them to fit the window; the theme follows the radio buttons.
    HStack(spacing: 16)(
        Toggle('Subscribe', isOn: $this->subscribe)->toggleStyle(ToggleStyle::checkbox),
        Picker('Theme', selection: $this->mode)(Text('Light')->tag('light'), Text('Dark')->tag('dark'), Text('System')->tag('system'))
            ->pickerStyle(PickerStyle::radioGroup)->horizontalRadioGroupLayout(),
    )->labelsHidden()->preferredColorScheme(match ($this->mode) { 'light' => ColorScheme::light, 'dark' => ColorScheme::dark, default => null }),
    HStack(spacing: 16)(
        Slider(value: $this->volume, in: [0, 100])->frame(width: 140),
        Text(verbatim: sprintf('%.0f%%', $this->volume))->foregroundStyle(Color::secondary)->frame(width: 40), // a fixed width, so the number does not push the picker about
        Picker('Language', selection: $this->lang)(Each(['en' => 'English', 'zh-Hans' => '中文', 'es' => 'Español'])(fn(string $name, string $tag) => Text(verbatim: $name)->tag($tag))), // each in its own language
    )->labelsHidden(),
    // A card: Tab reaches it, Space or a click activates it; its text wraps at the card's width.
    Button(action: fn() => $this->activate(), label: Text('**A card.** Tab to it and press Space, or click it. This sentence wraps at the width the window gives the card, '
        . 'so make the window narrower and watch it reflow.'))->buttonStyle(ButtonStyle::bordered),
    // A link, a bar following the slider, a spinner; Submit has a tip.
    HStack(spacing: 12)(
        WebLink('myphp on GitHub', destination: 'https://github.com/owner888/myphp'),
        ProgressView(value: $this->volume / 100)->frame(width: 140)->help('follows the slider'),
        ProgressView()->help('indeterminate: animates'),
    ),
    HStack(spacing: 8)(
        Button('Submit', action: fn() => $this->submit())->buttonStyle(ButtonStyle::borderedProminent)->help('Sends the form; Return in a field does too'),
        Button('Clear', action: fn() => $this->clear()),
    ),
    match ($this->greeting) {
        'hello' => Text('Hello, {name} from {city} (#{count})', ['name' => $this->name, 'city' => $this->city, 'count' => $this->submits]),
        'card' => Text('Card activated (#{count})', ['count' => $this->submits]),
        default => Text('Submit, or press Return in a field.'),
    },
    Text('name: {bytes, plural, one {# byte} other {# bytes}}', ['bytes' => strlen($this->name)])->font(Font::caption)->foregroundStyle(Color::secondary),
)->padding(24))->environment(locale: $this->lang);
