# Documents

Apps whose windows each show a file, such as a text editor, a drawing app
or a notes app, are document-based apps. `MyPHP\Documents` (mygo's
`NewDocuments`) gives them what AppKit's `NSDocument` and SwiftUI's
`DocumentGroup` give Mac apps:

- a window per document, with its name in the title, the file's icon in
  the title bar and a dot in the close button while it has unsaved changes;
- New, Open, Open Recent, Save, Save As, Duplicate, Rename, Move To and
  Revert to Saved, in the File menu;
- autosave, and asking to save only where saving can't happen by itself;
- a recovery copy of every unsaved change, so that a crash or a quit loses
  nothing;
- the documents opened from the Finder and the Dock;
- the files that other apps change while they are open.

A notes editor in native UI, the whole app:

```php
$notes = new Documents(new DocumentsOptions(
    types: [new DocumentType('Markdown Document', ['md'])],
    read: fn(string $bytes): string => $bytes,
    write: fn(string $text): string => $text,
    new: fn(): string => '',
    equal: fn(string $a, string $b): bool => $a === $b,
    window: function (Document $doc): Window {
        $text = new TextState($doc->content());
        $text->multiline = true;
        $w = new Window(new WindowOptions(content: Content::view(function (Context $c) use ($doc, $text) {
            $c->textarea($text, '', 0, 0);
            if ($text->text !== $doc->content()) { $doc->set($text->text); }
        })));
        $doc->onReload(function () use ($doc, $text, $w) { $text->text = $doc->content(); $w->invalidate(); });
        return $w;
    },
));
App::run();
```

`examples/documents` is this editor with a status line and a bar for files
changed by other apps: `./run.sh documents [file.md…]`.

## Documents and their content

`DocumentsOptions` describes one kind of document:

| field | |
|---|---|
| `types` | the kinds of files, by name and extensions, e.g. `new DocumentType('Markdown Document', ['md', 'markdown'])`. Open dialogs show them; new documents are saved with the first extension |
| `read` | makes a content of a file's bytes; throwing says why it can't |
| `write` | makes a file's bytes of a content |
| `window` | creates the window of a document |
| `new` | the content of a new document; null without it |
| `equal` | compares contents, so that undoing back to what the file has leaves the document unedited |
| `snapshot` | copies a mutable content (an object the app changes) before it is written |
| `autosave` | `Autosave::InPlace` (the default) or `Autosave::Off` |
| `autosaveDelay` | how long after the last edit an autosave happens: 5 seconds |
| `askOnQuit` | asks to save at quit, rather than keeping the documents for the next launch |
| `noUntitled` | no untitled document when the app starts with nothing to open |

Make the `Documents` before `App::run`. When the app starts, it opens the
files the app was started with, the documents a crash or a quit left with
unsaved changes, or else a new untitled document. An app may make several
kinds of documents; the first is the one File > New creates.

A `Document` is an open document:

```php
$doc->content();   // the content
$doc->set($v);     // replaces it, which edits the document
$doc->state();     // name, path, edited, readOnly, conflict, deleted
$doc->window();    // its window
$doc->save();      // as File > Save
```

The content is best a value the app replaces with `set()`: a string, an
array. A mutable content, an object the app changes in place, gives
`snapshot`, which copies it for `write`, and tells about changes with
`changed()`.

A document is edited when its content differs from its file's: `set()` and
`changed()` edit it; with `equal`, a `set()` of the content the file has
makes it unedited again, which is what undoing back to it does.
`setEdited()` sets it, for an app that keeps its own undo history: `false`
makes the content what the file has, without writing it. Saving makes it
unedited, unless it changed while it was written: those changes stay
unsaved. `onStateChange()` tells about changes of the state, on the next
turn of the run loop.

## Saving

| | |
|---|---|
| `save()` | writes the document to its file. An untitled document asks where, a read-only one asks for another file, and one whose file another app changed asks whether to overwrite it. `DocumentCanceled` says the user canceled |
| `saveAs($path)` | writes it to another file, which the document then is; `''` asks where |
| `saveAnyway()` | writes it even when another app changed its file |
| `duplicate()` | opens an untitled copy, named "Notes copy" |
| `rename($name)` | renames its file, in its folder; `''` asks |
| `moveTo($dir)` | moves its file to another folder; `''` asks |
| `revert()` | reads its file again, dropping its unsaved changes |
| `close()` | closes its window, which asks to save first where it must |

Files are written as `NSDocument` writes them: into a new file next to the
document, flushed to the disk, which then replaces it through
`NSFileManager`, which keeps its permissions, creation date, Finder tags
and other extended attributes, ACLs, and its identity, so aliases still
find it. A symbolic link's target is replaced, and a file with other hard
links, or in a folder the user can't create files in, is written in place.
Before writing, the file is compared with what the document last read or
wrote (its modification time and size): when another app changed it,
`save()` asks, and autosave writes nothing.

## Autosave and recovery copies

With `Autosave::InPlace`, a document is saved to its file a moment after
the user stops editing (`autosaveDelay`, and at least every 30 seconds
while the user keeps editing), when its window loses the keyboard, and
when it closes. Saved documents never ask to be saved. Those that can't be
saved in place aren't: untitled documents, documents whose files are
read-only, were deleted, or were changed by another app; those ask when
they close. With `Autosave::Off`, documents are saved when the user saves
them, and ask when they close.

Every document with unsaved changes that autosave doesn't write keeps a
recovery copy, a second after the user stops editing: in
`Recovery/<kind>` of `App::userDataPath()`, with the file's modification
time and size. It goes when the document is saved, reverted or closed
without saving. When the app starts, the documents a crash or a forced
quit left come back, edited; one whose file changed meanwhile is in
conflict. Each running instance keeps its copies in a session of its own,
locked while it runs.

## Closing and quitting

| | asks |
|---|---|
| closing an unedited document | nothing |
| closing a saved, edited document, with autosave | nothing: it is saved first |
| closing an edited untitled document, with autosave | "Do you want to keep this new document “Untitled”?" Save…, Delete, Cancel |
| closing an edited document without autosave, or one that can't be saved in place | "Do you want to save the changes made to the document “Notes.md”?" Save, Don't Save, Cancel |
| quitting | nothing: the documents are saved in place or kept, and come back edited at the next launch |
| quitting with `askOnQuit`, several documents | "You have 3 documents with unsaved changes. Do you want to review these changes before quitting?" Review Changes…, Discard Changes, Cancel, then a sheet for each |

The questions are MyPHP's own strings (`L10n`), in the language the app
shows, as sheets of the document's window. A close that must ask keeps the
window open until the user answers; a quit waits for the documents
(`App::onBeforeQuit`), then quits again. A window destroyed without asking
(`Window::destroy`) keeps the recovery copy of its document's changes.
The app stays open without windows, and clicking its Dock icon makes an
untitled document (`App::onActivate`).

## Files changed by other apps

Documents watch their files (`App::watchFiles`), and look at them when
their window gets the keyboard, since watches miss changes. A document
without unsaved changes reads its file again, and `onReload()` tells the
app to show the new content; one with unsaved changes is in conflict, and
saving asks whether to keep it or the other app's ("Save Anyway",
"Revert"); a file renamed by another app, in its folder, is followed; a
deleted file leaves its document open, `deleted` in its state, and saving
writes the file again.

## The File menu

The menu roles of documents act on the document of the window that has
the keyboard, and are enabled for it: `new` (⌘N), `open` (⌘O),
`openRecent`, `save` (⌘S), `saveAs` (⌥⇧⌘S), `duplicate` (⇧⌘S), `rename`,
`moveTo`, `revert` (Revert to Saved). `fileMenu` holds DocumentGroup's File
menu when the app has documents: New, Open, Open Recent, Close, Save,
Duplicate, Rename, Move To, Revert to Saved. Open Recent lists
`App::recentDocuments()`, which documents add their files to, and its
Clear Menu calls `App::clearRecentDocuments()`.

Documents open the files the system asks the app to open
(`App::onOpenFile`) of their extensions; list the types in
`fileAssociations` of myphp.json, which `myphp build` declares in the
bundle. A file that is open already focuses its window.

## Web pages

The pages of document windows get their documents through the generated
TypeScript client: bind the service of the documents,
`Ipc::bind($notes->service(), 'Note')`, and the page reads and replaces
the content of its window's document, and follows its state:

```ts
editor.value = await Note.content() as string;
editor.oninput = () => Note.setContent(editor.value);
window.myphp.on("myphp:document-state", (s) => { status.textContent = (s as DocumentState).edited ? "Edited" : "Saved" });
window.myphp.on("myphp:document-reload", async () => { editor.value = await Note.content() as string });
```

The service has `content`, `setContent`, `state`, `save`, `saveAs`,
`duplicate`, `rename`, `moveTo` and `revert`, for the window that calls
them. The events reach trusted pages only (`Window::isTrusted`): the app's
own, and WindowOptions' `trustedOrigins`.

## What is left out

What only a real `NSDocument` does: renaming in the title bar's popover,
"— Edited" next to the title, browsing versions, locking files, iCloud
drafts; documents that are folders (packages). See docs/mygo-deviations.md
for what differs from mygo's.
