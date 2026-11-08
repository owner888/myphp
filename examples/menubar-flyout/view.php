<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\App;

// The panel over the popover's material: two switches, the session length, Quit.
return fn() => VStack(alignment: HorizontalAlignment::leading, spacing: 12)(
    Text('Focus')->font(Font::headline),
    Toggle('Focus mode', isOn: $this->focus)->toggleStyle(ToggleStyle::switch)->id('focus'),
    Toggle('Sound', isOn: $this->sound)->toggleStyle(ToggleStyle::switch)->id('sound'),
    Text('Sessions of {minutes} minutes', minutes: (int)$this->minutes)->font(Font::caption)->foregroundStyle(Color::secondary),
    Slider(value: $this->minutes, in: [5, 60])->accessibilityLabel('Session length')->id('minutes'),
    Spacer(),
    HStack()(Spacer(), Button('Quit', action: App::quit(...))->id('quit')),
)->padding(16);
