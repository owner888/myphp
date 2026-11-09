<?php
// Text fields and a text area in native UI: click or Tab to focus, type
// (input methods included: try Pinyin), select with the mouse or Shift+arrows,
// Cmd+A/C/X/V/Z through the Edit menu, Enter submits a field; its text in
// English, Chinese or Spanish (locales/, as SwiftUI's String Catalog). The
// window's view is view.php, of the Profile this file makes.
// Run: myphp dev examples/form
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\UI\Content;

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/locales/Locales.php'; // the catalogs, which myphp dev writes from locales/*.json

final class Profile
{
    public string $name = 'Ada', $city = '', $note = '', $draft = '', $log = '';
    /** What the last line says: nothing yet, the greeting of a submit, or the card activated. */
    public string $greeting = '';
    public int $submits = 0;
    public bool $subscribe = false;
    /** The theme: light, dark, or following the system. */
    public string $mode = 'system';
    public float $volume = 40.0;
    /** The language the window shows, a BCP 47 tag of locales/. */
    public string $lang = 'en';

    public function submit(): void
    {
        $this->submits++;
        $this->greeting = 'hello';
    }

    public function clear(): void
    {
        [$this->name, $this->city, $this->note, $this->greeting] = ['', '', '', ''];
    }

    /** Sends the message being written to the log below it. */
    public function send(): void
    {
        if (trim($this->draft) !== '') { $this->log .= ($this->log === '' ? '' : "\n") . trim($this->draft); }
        $this->draft = '';
    }

    public function activate(): void
    {
        $this->submits++;
        $this->greeting = 'card';
    }
}

$s = new Profile();
App::setName('Form');
App::whenReady(function () use ($s) {
    new Window(new WindowOptions(title: 'MyPHP native — text input', width: 600, height: 560,
        content: Content::file(__DIR__ . '/view.php', $s)));
});
App::run();
