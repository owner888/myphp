<?php
// The app's instance (IINA's plugin-userscript global.js): Manage User Scripts… opens the window of the scripts (standalone/index.html), whose messages keep the list and the scripts' code.
use MyPHP\PlayerPlugins\Iina;
use function MyPHPPlugins\UserScript\{load, save};

require_once __DIR__ . '/scripts.php';

return function (Iina $iina) {
    $window = $iina->standaloneWindow;
    $file = $iina->file;

    $window->loadFile('standalone/index.html'); // first: a page loaded clears the listeners (IINA's)
    $window->setProperty(['title' => 'User Scripts']);

    $window->onMessage('load-scripts', fn() => $window->postMessage('load-scripts-done', load($iina)));
    $window->onMessage('save-scripts', function ($scripts) use ($iina, $window) {
        if (!is_array($scripts)) { throw new InvalidArgumentException('saveUserScripts: scripts should be an array'); }
        save($iina, $scripts);
        $window->postMessage('save-scripts-done', null);
    });
    $window->onMessage('get-script-content', function ($m) use ($iina, $file, $window) {
        $path = "@data/{$m['id']}.php";
        if (!$file->exists($path)) {
            $iina->console->log("File $path doesn't exist; creating.");
            $file->write($path, '');
        }
        $window->postMessage('get-script-content-done', ['content' => (string)$file->read($path)]);
    });
    $window->onMessage('get-script-info', function ($m) use ($iina, $window) {
        $found = null;
        foreach (load($iina) as $s) { if ($s['id'] === $m['id']) { $found = $s; } }
        $window->postMessage('get-script-info-done', $found);
    });
    $window->onMessage('set-script-info', function ($m) use ($iina, $window) {
        $id = $m['id'];
        unset($m['id']);
        save($iina, array_map(fn(array $s) => $s['id'] === $id ? array_merge($s, $m) : $s, load($iina)));
        $window->postMessage('set-script-info-done', []);
    });
    $window->onMessage('set-script-content', function ($m) use ($iina, $file, $window) {
        $path = "@data/{$m['id']}.php";
        if (!$file->exists($path)) {
            $iina->console->log("File $path doesn't exist; creating.");
            $file->write($path, '');
        }
        $file->write($path, (string)$m['content']);
        $window->postMessage('set-script-content-done', []);
    });


    $iina->menu->addItem($iina->menu->item('Manage User Scripts...', fn() => $window->open(), ['keyBinding' => 'Meta+Shift+U']));
};
