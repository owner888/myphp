<?php
// The window's view of the state main.php makes, $this in it (Content::file): saved, it hot reloads.
declare(strict_types=1);

use MyPHP\App;
use MyPHP\UI\{Context, Font, Menu, Node};

return function (Context $c): void {
    $t = $c->theme;
    $c->column(20, 14, 1);
      $c->row(0, 10);
        $c->text('Widgets', 20, style: Font::BOLD);
        if ($c->changed($c->searchField($this->query, 180))) { $this->last = 'search ' . json_encode($this->query->text); }
        $c->spacer(); $c->spinner(); $c->muted('working…');
      $c->end();
      $c->divider();
      // A toolbar: toggles and buttons without a face until hovered.
      $c->toolbar(function () use ($c) {
          if ($c->changed($c->toggle($this->bold, 'Bold'))) { $this->last = 'bold ' . ($this->bold ? 'on' : 'off'); }
          $c->toggle($this->italic, 'Italic');
          $c->divider(true);
          if ($c->clicked($c->button('Share'))) { $c->toast('Shared'); }
          $c->menuButton('View', function (Menu $m) {
              if ($m->item('Icons')->chosen()) { $this->last = 'icons'; }
              if ($m->item('List')->chosen()) { $this->last = 'list'; }
          });
      });
      $c->row(0, 16);
        if ($c->changed($c->switch('Wi-Fi', $this->wifi))) { $this->last = 'Wi-Fi ' . ($this->wifi ? 'on' : 'off'); }
        $c->badge($this->wifi ? 'connected' : 'off');
        $c->spacer();
        if ($c->changed($c->segmented(['List', 'Grid', 'Map'], $this->view))) { $this->last = 'view ' . $this->view; }
      $c->end();
      $c->divider();
      if ($c->changed($c->tabs(['General', 'Advanced', 'About'], $this->tab))) { $this->last = 'tab ' . $this->tab; }
      $c->key('tab' . $this->tab)->column(12, 10, 0, $t->surface)->radius($t->radius); // keyed by tab: each tab's controls keep their own state (hover, focus, locals) apart from the others'
        if ($this->tab === 0) {
            $c->row(0, 10); $c->text('Copies'); $c->text((string)$this->count); if ($c->changed($c->stepper($this->count, 1, 10))) { $this->last = "count {$this->count}"; } $c->end();
            $c->row(0, 10); $c->text('Scale'); if ($c->changed($c->numberInput($this->scale, 0.5, 4, 0.25))) { $this->last = "scale {$this->scale}"; } $c->end();
            $c->row(0, 10); $c->text('Disk'); $c->meter($this->count * 10, 0, 100, new \MyPHP\UI\MeterLevels(80, 95)); $c->muted("{$this->count}0 % used"); $c->end();
            // Icons in the text's color at the font size, or a size and color of their own; avatars from names.
            $c->row(0, 10)->alignItems(Node::CENTER); $c->text('Icons'); $c->icon($this->icons['check']); $c->icon($this->icons['star'], 20, $t->warning); $c->icon($this->icons['bell'], 28); $c->icon($this->icons['star'], 20)->rotate(45); $c->image($this->icons['palette'], 28, 28); $c->image($this->icons['palette'], 28, 28)->grayscale(); $c->avatar('Ada Lovelace'); $c->avatar('Grace Hopper'); $c->avatar('Linus'); $c->end();
            $c->row(0, 10)->alignItems(Node::CENTER); $c->text('Fruit'); if ($c->changed($c->combobox($this->fruit, FRUITS))) { $this->last = "fruit {$this->fruit}"; } $c->end();
            $c->row(0, 10)->alignItems(Node::CENTER); $c->text('City'); $ac = $c->autocomplete($this->city, CITIES, 'City'); if ($c->changed($ac)) { $this->last = "city {$this->city->text}"; } if ($c->submitted($ac)) { $this->last = "city {$this->city->text} ⏎"; } $c->end();
            $c->row(0, 10)->alignItems(Node::CENTER); $c->text('When'); if ($c->changed($c->dateInput($this->when))) { $this->last = 'date ' . $this->when->format('Y-m-d'); } if ($c->changed($c->timeInput($this->when))) { $this->last = 'time ' . $this->when->format('H:i'); } $c->end();
            $c->row(0, 10)->alignItems(Node::START); $c->text('Tags'); if ($c->changed($c->tokenField($this->tags, TAGS))) { $this->last = 'tags ' . implode(', ', $this->tags); } $c->end();
            $c->collapsible('More options', $this->more, function () use ($c) {
                $c->muted('Shown while the section is open; the header toggles it, and it grows into view.');
                $c->row(0, 8); $c->badge('new'); $c->badge('3'); $c->badge('beta'); $c->end();
                // A check box over check boxes: mixed while some are checked; its click checks or clears them all.
                if ($c->changed($c->checkboxGroup('Notifications', function () use ($c) { $c->checkbox('Mail', $this->mail); $c->checkbox('Calendar', $this->calendar); }))) { $this->last = 'notifications ' . (int)$this->mail . (int)$this->calendar; }
            });
        } elseif ($this->tab === 1) {
            // A tree: the arrows open and close, Up/Down/Left/Right move the focus, a click chooses.
            $c->tree(function () use ($c) {
                $item = function (string $path, ?Closure $kids = null) use ($c, &$item) {
                    $name = basename($path);
                    if ($kids === null) { $leaf = null; $n = $c->treeItem($name, $leaf, null, $this->chosen === $path); }
                    else { $this->open[$path] ??= $path === 'src'; $n = $c->treeItem($name, $this->open[$path], $kids, $this->chosen === $path); }
                    if ($c->clicked($n)) { $this->chosen = $path; $this->last = "chose $path"; }
                };
                $item('src', function () use ($item) { $item('src/App.php'); $item('src/UI', function () use ($item) { $item('src/UI/Context.php'); $item('src/UI/Node.php'); }); });
                $item('examples', function () use ($item) { $item('examples/widgets'); });
                $item('README.md');
            });
            // A form: labels in a column of their own, right-aligned, level with their controls; a click on one focuses its control.
            $c->form(function () use ($c) {
                $c->field('Name', function () use ($c) { $c->input($this->name, 'Jane Doe', 0); }, 'Shown on receipts.', trim($this->name->text) === '' ? 'Required' : '');
                $c->field('Size', function () use ($c) { $c->select(['Small', 'Medium', 'Large'], $this->size); });
                $c->fieldset('Notifications', function () use ($c) {
                    $c->field('Mail', function () use ($c) { if ($c->changed($c->checkbox('Send me mail', $this->notify))) { $this->last = 'notify ' . ($this->notify ? 'on' : 'off'); } });
                });
            });
            // A list whose rows drag to another place (Cmd-click chooses several to drag together); the choice follows them by key.
            $c->row(0, 10)->alignItems(Node::START); $c->text('Steps');
              $c->column(0, 0, 0, $t->background)->size(200, 5 * 28 + 2)->radius($t->radius)->border();
                $c->list($this->stepList, count($this->steps), function (int $i) use ($c, $t) { $c->row(0, 0)->size(0, 28)->padding(0, 10)->alignItems(Node::CENTER); $c->text($this->steps[$i]); $c->end(); });
              $c->end();
            $c->end();
            // A calendar: a click or the arrows choose a day, Page Up and Page Down move by months.
            $c->row(0, 10)->alignItems(Node::START); $c->text('Calendar'); if ($c->changed($c->calendar($this->when))) { $this->last = 'calendar ' . $this->when->format('Y-m-d'); } $c->end();
        } else {
            $c->richText([new \MyPHP\UI\Span('MyPHP ', style: Font::BOLD), new \MyPHP\UI\Span(App::VERSION, color: $t->textMuted)]);
            $crumbs = ['Macintosh HD', 'Users', 'developer', 'Documents', 'Widgets']; // a path: the items but the last are links
            if ($c->changed($c->breadcrumbs(array_slice($crumbs, 0, $this->crumb + 1), $this->crumb))) { $this->last = 'went to ' . $crumbs[$this->crumb]; }
            $c->row(0, 10)->alignItems(Node::CENTER); $c->text('Color'); if ($c->changed($c->colorWell($this->color, $this->alpha))) { $this->last = 'color ' . Context::hexOf($this->color, $this->alpha); } $c->muted(Context::hexOf($this->color, $this->alpha)); $c->end();
            // The language the app shows: MyPHP's own strings (the search field, the find bar, the menus) and the date and time formats follow it.
            $c->row(0, 10)->alignItems(Node::CENTER); $c->text('Language');
              $langs = ['en' => 'English', 'zh-Hans' => '简体中文', 'zh-Hant' => '繁體中文']; $lang = array_search(App::locale(), array_keys($langs), true) ?: 0;
              if ($c->changed($c->segmented(array_values($langs), $lang))) { App::setLocale(array_keys($langs)[$lang]); $this->last = 'language ' . App::locale(); }
              $c->muted('region ' . (App::region()->locale ?: '—') . ', ' . App::region()->hourCycle . 'h');
            $c->end();
            $c->row(0, 10); $c->text('Rating'); if ($c->changed($c->rating($this->stars))) { $this->last = "stars {$this->stars}"; } $c->end();
            $c->row(0, 10); $c->text('Range'); if ($c->changed($c->rangeSlider($this->low, $this->high, 0, 100, 5, 240))) { $this->last = sprintf('range %d–%d', $this->low, $this->high); } $c->muted(sprintf('%d–%d', $this->low, $this->high)); $c->end();
            // An accordion: sections in a bordered box, Up and Down between their headers.
            $c->accordion(function () use ($c) {
                $c->accordionItem('Appearance', $this->acc[0], function () use ($c) { $c->muted('Theme, accent, text size.'); });
                $c->accordionItem('Shortcuts', $this->acc[1], function () use ($c) { $c->muted('Cmd+N new, Cmd+S save.'); });
                $c->accordionItem('About', $this->acc[2], function () use ($c) { $c->muted('The widgets example.'); });
            });
        }
      $c->end();
      $c->row(0, 8);
        if ($c->clicked($c->primaryButton('Save'))) { $c->toast('Saved'); $this->last = 'saved'; }
        if ($c->clicked($c->button('Delete…'))) { $this->asking = true; }
        if ($c->clicked($c->button('Reset…'))) { $this->resetting = true; }
        $c->menuButton('Sort by', function (Menu $m) {
            foreach (['Name', 'Date'] as $by) {
                if ($m->item($by)->checked($this->sort === $by)->chosen()) { $this->sort = $by; $this->last = 'sort by ' . strtolower($by); }
            }
        });
        $anchor = $c->button('Options');
        if ($c->clicked($anchor)) { $this->options = !$this->options; }
      $c->end();
      // A popover below the Options button: a press elsewhere closes it.
      $c->popover($anchor, $this->options, function () use ($c) {
          $c->column(8, 8);
            $c->checkbox('Bold titles', $this->bold);
            $c->muted('Sorted by ' . $this->sort, 11);
          $c->end();
      });
      $c->spacer();
      $c->divider();
      $c->muted('last change: ' . $this->last, 12);
    $c->end();
    // A modal over it all: what is behind is inert until it closes.
    $modal = $c->modal($this->asking, function () use ($c) {
        $c->text('Delete the draft?', 15, style: Font::BOLD);
        $c->muted("You can't undo this.");
        $c->row(0, 8)->justify(Node::END);
          if ($c->clicked($c->button('Cancel'))) { $this->asking = false; }
          if ($c->clicked($c->primaryButton('Delete'))) { $this->asking = false; $c->toast('Draft deleted'); $this->last = 'deleted'; }
        $c->end();
    });
    if ($modal !== null && $c->changed($modal)) { $this->last = 'dismissed'; }
    // An alert: the last button is the default, Enter clicks it, Escape chooses none.
    switch ($c->alertDialog($this->resetting, 'Reset all settings?', 'Every control goes back to how it started.', 'Cancel', 'Reset')) {
        case 1: $this->last = 'reset'; $c->toast('Settings reset'); break;
        case Context::ALERT_DISMISSED: $this->last = 'reset dismissed'; break;
    }
};
