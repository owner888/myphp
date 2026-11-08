<?php
// A notes editor of Markdown and text files in native UI, built on
// Documents (mygo's examples/documents): the File menu, a window per
// note, autosave, recovery after a crash, the proxy icon and the edited
// dot, notes changed by other apps.
// Run: myphp run -d macos examples/documents [file.md…]
declare(strict_types=1);

use MyPHP\{App, Document, Documents, DocumentsOptions, DocumentType, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php'; // MyPHP, and its declarative views (VStack, Text…)

/** A note's window: its document, and the text being edited, which view.php shows. */
final class Note
{
    public function __construct(public Document $doc, public string $text) {}
}

$notes = new Documents(new DocumentsOptions(
    types: [new DocumentType('Markdown Document', ['md', 'markdown']), new DocumentType('Plain Text', ['txt'])],
    read: fn(string $bytes): string => $bytes,
    write: fn(string $text): string => $text,
    new: fn(): string => '',
    // Undoing back to what the file has leaves the note unedited.
    equal: fn(string $a, string $b): bool => $a === $b,
    window: function (Document $doc): Window {
        $note = new Note($doc, $doc->content());
        $w = new Window(new WindowOptions(width: 640, height: 480, stateKey: 'note', content: Content::file(__DIR__ . '/view.php', $note)));
        // Reverted, or changed by another app: show the file's text.
        $doc->onReload(function () use ($doc, $note, $w): void { $note->text = $doc->content(); $w->invalidate(); });
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
