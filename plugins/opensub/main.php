<?php
// Each player's instance (IINA's plugin-opensub index.js, message.js and sub-provider.js): the sidebar
// (sidebar/index.html) searches opensubtitles.com and logs in; what it asks of the player is called
// by its messages ("#call.$name", answered "#on.$name"), as IINA's rpcClient.
use MyPHP\PlayerPlugins\{Iina, SubtitleApi};

return function (Iina $iina) {
    $core = $iina->core;
    $sidebar = $iina->sidebar;
    $console = $iina->console;
    $preferences = $iina->preferences;
    $utils = $iina->utils;

    $console->log('Plugin is running');

    $keychainAvailable = $preferences->get('save_login') === 'keychain';

    $iina->event->on('iina.menu-update', function () use ($iina, $core, $sidebar) {
        $iina->menu->removeAllItems();
        $iina->menu->addItem($iina->menu->item('Show Sidebar', fn() => $sidebar->show(), ['keyBinding' => 'Meta+p', 'enabled' => (bool)$core->window->visible]));
    });

    // IINA's rpcClient: a function served is called by "#call.$name" and answers "#on.$name"; one called answers so.
    $serve = function (string $name, Closure $fn) use ($sidebar, $console) {
        $sidebar->onMessage("#call.$name", function ($m) use ($name, $fn, $sidebar, $console) {
            $console->log("RPC call: $name");
            $sidebar->postMessage("#on.$name", ['res' => $fn(...(array)($m['args'] ?? []))]);
        });
        $console->log("RPC server registered: $name");
    };

    $getCachedJWT = function () use ($keychainAvailable, $preferences, $utils, $console): ?array {
        if (!$keychainAvailable) {
            $console->log('getJWT: keychain not available');
            return null;
        }
        $username = $preferences->get('username');
        if (!$username) {
            $console->log('getJWT: no username');
            return null;
        }
        $console->log("getJWT: $username");
        $jwt = $utils->keychainRead('opensubtitles', $username);
        $jwt = is_string($jwt) ? $jwt : null;
        $console->log('getJWT: token length: ' . strlen((string)$jwt));
        return ['jwt' => $jwt, 'username' => $username];
    };

    $iina->event->on('iina.window-loaded', function () use ($iina, $core, $sidebar, $console, $preferences, $utils, $serve, $getCachedJWT, $keychainAvailable) {
        $sidebar->loadFile('sidebar/index.html');

        $serve('$sendOSD', fn($message) => $core->osd((string)$message));
        $serve('$getJWT', fn() => $getCachedJWT());
        $serve('$getFileName', fn() => $core->status->title);
        $serve('$setJWT', function ($username, $token) use ($keychainAvailable, $console, $utils, $preferences) {
            if (!$keychainAvailable) {
                $console->log('setJWT: keychain not available');
                return null;
            }
            $console->log("setJWT: $username; token length: " . strlen((string)$token));
            if (!is_string($token) || $token === '') {
                $console->log('setJWT: no token');
                return false;
            }
            if ($utils->keychainWrite('opensubtitles', (string)$username, $token)) {
                $preferences->set('username', $username);
                $preferences->sync();
                return true;
            }
            $console->log('setJWT: keychain write failed');
            return false;
        });
        $serve('$setLanguages', function ($languages) use ($console, $preferences) {
            $console->log("setLanguages: $languages");
            $preferences->set('languages', $languages);
            $preferences->sync();
        });
        $serve('$getLanguages', function () use ($preferences) {
            $res = $preferences->get('languages') ?? '';
            if (!is_string($res) || preg_match('/^[a-z\-]+(,[a-z\-]+)*$/', $res) !== 1) {
                $preferences->set('languages', '');
                $preferences->sync();
                return '';
            }
            return $res;
        });
        $serve('$downloadFile', function ($items) use ($iina, $core, $console, $utils) {
            foreach ((array)$items as $item) {
                $console->log("downloadFile: {$item['link']}, {$item['file_name']}");
                $dest = '@tmp/' . basename((string)$item['file_name']);
                $iina->http->download((string)$item['link'], $dest);
                $core->subtitle->loadTrack($utils->resolvePath($dest));
                $console->log($dest);
            }
        });
        $serve('$openLink', fn($link) => $utils->open((string)$link));

        $sidebar->onMessage('error', fn($error) => $console->error(is_string($error) ? $error : json_encode($error)));

        $sidebar->postMessage('jwt', ['jwt' => $getCachedJWT()]);
    });

    // The provider: its search shows the sidebar, which searches (IINA's CUSTOM_IMPLEMENTATION).
    $iina->subtitle->registerProvider('open-sub', [
        'search' => function () use ($sidebar) {
            $sidebar->show();
            $sidebar->postMessage('#call.$requestDownload', ['args' => []]);
            return SubtitleApi::CUSTOM_IMPLEMENTATION;
        },
        'description' => fn($item) => null,
        'download' => fn($item) => null,
    ]);
    $console->log('Sub provider registered');
};
