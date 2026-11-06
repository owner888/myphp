<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\{Clipboard, ClipboardOptions};
use MyPHP\Transfer\{Data, DropOptions, Format};
use MyPHP\UI\{Context, Font};

return function (Context $c): void {
    $t = $c->theme;
    $c->column(20, 12, 1);
      $c->text('Clipboard and drag data', 24, style: Font::BOLD);
      $c->muted('Copy or drag the same note as text, HTML, or lazy JSON. Paste in another app to use its preferred representation.');
      $c->input($this->title, 'Title', 0);
      $c->textarea($this->body, '', 0, 90);
      $c->row(0, 8);
        if ($c->clicked($c->button('Copy note'))) {
            try {
                Clipboard::write($this->data(), new ClipboardOptions(onRelease: function () { $this->releases++; $this->win?->invalidate(); }));
                $this->status = 'Copied; JSON is produced only if requested';
            } catch (\RuntimeException $e) {
                $this->status = $e->getMessage();
            }
        }
        if ($c->clicked($c->button('Paste'))) {
            $preferred = Data::preferredFormat(Clipboard::formats(), NOTE_FORMAT, Format::TEXT, Format::FILE_LIST, Format::URI_LIST);
            if ($preferred === null) {
                $this->status = 'No supported clipboard format';
            } else {
                try { $this->receive(Clipboard::read($preferred)); } catch (\RuntimeException $e) { $this->status = $e->getMessage(); }
            }
        }
        if ($c->clicked($c->button('Flush'))) {
            try { Clipboard::flush(); $this->status = 'Clipboard persisted; provider resources released'; } catch (\RuntimeException $e) { $this->status = $e->getMessage(); }
        }
      $c->end();
      $note = $c->column(16)->border(1, $t->border)->radius(8);
        $c->text('Drag this note', style: Font::BOLD);
      $c->end();
      $c->dragDataFrom($note, $this->data(...));
      $o = new DropOptions([NOTE_FORMAT, Format::TEXT, Format::FILE_LIST, Format::URI_LIST]);
      $zone = $c->column(16)->border(1, $t->border)->radius(8);
        $c->text('Drop notes, text, or files here');
      $c->end();
      if ($c->dataDragOver($zone, $o)) { $zone->border(2, $t->accent); }
      if ($c->dropData($zone, $o, $drop)) { $this->receive($drop->data); }
      $c->row(0, 8);
        $c->input($this->path, 'Absolute file path', 0);
        if ($c->clicked($c->button('Copy file'))) {
            try { Clipboard::writeFiles($this->path->text); $this->status = 'Copied native file list'; } catch (\InvalidArgumentException | \RuntimeException $e) { $this->status = $e->getMessage(); }
        }
      $c->end();
      $c->muted("JSON requests: {$this->providers} · Clipboard releases: {$this->releases}");
      $c->text($this->status);
    $c->end();
};
