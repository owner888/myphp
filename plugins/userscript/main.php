<?php
// Each player runs the user's scripts (IINA's plugin-userscript index.js): a script is PHP code run with $iina, the plugin's Iina.
use MyPHP\PlayerPlugins\Iina;
use function MyPHPPlugins\UserScript\load;

require_once __DIR__ . '/scripts.php';

return function (Iina $iina) {
    $scripts = load($iina);
    foreach ($scripts as ['id' => $id, 'name' => $name]) {
        $iina->console->log("Running script \"$name\", id=$id");
        $path = "@data/$id.php";
        if (!$iina->file->exists($path)) {
            $iina->console->log("File $path doesn't exist; skipped.");
            continue;
        }
        $content = (string)$iina->file->read($path);
        try {
            (static function (Iina $iina) use ($content): void { eval($content); })($iina);
        } catch (\Throwable $e) {
            $iina->console->error("Error running script \"$name\", id=$id: {$e->getMessage()}");
        }
    }
};
