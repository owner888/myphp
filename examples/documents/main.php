<?php
// A notes editor of Markdown and text files in native UI, built on
// Documents (mygo's examples/documents): the File menu, a window per
// note, autosave, recovery after a crash, the proxy icon and the edited
// dot, notes changed by other apps.
// Run: ./run.sh documents [file.md…]
declare(strict_types=1);

use MyPHP\{App, Document, Documents, DocumentsOptions, DocumentType, Window, WindowOptions};
use MyPHP\UI\{Content, Context, Node, TextState};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

function note(Context $c, Document $doc, TextState $text): void
{
    $t = $c->theme;
    $state = $doc->state();
    $c->column(0, 0, 1);
      if ($state->conflict) {
          $c->row(10, 8)->background($t->warning, 0.15)->align(Node::CENTER);
            $c->text('Another app changed this note. Save to keep yours, or revert to theirs.', 13);
            $c->spacer();
            if ($c->clicked($c->button('Revert'))) { App::later(fn() => $doc->revert()); }
            if ($c->clicked($c->primaryButton('Save'))) { App::later(fn() => $doc->saveAnyway()); }
          $c->end();
      }
      $c->key('text')->textarea($text, '', 0, 0, 14)->label('Note')->autoFocus(); // 0, 0: fills the window
      if ($text->text !== $doc->content()) {
          $doc->set($text->text);
      }
      $c->row(6, 12)->align(Node::CENTER);
        $c->muted(sprintf('%d words', count(preg_split('/\s+/u', $text->text, -1, PREG_SPLIT_NO_EMPTY))), 12);
        $c->spacer();
        $c->muted(match (true) { $state->path === '' => 'Not saved', $state->readOnly => 'Read-only', $state->edited => 'Edited', default => 'Saved' }, 12);
      $c->end();
    $c->end();
}

$notes = new Documents(new DocumentsOptions(
    types: [new DocumentType('Markdown Document', ['md', 'markdown']), new DocumentType('Plain Text', ['txt'])],
    read: fn(string $bytes): string => $bytes,
    write: fn(string $text): string => $text,
    new: fn(): string => '',
    // Undoing back to what the file has leaves the note unedited.
    equal: fn(string $a, string $b): bool => $a === $b,
    window: function (Document $doc): Window {
        $text = new TextState($doc->content());
        $text->multiline = true;
        $w = new Window(new WindowOptions(width: 640, height: 480, stateKey: 'note', content: Content::view(fn(Context $c) => note($c, $doc, $text))));
        // Reverted, or changed by another app: show the file's text.
        $doc->onReload(function () use ($doc, $text, $w): void { $text->text = $doc->content(); $text->setCursor(0); $w->invalidate(); });
        $doc->onStateChange(fn() => $w->invalidate());
        return $w;
    },
));

// Without a bundle, which declares the files the app opens, the command line's notes open here.
App::whenReady(function () use ($notes): void {
    foreach (array_slice($_SERVER['argv'], 1) as $path) {
        if (is_file($path)) { $notes->open($path); }
    }
});
App::run();
