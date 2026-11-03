<?php
// A lightweight macOS menu bar network diagnostic monitor built with MyPHP.
// Displays real-time ping to remote Mac mini (Tailscale), public egress IP,
// DNS latency (DoH), and local hotspot latency directly on the macOS menu bar.
// Uses MyPHP core's native SF Symbols and dual-line status title capabilities.
// Run: ./run.sh netmonitor
declare(strict_types=1);

use MyPHP\{ActivationPolicy, App, Clipboard, Menu, MenuItem, Network, NetworkStatus, Process, QuitEvent, Tray, TrayOptions};

spl_autoload_register(fn($c) => require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 6)) . '.php');

// 声明应用支持的语言：中文与英文
App::setLanguages('zh-Hans', 'en');

final class I18n
{
    private string $lang;

    private const MESSAGES = [
        'zh' => [
            'probing'         => '探测中…',
            'refreshing'      => '刷新中…',
            'offline'         => '离线',
            'title_panel'     => '网络实时诊断 · %s',
            'net_type'        => '连接类型: %s',
            'net_wifi'        => '无线网络 (Wi-Fi)',
            'net_cellular'    => '蜂窝网络 (Cellular/4G/5G)',
            'net_wired'       => '有线网络 (Ethernet)',
            'net_none'        => '未连接网络',
            'net_other'       => '其他网络接口',
            'mac_mini'        => 'Mac mini: %s (%s)',
            'egress_ip'       => '公网出口: %s · %s',
            'dns_status'      => 'DNS 延迟: %s',
            'hotspot'         => '本地热点: %s (%s)',
            'refresh_now'     => '立即重新探测',
            'restart_ts'      => '重启 Tailscale 打洞',
            'copy_log'        => '复制诊断报告',
            'quit'            => '退出 NetMonitor',
            'mode_direct'     => '直连 P2P · %s',
            'mode_direct_raw' => '直连 P2P',
            'mode_relay'      => '中继 · %s',
            'unknown'         => '未知',
            'timeout'         => '超时',
            'normal'          => '%d ms (DoH 8.8.8.8 · 正常)',
            'dns_error'       => '异常',
            'report_title'    => '=== MyPHP 网络实时诊断报告 ===',
            'time'            => '时间',
        ],
        'en' => [
            'probing'         => 'Probing…',
            'refreshing'      => 'Refreshing…',
            'offline'         => 'Offline',
            'title_panel'     => 'Network Diagnostics · %s',
            'net_type'        => 'Interface: %s',
            'net_wifi'        => 'Wireless (Wi-Fi)',
            'net_cellular'    => 'Cellular (4G/5G Hotspot)',
            'net_wired'       => 'Wired (Ethernet)',
            'net_none'        => 'No Connection',
            'net_other'       => 'Other Interface',
            'mac_mini'        => 'Mac mini: %s (%s)',
            'egress_ip'       => 'Egress IP: %s · %s',
            'dns_status'      => 'DNS Latency: %s',
            'hotspot'         => 'Local Hotspot: %s (%s)',
            'refresh_now'     => 'Refresh Now',
            'restart_ts'      => 'Restart Tailscale Tunnel',
            'copy_log'        => 'Copy Diagnostics Report',
            'quit'            => 'Quit NetMonitor',
            'mode_direct'     => 'Direct P2P · %s',
            'mode_direct_raw' => 'Direct P2P',
            'mode_relay'      => 'Relay · %s',
            'unknown'         => 'Unknown',
            'timeout'         => 'Timeout',
            'normal'          => '%d ms (DoH 8.8.8.8 · Normal)',
            'dns_error'       => 'Error',
            'report_title'    => '=== MyPHP Network Diagnostics Report ===',
            'time'            => 'Time',
        ],
    ];

    public function __construct()
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $locale = strtolower(App::locale());
        $this->lang = str_starts_with($locale, 'zh') ? 'zh' : 'en';
    }

    public function t(string $key, mixed ...$args): string
    {
        $tpl = self::MESSAGES[$this->lang][$key] ?? self::MESSAGES['en'][$key] ?? $key;
        return $args ? sprintf($tpl, ...$args) : $tpl;
    }
}

final class NetMonitor
{
    private ?Tray $tray = null;
    private bool $probing = false;
    private I18n $i18n;
    private array $data = [
        'ts_ping'    => 0,
        'ts_mode'    => '',
        'ts_endpoint'=> '',
        'ts_relay'   => '',
        'egress_ip'  => '',
        'egress_geo' => '',
        'dns_ms'     => 0,
        'dns_ok'     => false,
        'gw_ping'    => 0,
        'gw_ip'      => '172.26.151.213',
        'updated'    => '',
    ];

    public function __construct()
    {
        $this->i18n = new I18n();
    }

    public function start(): void
    {
        $this->tray = new Tray(new TrayOptions(
            systemIcon: 'dot.radiowaves.left.and.right',
            topTitle: '...',
            bottomTitle: '--',
            toolTip: 'MyPHP NetMonitor',
            menu: $this->buildMenu(),
        ));

        App::onWillQuit(fn(QuitEvent $e) => $this->tray?->destroy());

        // 监听系统语言变更动态切换
        App::onLocaleChanged(function () {
            $this->i18n->refresh();
            $this->updateUI();
        });

        // 监听网络接口切换事件 (Wi-Fi 切 4G/有线)
        Network::onChanged(function (NetworkStatus $s) {
            $this->probe();
        });

        // 立即执行第一次探测
        $this->probe();
    }

    public function probe(): void
    {
        if ($this->probing) {
            return;
        }
        $this->probing = true;

        $probeCode = <<<'PHP'
        error_reporting(0);
        ini_set('display_errors', '0');

        $res = [
            'ts_ping'     => 0,
            'ts_mode'     => '',
            'ts_endpoint' => '',
            'ts_relay'    => '',
            'egress_ip'   => '',
            'egress_geo'  => '',
            'dns_ms'      => 0,
            'dns_ok'      => false,
            'gw_ping'     => 0,
            'gw_ip'       => '172.26.151.213',
            'updated'     => date('H:i:s'),
        ];

        // 1. 探测 Tailscale Mac mini (macOS ping -W 单位为毫秒)
        exec("ping -c 2 -W 1500 100.87.119.87 2>&1", $pOut, $pCode);
        if ($pCode === 0) {
            foreach ($pOut as $l) {
                if (preg_match('/round-trip.*=\s*([0-9\.]+)\/([0-9\.]+)\//', $l, $m)) {
                    $res['ts_ping'] = (int)round((float)$m[2]);
                }
            }
        }

        // 2. 探测 Tailscale 连接模式
        exec("tailscale status 2>/dev/null | grep 100.87.119.87", $tsOut);
        if (!empty($tsOut[0])) {
            $line = $tsOut[0];
            if (str_contains($line, 'direct')) {
                $res['ts_mode'] = 'direct';
                if (preg_match('/direct\s+([0-9\.\:]+)/', $line, $m)) {
                    $res['ts_endpoint'] = $m[1];
                }
            } elseif (preg_match('/relay\s+"([^"]+)"/', $line, $m)) {
                $res['ts_mode'] = 'relay';
                $res['ts_relay'] = $m[1];
            }
        }

        // 3. 探测本地热点延迟 (-W 1000 毫秒)
        exec("ping -c 1 -W 1000 172.26.151.213 2>&1", $gwOut, $gwCode);
        if ($gwCode === 0) {
            foreach ($gwOut as $l) {
                if (preg_match('/time=([0-9\.]+)\s*ms/', $l, $m)) {
                    $res['gw_ping'] = (int)round((float)$m[1]);
                }
            }
        }

        // 4. 探测 DNS 解析
        $t0 = microtime(true);
        $dnsIp = gethostbyname('www.google.com');
        $dnsMs = (int)round((microtime(true) - $t0) * 1000);
        if ($dnsIp !== 'www.google.com') {
            $res['dns_ok'] = true;
            $res['dns_ms'] = $dnsMs;
        }

        // 5. 探测公网出口 IP
        $ch = curl_init('https://ipinfo.io/json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $ipJson = curl_exec($ch);
        if ($ipJson) {
            $ipData = json_decode($ipJson, true);
            if (is_array($ipData) && !empty($ipData['ip'])) {
                $res['egress_ip'] = $ipData['ip'];
                $city = $ipData['city'] ?? '';
                $country = $ipData['country'] ?? '';
                $res['egress_geo'] = trim("$city, $country", ', ');
            }
        }

        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        PHP;

        Process::run([PHP_BINARY, '-r', $probeCode], '', function (string $out, string $err, int $code) {
            $this->probing = false;
            $clean = trim($out);
            if (preg_match('/\{.*\}/s', $clean, $m)) {
                $parsed = json_decode($m[0], true);
                if (is_array($parsed)) {
                    $this->data = array_merge($this->data, $parsed);
                }
            }
            $this->updateUI();

            // 15 秒后触发下一次心跳轮询
            App::after(15.0, fn() => $this->probe());
        });
    }

    private function updateUI(): void
    {
        $ms = $this->data['ts_ping'];
        $geo = $this->data['egress_geo'] ?: 'DE';
        $code = 'DE';
        if (str_contains($geo, 'DE') || str_contains($geo, 'Germany')) {
            $code = 'DE';
        } elseif (str_contains($geo, 'AE') || str_contains($geo, 'Dubai')) {
            $code = 'DXB';
        } elseif (str_contains($geo, 'SG')) {
            $code = 'SG';
        }

        if ($ms <= 0) {
            $top = '--';
            $bottom = 'OFF';
        } else {
            $top = "{$ms}ms";
            $bottom = $code;
        }

        // 调用 MyPHP 核心沉淀的原生 API
        $this->tray?->setDualLineTitle($top, $bottom);
        $this->tray?->setMenu($this->buildMenu());
    }

    private function buildMenu(): Menu
    {
        $timeStr = $this->data['updated'] ?: date('H:i:s');
        $tsPingStr = $this->data['ts_ping'] > 0 ? "{$this->data['ts_ping']} ms" : $this->i18n->t('timeout');

        // 计算 Tailscale 连接模式文字
        if ($this->data['ts_mode'] === 'direct') {
            $modeStr = $this->data['ts_endpoint']
                ? $this->i18n->t('mode_direct', $this->data['ts_endpoint'])
                : $this->i18n->t('mode_direct_raw');
        } elseif ($this->data['ts_mode'] === 'relay') {
            $modeStr = $this->i18n->t('mode_relay', $this->data['ts_relay'] ?: 'DERP');
        } else {
            $modeStr = $this->i18n->t('unknown');
        }

        // 公网出口
        $egressIp = $this->data['egress_ip'] ?: $this->i18n->t('unknown');
        $egressGeo = $this->data['egress_geo'] ?: $this->i18n->t('unknown');

        // DNS 状态
        if ($this->data['dns_ok']) {
            $dnsStr = $this->i18n->t('normal', $this->data['dns_ms']);
        } else {
            $dnsStr = $this->i18n->t('dns_error');
        }

        // 热点网关
        $gwPingStr = $this->data['gw_ping'] > 0 ? "{$this->data['gw_ping']} ms" : $this->i18n->t('timeout');
        $gwIp = $this->data['gw_ip'];

        // 系统当前底层连接接口 (Flutter-like)
        $status = Network::status();
        $netTypeStr = match ($status->type) {
            'wifi'     => $this->i18n->t('net_wifi'),
            'cellular' => $this->i18n->t('net_cellular'),
            'wired'    => $this->i18n->t('net_wired'),
            'none'     => $this->i18n->t('net_none'),
            default    => $this->i18n->t('net_other'),
        };

        return new Menu([
            new MenuItem($this->i18n->t('title_panel', $timeStr), disabled: true),
            MenuItem::separator(),
            new MenuItem($this->i18n->t('net_type', $netTypeStr), disabled: true),
            new MenuItem($this->i18n->t('mac_mini', $tsPingStr, $modeStr), disabled: true),
            new MenuItem($this->i18n->t('egress_ip', $egressIp, $egressGeo), disabled: true),
            new MenuItem($this->i18n->t('dns_status', $dnsStr), disabled: true),
            new MenuItem($this->i18n->t('hotspot', $gwPingStr, $gwIp), disabled: true),
            MenuItem::separator(),
            new MenuItem($this->i18n->t('refresh_now'), fn() => $this->probe(), 'CmdOrCtrl+R'),
            new MenuItem($this->i18n->t('restart_ts'), function () {
                $this->tray?->setDualLineTitle('...', 'TS');
                Process::run(['bash', '-c', 'tailscale down && tailscale up'], '', fn() => $this->probe());
            }, 'CmdOrCtrl+T'),
            new MenuItem($this->i18n->t('copy_log'), function () use ($tsPingStr, $modeStr, $egressIp, $egressGeo, $dnsStr, $gwPingStr, $gwIp) {
                $report = $this->i18n->t('report_title') . "\n" .
                    $this->i18n->t('time') . ": " . date('Y-m-d H:i:s') . "\n" .
                    "Mac mini: {$tsPingStr} ({$modeStr})\n" .
                    "Egress:   {$egressIp} ({$egressGeo})\n" .
                    "DNS:      {$dnsStr}\n" .
                    "Hotspot:  {$gwPingStr} ({$gwIp})\n";
                Clipboard::writeText($report);
            }, 'CmdOrCtrl+Shift+C'),
            MenuItem::separator(),
            new MenuItem($this->i18n->t('quit'), fn() => App::quit(), role: 'quit'),
        ]);
    }
}

$app = new NetMonitor();
App::setName('NetMonitor');
App::setActivationPolicy(ActivationPolicy::Accessory);
App::whenReady($app->start(...));
App::run();
