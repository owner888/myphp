# Clipboard and drag data

`MyPHP\Transfer` describes serialized content shared by the system
clipboard and native [drag and drop](ui/drag-and-drop.md). A `Data` value
contains ordered items. Each `Item` offers alternative formats
(`Representation`s) for the **same** content; separate items describe
separate values. Custom formats use MIME names and explicit encodings.
Native data never contains a PHP value of the process.

```php
use MyPHP\Clipboard;
use MyPHP\ClipboardOptions;
use MyPHP\Transfer\{Data, Format, Item, Representation};

$noteFormat = 'application/vnd.example.note+json';
$frozen = ['title' => 'Groceries', 'body' => 'Milk']; // arrays are copies: frozen as made
$data = new Data(new Item(
    Representation::bytes(Format::TEXT, $frozen['title']),
    Representation::bytes(Format::HTML, '<b>' . htmlspecialchars($frozen['title']) . '</b>'),
    Representation::lazy($noteFormat, fn() => json_encode($frozen, JSON_THROW_ON_ERROR)),
));

Clipboard::write($data, new ClipboardOptions(
    onRelease: function () { /* release resources captured by these providers */ },
));
// In a view: ->draggable($data) — the same model, with an independent provider cache.
```

Empty bytes are valid. `Format::TEXT`, `HTML`, `PNG`, `URI_LIST`,
`FILE_LIST` and `MARKDOWN` name the common representations. `Data::text()`,
`Data::url()` and `Data::files()` construct common offers. Files are
absolute paths encoded as escaped file URLs; `paths()` accepts local paths
only, ignoring remote authorities and non-file URLs. Files are neither read
nor deleted.

## Clipboard discovery and reading

`Clipboard::formats()` returns portable names without requesting
representations. `Data::preferredFormat()` and `Offer::preferred()`
negotiate in receiver preference order. `availableFormats()` keeps the
platform's conventions: native UTIs on macOS, GTK targets on Linux.

Request only formats the receiver can use:

```php
$formats = Clipboard::formats();
if (($format = Data::preferredFormat($formats, $noteFormat, Format::HTML, Format::TEXT)) !== null) {
    $bytes = Clipboard::readFormat($format);
    // Decode the explicitly agreed encoding.
}

// Read accepted alternatives together; other providers are not requested.
$data = Clipboard::read($noteFormat, Format::HTML, Format::TEXT);
if (($format = $data->preferred($noteFormat, Format::HTML, Format::TEXT)) !== null) {
    $bytes = $data->read($format);
}
```

`read()` without arguments copies all advertised representations. An empty
clipboard returns empty data. An explicit request with no matching format
throws `FormatUnavailable`. `readFormat()` coalesces items as
`Data::read()` does: text joins with newlines, URI and file lists with
CRLF, and other formats use the first matching item.

Reads return materialized data with no native providers. It remains usable
after the owner changes, a window closes, or the source application exits,
and can be offered in a later drag. Reads that observe ownership changing
throw `ClipboardChanged`. Clipboard access from a clipboard provider
throws `ClipboardReentrant`, preventing recursive reads and replacement.

`readText()`, `writeText()`, `readHTML()`, `writeHTML()`, `readImage()`,
`writeImage()` and `clear()` keep their simple forms. `writeHTML()` offers
HTML and a text fallback. `writeImage()` validates the PNG before replacing
existing data. `writeFiles()` and `readFiles()` exchange native file lists.

## Lazy providers and ownership

Each write and each drag takes a new `Data::snapshot()`. A lazy provider
runs at most once for that snapshot when requested; success and errors are
cached. Discovery and preference negotiation request no bytes. Native
receivers, including clipboard managers, may request bytes immediately
after publication. `read()` requests only its selected formats. A provider
error rejects that read; other representations remain available.

Providers execute on the main thread and must return promptly. Prepare
costly content beforehand, and freeze the value shared by alternative
representations. Never read the same provider recursively, or access the
clipboard from a clipboard provider.

Clipboard ownership belongs to the **application**, independent of any
source window. Closing a window leaves its copied content and providers
available. Keep provider resources alive until
`ClipboardOptions::$onRelease`, which runs once on the main thread.
Replacement, `clear()`, a successful `flush()`, and app shutdown release
providers. Drag sources instead have their `DragOptions::$done` callback.

`Clipboard::flush()` resolves every owned representation before handing it
to native storage, so that it outlives the app. Quitting flushes
automatically on a best-effort basis and then releases remaining provider
resources. On Linux a clipboard manager is required for persistence after
exit.

## Native interoperability

| Platform | Clipboard | Representations |
|---|---|---|
| macOS | `NSPasteboard` with pasteboard item providers | Text, HTML, PNG and file URLs use system UTIs; MIME names map through `UTType`, with a reversible identifier fallback. Item boundaries are preserved. |
| Linux | GTK 3 clipboard selections | MIME targets, UTF8_STRING for text, text/uri-list for URLs and files. |

The [clipboard example](../examples/clipboard) and the [drag-drop
example](../examples/drag-drop) demonstrate these operations.
