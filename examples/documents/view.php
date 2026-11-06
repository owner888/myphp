<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\App;

// A bar while another app changed the note, the text (each change the document's), and its words and state.
return function () {
    $state = $this->doc->state();
    return VStack(spacing: 0)(
        !$state->conflict ? null : HStack(spacing: 8)(
            Text('Another app changed this note. Save to keep yours, or revert to theirs.')->font(Font::callout),
            Spacer(),
            Button('Revert', action: fn() => App::later(fn() => $this->doc->revert())),
            Button('Save', action: fn() => App::later(fn() => $this->doc->saveAnyway()))->buttonStyle(ButtonStyle::borderedProminent),
        )->padding(10)->background(Color::orange->opacity(0.15)),
        TextEditor(text: $this->text)->monospaced()->accessibilityLabel('Note')->defaultFocus()->id('text')
            ->onChange(of: $this->text, action: fn(string $old, string $new) => $this->doc->set($new)),
        HStack(spacing: 12)(
            Text('{count, plural, one {# word} other {# words}}', ['count' => count(preg_split('/\s+/u', $this->text, -1, PREG_SPLIT_NO_EMPTY))]),
            Spacer(),
            Text(match (true) { $state->path === '' => 'Not saved', $state->readOnly => 'Read-only', $state->edited => 'Edited', default => 'Saved' }),
        )->font(Font::caption)->foregroundStyle(Color::secondary)->padding(Edge::vertical, 6)->padding(Edge::horizontal, 12),
    );
};
