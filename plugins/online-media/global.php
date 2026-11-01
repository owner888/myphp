<?php
// The app's instance (IINA's plugin-online-media global.ts and download.ts): Manage yt-dlp and Downloads… opens the window
// (downloads.html) of the yt-dlp used (downloaded, updated, checked) and of the videos downloaded, which a player's instance asks for.
use MyPHP\PlayerPlugins\Iina;
use function MyPHPPlugins\OnlineMedia\{downloadDeno, downloadYTDLP, findBinary, formatFileSize, formatSeconds};

require_once __DIR__ . '/lib.php';

return function (Iina $iina) {
    $console = $iina->console; $global = $iina->global; $menu = $iina->menu; $window = $iina->standaloneWindow; $file = $iina->file; $utils = $iina->utils;
    $downloads = (object)['tasks' => [], 'statusNeedUpdate' => false];

    $updateDownloadsWindow = function () use ($window, $downloads) {
        $active = false;
        foreach ($downloads->tasks as $t) { if ($t->status !== 'done' && $t->status !== 'error') { $active = true; } }
        $window->postMessage('update', ['active' => $active, 'data' => array_map(fn($t) => [
            'url' => $t->url, 'filename' => $t->filename, 'destFolder' => $t->destFolder, 'dest' => "{$t->destFolder}/{$t->filename}", 'status' => $t->status,
            'start' => date(DATE_RFC2822, (int)$t->startTime), 'error' => $t->errorMessage, 'dl' => formatFileSize($t->downloadedBytes), 'total' => formatFileSize($t->totalBytes),
            'eta' => formatSeconds($t->eta),
        ], $downloads->tasks)]);
    };

    // A video downloaded by yt-dlp into ~/Downloads (IINA's DownloadTask), its progress read off what it prints.
    $startTask = function (object $t) use ($iina, $global, $console, $utils, $downloads, $updateDownloadsWindow) {
        $t->startTime = time();
        $args = ['-P', $t->destFolder];
        if ($t->jsRuntime) { array_push($args, '--js-runtimes', $t->jsRuntime); }
        array_push($args, '--progress-template', '!!%(progress.downloaded_bytes)s-%(progress.total_bytes)s-%(progress.eta)s', '--', $t->url);
        $t->status = 'downloading';
        $updateDownloadsWindow();
        $iina->setTimeout(function () use ($t, $args, $global, $console, $utils, $downloads, $updateDownloadsWindow) {
            try {
                $res = $utils->exec($t->ytdl, $args, null, function (string $data) use ($t, $downloads) {
                    foreach (preg_split('/\r|\n/', $data) ?: [] as $line) {
                        $line = trim($line);
                        if (!str_starts_with($line, '!!')) { continue; }
                        [$downloaded, $total, $eta] = explode('-', substr($line, 2)) + ['NA', 'NA', 'NA'];
                        if ($downloaded !== 'NA') { $t->downloadedBytes = (int)$downloaded; }
                        if ($total !== 'NA') { $t->totalBytes = (int)$total; }
                        if ($eta !== 'NA') { $t->eta = (int)$eta; }
                        $downloads->statusNeedUpdate = true;
                    }
                }, null);
                $console->log('Download finished');
                if (($res['status'] ?? 1) === 0) {
                    $t->status = 'done';
                    $global->postMessage($t->player, 'downloaded', true);
                } else {
                    $t->status = 'error';
                    $t->errorMessage = $res['stderr'] ?? '';
                }
            } catch (\Throwable $e) {
                $t->status = 'error';
                $t->errorMessage = $e->getMessage();
            }
            $updateDownloadsWindow();
        }, 0);
    };

    $downloadVideo = function (string $url, string $player) use ($iina, $console, $utils, $downloads, $startTask) {
        ['path' => $path, 'jsRuntime' => $jsRuntime] = findBinary($iina);
        $resolved = $jsRuntime ? (string)$utils->resolvePath($jsRuntime) : '';
        $filename = str_replace("\n", '', (string)($utils->exec($path, ['--js-runtimes', $jsRuntime, '--get-filename', $url])['stdout'] ?? ''));
        $console->log($filename);
        $task = (object)['player' => $player, 'url' => $url, 'filename' => $filename, 'destFolder' => rtrim((string)getenv('HOME'), '/') . '/Downloads', 'ytdl' => $path,
            'jsRuntime' => $resolved, 'status' => 'pending', 'errorMessage' => null, 'downloadedBytes' => 0, 'totalBytes' => 0, 'eta' => null, 'startTime' => time()];
        $downloads->tasks[] = $task;
        $startTask($task);
    };

    $showDownloadsWindow = function () use ($iina, $console, $global, $window, $file, $utils, $downloads, $updateDownloadsWindow) {
        $window->loadFile('downloads.html');
        $window->setProperty(['title' => 'Downloads', 'resizable' => true, 'fullSizeContentView' => false, 'hideTitleBar' => false]);
        $window->setFrame(400, 460);
        $window->onMessage('requestUpdate', function ($m) use ($downloads, $updateDownloadsWindow) {
            if (!($m['force'] ?? false) && !$downloads->statusNeedUpdate) { return; }
            $updateDownloadsWindow();
            $downloads->statusNeedUpdate = false;
        });
        $window->onMessage('openFile', fn($m) => $global->createPlayerInstance(['url' => $m['file']]));
        $window->onMessage('revealFile', fn($m) => $file->showInFinder($m['fileName']));
        $window->onMessage('showBinaryInFinder', fn() => $file->showInFinder('@data/'));
        $window->onMessage('getBinaryPath', fn() => $window->postMessage('binaryPath', findBinary($iina)));
        $window->onMessage('getBinaryInfo', function () use ($iina, $console, $window, $utils) {
            ['path' => $path, 'jsRuntime' => $jsRuntime] = findBinary($iina);
            $console->log("Binary path: $path");
            try {
                $res = $utils->exec($path, ['--version']);
            } catch (\Throwable $e) {
                $res = ['status' => -1, 'stdout' => '', 'stderr' => $e->getMessage()];
            }
            if (($res['status'] ?? 1) === 0) {
                $console->log("Version: {$res['stdout']}");
                $window->postMessage('binaryInfo', ['path' => $path, 'version' => $res['stdout'], 'jsRuntime' => $jsRuntime, 'errorMessage' => '']);
            } else {
                $error = 'Error when executing the binary: ' . (($res['stderr'] ?? '') !== '' ? $res['stderr'] : 'No error message');
                $console->log($error);
                $window->postMessage('binaryInfo', ['path' => $path, 'version' => '', 'jsRuntime' => $jsRuntime, 'errorMessage' => $error]);
            }
        });
        $updateBinary = function () use ($iina, $window) {
            $window->postMessage('updatingBinary', null);
            $error = downloadYTDLP($iina);
            $window->postMessage('binaryUpdated', ['updated' => $error === null, 'error' => $error]);
        };
        $window->onMessage('downloadBinary', $updateBinary);
        $window->onMessage('updateManagedBinary', $updateBinary);
        $window->onMessage('downloadDeno', function () use ($iina, $window) {
            $window->postMessage('downloadingDeno', null);
            $error = downloadDeno($iina);
            $window->postMessage('denoDownloaded', ['downloaded' => $error === null, 'error' => $error]);
        });
        $window->open();
    };

    $menu->addItem($menu->item('Manage yt-dlp and Downloads...', fn() => $showDownloadsWindow()));
    $global->onMessage('showWindow', fn() => $showDownloadsWindow());
    $global->onMessage('downloadVideo', function ($url, $player) use ($console, $global, $downloadVideo, $showDownloadsWindow) {
        if ($url && $player) {
            $downloadVideo((string)$url, (string)$player);
            $global->postMessage($player, 'downloading', true);
            $showDownloadsWindow();
        } else {
            $console->error("Invalid parameters for downloadVideo: url=$url, player=$player");
        }
    });
};
