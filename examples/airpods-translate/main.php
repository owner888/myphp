<?php
// AirPods Real-time Translation via Menu Bar Tray.
// Auto-detect spoken language + Gemini 2.5 Flash bi-directional translation.
// Run: myphp run -d macos examples/airpods-translate
declare(strict_types=1);

use MyPHP\{ActivationPolicy, App, Clipboard, Menu, MenuItem, Tray, TrayOptions};
use Examples\AirPodsTranslate\AudioDeviceDetector;

spl_autoload_register(function (string $class) {
    if (str_starts_with($class, 'Examples\\AirPodsTranslate\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('Examples\\AirPodsTranslate\\')) . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
    $file = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

App::setActivationPolicy(ActivationPolicy::Accessory);

App::whenReady(function () {
    $targetLocale = 'auto'; // Default: auto bi-directional translation
    $isListening = true;
    $currentDevice = AudioDeviceDetector::defaultInputName();
    $isAirPods = AudioDeviceDetector::isAirPods($currentDevice);

    $lastSource = '';
    $lastTranslated = '';
    /** @var list<array{time: string, src: string, dst: string}> */
    $history = [];

    // Ensure ASR binary is built
    $asrBin = __DIR__ . '/asr_engine';
    $asrSwift = __DIR__ . '/asr_engine.swift';
    if (!file_exists($asrBin) || filemtime($asrSwift) > filemtime($asrBin)) {
        exec("swiftc -O " . escapeshellarg($asrSwift) . " -o " . escapeshellarg($asrBin));
    }

    $tray = new Tray(new TrayOptions(
        systemIcon: $isAirPods ? 'airpodspro' : 'waveform.circle',
        topTitle: 'AirPods 智能翻译',
        bottomTitle: '自动识别说话语言...',
        toolTip: "AirPods 实时智能双向同传 (Gemini 2.5 Flash)\n当前输入: {$currentDevice}"
    ));

    $asrProc = null;
    $asrPipes = [];
    $readBuffer = '';

    $startASR = function () use (&$asrProc, &$asrPipes, &$readBuffer, &$targetLocale, $asrBin) {
        if ($asrProc !== null) {
            foreach ($asrPipes as $p) {
                if (is_resource($p)) fclose($p);
            }
            $asrPipes = [];
            proc_terminate($asrProc);
            proc_close($asrProc);
            $asrProc = null;
        }
        $cmd = [$asrBin, '--target', $targetLocale];
        $asrProc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ], $asrPipes, null, array_merge($_ENV, [
            'GEMINI_API_KEY' => getenv('GEMINI_API_KEY') ?: '',
        ]));
        if ($asrProc !== false) {
            stream_set_blocking($asrPipes[1], false);
            stream_set_blocking($asrPipes[2], false);
            $readBuffer = '';
        }
    };

    $stopASR = function () use (&$asrProc, &$asrPipes) {
        if ($asrProc !== null) {
            foreach ($asrPipes as $p) {
                if (is_resource($p)) fclose($p);
            }
            $asrPipes = [];
            $status = proc_get_status($asrProc);
            if ($status['running']) {
                proc_terminate($asrProc, 15);
                usleep(20000);
                $status = proc_get_status($asrProc);
                if ($status['running']) {
                    proc_terminate($asrProc, 9);
                }
            }
            proc_close($asrProc);
            $asrProc = null;
        }
    };

    $truncate = function (string $text, int $width = 24): string {
        return mb_strimwidth($text, 0, $width, '…', 'UTF-8');
    };

    $buildMenu = null;
    $buildMenu = function () use (&$tray, &$isListening, &$targetLocale, &$currentDevice, &$isAirPods, &$lastTranslated, &$history, &$buildMenu, $startASR, $stopASR) {
        $items = [];

        // 1. Audio input device status
        $items[] = new MenuItem($currentDevice, disabled: true);
        $items[] = MenuItem::separator();

        // 2. Listening toggle
        $items[] = new MenuItem(
            label: $isListening ? '暂停实时收音' : '继续实时收音',
            click: function () use (&$isListening, &$tray, $buildMenu, $startASR, $stopASR, $isAirPods) {
                $isListening = !$isListening;
                if ($isListening) {
                    $startASR();
                    $tray->setSystemIcon($isAirPods ? 'airpodspro' : 'waveform.circle');
                    $tray->setDualLineTitle('AirPods 智能翻译', '自动识别说话语言...');
                } else {
                    $stopASR();
                    $tray->setSystemIcon('mic.slash');
                    $tray->setDualLineTitle('AirPods 智能翻译', '已暂停监听');
                }
                $buildMenu();
            }
        );
        $items[] = MenuItem::separator();

        // 3. Target language submenu (radio type for native checkmark)
        $targetItems = [];
        $targetLangs = [
            'auto' => '自动识别双向互译 (中英互传)',
            'en-US' => '固定目标: 英语 (English)',
            'zh-CN' => '固定目标: 中文 (Simplified Chinese)',
            'ja-JP' => '固定目标: 日语 (Japanese)',
            'ko-KR' => '固定目标: 韩语 (Korean)',
            'fr-FR' => '固定目标: 法语 (French)',
            'de-DE' => '固定目标: 德语 (German)',
            'es-ES' => '固定目标: 西班牙语 (Spanish)',
        ];
        foreach ($targetLangs as $code => $name) {
            $targetItems[] = new MenuItem(
                label: $name,
                type: MenuItem::RADIO,
                checked: $targetLocale === $code,
                click: function () use ($code, &$targetLocale, $startASR, $buildMenu) {
                    $targetLocale = $code;
                    $startASR();
                    $buildMenu();
                }
            );
        }
        $items[] = new MenuItem('翻译模式 / 目标语言', submenu: $targetItems);

        $items[] = MenuItem::separator();

        // 4. Copy last translation quick action
        $copyLabel = $lastTranslated !== '' ? '复制最新译文: ' . mb_strimwidth($lastTranslated, 0, 14, '…', 'UTF-8') : '复制最新译文';
        $items[] = new MenuItem(
            label: $copyLabel,
            disabled: $lastTranslated === '',
            click: function () use (&$lastTranslated) {
                if ($lastTranslated !== '') {
                    Clipboard::writeText($lastTranslated);
                }
            }
        );

        // 5. Recent translations submenu (last 20 items, newest first)
        $historyItems = [];
        $reversedHistory = array_reverse($history);
        foreach ($reversedHistory as $entry) {
            $dstPreview = mb_strimwidth($entry['dst'], 0, 22, '…', 'UTF-8');
            $srcPreview = mb_strimwidth($entry['src'], 0, 16, '…', 'UTF-8');
            $itemLabel = "[{$entry['time']}] {$dstPreview} ({$srcPreview})";
            $textToCopy = $entry['dst'];
            $historyItems[] = new MenuItem(
                label: $itemLabel,
                click: function () use ($textToCopy) {
                    Clipboard::writeText($textToCopy);
                }
            );
        }
        if (empty($historyItems)) {
            $historyItems[] = new MenuItem('暂无历史记录', disabled: true);
        }
        $historyCount = count($history);
        $items[] = new MenuItem("最近译文记录 ({$historyCount}/20)", submenu: $historyItems);

        // 6. Clear display and history
        $items[] = new MenuItem(
            label: '清空托盘与历史',
            click: function () use (&$tray, &$lastSource, &$lastTranslated, &$history, $isAirPods, &$buildMenu) {
                $lastSource = '';
                $lastTranslated = '';
                $history = [];
                $tray->setDualLineTitle('AirPods 智能翻译', '自动识别说话语言...');
                $tray->setSystemIcon($isAirPods ? 'airpodspro' : 'waveform.circle');
                $buildMenu();
            }
        );

        $items[] = MenuItem::separator();

        // 7. Desk Mic setup shortcut
        $items[] = new MenuItem(
            label: '设置免佩戴收音 (关闭人耳检测)...',
            click: function () {
                exec('open "x-apple.systempreferences:com.apple.BluetoothSettings"');
            }
        );

        $items[] = MenuItem::separator();
        $items[] = new MenuItem('退出', click: fn() => App::quit());

        $tray->setMenu(new Menu($items));
    };

    $buildMenu();
    $startASR();

    // Polling ASR stdout on App event loop
    $pollASR = function () use (&$pollASR, &$asrPipes, &$readBuffer, &$isListening, &$tray, &$lastSource, &$lastTranslated, &$history, &$buildMenu, $truncate, $isAirPods) {
        if ($isListening && isset($asrPipes[1]) && is_resource($asrPipes[1])) {
            $chunk = stream_get_contents($asrPipes[1]);
            if ($chunk !== false && $chunk !== '') {
                $readBuffer .= $chunk;
                while (($pos = strpos($readBuffer, "\n")) !== false) {
                    $line = trim(substr($readBuffer, 0, $pos));
                    $readBuffer = substr($readBuffer, $pos + 1);
                    if ($line !== '' && str_starts_with($line, '{')) {
                        $json = json_decode($line, true);
                        if (is_array($json)) {
                            $type = $json['type'] ?? '';

                            if ($type === 'vad') {
                                $state = $json['state'] ?? '';
                                if ($state === 'start') {
                                    $tray->setSystemIcon('waveform');
                                    $tray->setDualLineTitle('正在收音...', '聆听说话中...');
                                } elseif ($state === 'end') {
                                    $tray->setDualLineTitle('收音完成', '智能翻译中...');
                                }
                            } elseif ($type === 'speech') {
                                $text = trim($json['text'] ?? '');
                                $trans = trim($json['translated'] ?? '');
                                if ($text !== '') {
                                    $lastSource = $text;
                                    $lastTranslated = $trans;
                                    $history[] = [
                                        'time' => date('H:i:s'),
                                        'src' => $text,
                                        'dst' => $trans,
                                    ];
                                    if (count($history) > 20) {
                                        array_shift($history);
                                    }
                                    $tray->setSystemIcon($isAirPods ? 'airpodspro' : 'waveform.circle');
                                    $tray->setDualLineTitle(
                                        $truncate($text),
                                        $trans !== '' ? $truncate($trans) : '无对应译文'
                                    );
                                    $buildMenu();
                                }
                            } elseif ($type === 'error') {
                                $msg = $json['message'] ?? '发生错误';
                                $tray->setDualLineTitle('收音提示', $truncate($msg));
                            }
                        }
                    }
                }
            }
        }
        App::after(0.04, $pollASR);
    };
    $pollASR();

    // Periodic check for audio device changes
    $checkDevice = function () use (&$checkDevice, &$currentDevice, &$isAirPods, &$tray, &$buildMenu) {
        $dev = AudioDeviceDetector::defaultInputName();
        if ($dev !== $currentDevice) {
            $currentDevice = $dev;
            $isAirPods = AudioDeviceDetector::isAirPods($dev);
            $tray->setSystemIcon($isAirPods ? 'airpodspro' : 'waveform.circle');
            $tray->setToolTip("AirPods 实时智能双向同传 (Gemini 2.5 Flash)\n当前输入: {$currentDevice}");
            $buildMenu();
        }
        App::after(3.0, $checkDevice);
    };
    $checkDevice();

    App::onQuit($stopASR);
    register_shutdown_function($stopASR);
});

App::run();
