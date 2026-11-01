<?php
namespace MyPHPPlugins\UserScript;

// The list of the user's scripts (IINA's plugin-userscript utils.js): @data/script-list.json, made where it is none, kept aside and made anew where it does not read.
use MyPHP\PlayerPlugins\Iina;

const SCRIPT_LIST_PATH = '@data/script-list.json';

/** @return list<array{id: string, name: string}> */
function load(Iina $iina): array
{
    if (!$iina->file->exists(SCRIPT_LIST_PATH)) { $iina->file->write(SCRIPT_LIST_PATH, '[]'); }
    $content = (string)$iina->file->read(SCRIPT_LIST_PATH);
    $scripts = json_decode($content, true);
    if (!is_array($scripts)) {
        $iina->console->log('Cannot parse the script list - will recreate one');
        $iina->file->write(SCRIPT_LIST_PATH . '.backup', $content);
        $iina->file->write(SCRIPT_LIST_PATH, '[]');
        return [];
    }
    $iina->console->log('Scripts loaded: ' . count($scripts));
    return array_values($scripts);
}

/** @param list<array<string, mixed>> $scripts */
function save(Iina $iina, array $scripts): void
{
    $iina->file->write(SCRIPT_LIST_PATH, json_encode(array_values($scripts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
