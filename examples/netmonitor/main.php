<?php
// A lightweight macOS menu bar network diagnostic monitor built with MyPHP.
// Displays real-time ping to remote Mac mini (Tailscale), public egress IP,
// DNS latency (DoH), and local hotspot latency directly on the macOS menu bar.
// Uses MyPHP core's native SF Symbols and dual-line status title capabilities.
// Run: myphp run -d macos examples/netmonitor
declare(strict_types=1);

use MyPHP\{ActivationPolicy, App, Clipboard, Menu, MenuItem, Network, NetworkStatus, Process, QuitEvent, Tray, TrayOptions};

// MyPHP, and its declarative views (VStack, Text…): loaded already in a phone's app, whose launcher loads them
class_exists(\MyPHP\App::class) || require __DIR__ . '/../../src/autoload.php';

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
            'aws_server'      => 'AWS (DE): %s (%s · $%s/月)',
            'aws_cost'        => '本月费用: $%s (%s)',
            'aws_traffic'     => '网络消耗: %s / %s',
            'aws_free_tier'   => '免费试用中',
            'aws_billed'      => '正常计费',
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
            'aws_server'      => 'AWS (DE): %s (%s · $%s/mo)',
            'aws_cost'        => 'Monthly Cost: $%s (%s)',
            'aws_traffic'     => 'Data Transfer: %s / %s',
            'aws_free_tier'   => 'Free Tier',
            'aws_billed'      => 'Standard',
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
        'gw_ip'      => '',
        'updated'    => '',
        'aws'        => null,
    ];

    public static function hasAwsConfig(): bool
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if (!$home) {
            return false;
        }
        return file_exists("{$home}/.aws/credentials") && file_exists("{$home}/.aws/config");
    }

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

    public function probe(bool $forceAws = false): void
    {
        if ($this->probing) {
            return;
        }
        $this->probing = true;

        $probeCode = <<<'PHP'
        error_reporting(0);
        ini_set('display_errors', '0');

        $forceAws = ($argv[1] ?? '0') === '1';

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
            'gw_ip'       => '',
            'updated'     => date('H:i:s'),
            'aws'         => null,
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

        // 3. 探测本地默认网关延迟 (-W 1000 毫秒)
        exec("route -n get default 2>/dev/null | grep gateway", $gwRoute);
        if (!empty($gwRoute[0]) && preg_match('/gateway:\s*([0-9\.]+)/', $gwRoute[0], $gm)) {
            $gwIp = $gm[1];
            $res['gw_ip'] = $gwIp;
            exec("ping -c 1 -W 1000 {$gwIp} 2>&1", $gwOut, $gwCode);
            if ($gwCode === 0) {
                foreach ($gwOut as $l) {
                    if (preg_match('/time=([0-9\.]+)\s*ms/', $l, $m)) {
                        $res['gw_ping'] = (int)round((float)$m[1]);
                    }
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

        // 6. 探测 AWS 凭证与服务器套餐/消耗
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if ($home && file_exists("{$home}/.aws/credentials") && file_exists("{$home}/.aws/config")) {
            $credFile = "{$home}/.aws/credentials";
            $configFile = "{$home}/.aws/config";
            $cacheFile = sys_get_temp_dir() . '/netmonitor_aws_cache.json';
            $awsData = null;
            if (!$forceAws && file_exists($cacheFile)) {
                $c = json_decode((string)@file_get_contents($cacheFile), true);
                if (is_array($c) && (time() - ($c['_cached_at'] ?? 0) < 600)) {
                    $awsData = $c;
                }
            }

            if ($awsData === null) {
                try {
                    $ini = parse_ini_file($credFile, true);
                    $ak = $ini['default']['aws_access_key_id'] ?? '';
                    $sk = $ini['default']['aws_secret_access_key'] ?? '';
                    $conf = parse_ini_file($configFile, true);
                    $region = $conf['default']['region'] ?? 'eu-central-1';

                    if ($ak && $sk) {
                        $sign = function(string $service, string $host, string $target, string $payload, string $targetRegion) use ($ak, $sk) {
                            $now = new DateTime('now', new DateTimeZone('UTC'));
                            $amzDate = $now->format('Ymd\THis\Z');
                            $dateStamp = $now->format('Ymd');
                            $payloadHash = hash('sha256', $payload);

                            $canonicalHeaders = "content-type:application/x-amz-json-1.1\nhost:$host\nx-amz-date:$amzDate\nx-amz-target:$target\n";
                            $signedHeaders = "content-type;host;x-amz-date;x-amz-target";
                            $canonicalRequest = "POST\n/\n\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

                            $credentialScope = "$dateStamp/$targetRegion/$service/aws4_request";
                            $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

                            $kDate = hash_hmac('sha256', $dateStamp, "AWS4" . $sk, true);
                            $kRegion = hash_hmac('sha256', $targetRegion, $kDate, true);
                            $kService = hash_hmac('sha256', $service, $kRegion, true);
                            $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

                            $signature = hash_hmac('sha256', $stringToSign, $kSigning);
                            $auth = "AWS4-HMAC-SHA256 Credential=$ak/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

                            $ch = curl_init("https://$host/");
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/x-amz-json-1.1',
                                "Host: $host",
                                "X-Amz-Date: $amzDate",
                                "X-Amz-Target: $target",
                                "Authorization: $auth",
                            ]);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                            $out = curl_exec($ch);
                            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            return $code === 200 ? json_decode((string)$out, true) : null;
                        };

                        $lsData = $sign('lightsail', "lightsail.$region.amazonaws.com", 'Lightsail_20161128.GetInstances', '{}', $region);
                        $instance = $lsData['instances'][0] ?? null;
                        if ($instance) {
                            $instName = $instance['name'] ?? 'DE-Server';
                            $bundleId = $instance['bundleId'] ?? 'nano';
                            $bundleName = ucfirst(explode('_', $bundleId)[0] ?? 'Nano');

                            $startMonth = strtotime(date('Y-m-01 00:00:00'));
                            $metricData = $sign('lightsail', "lightsail.$region.amazonaws.com", 'Lightsail_20161128.GetInstanceMetricData', json_encode([
                                'instanceName' => $instName,
                                'metricName'   => 'NetworkOut',
                                'period'       => 86400 * 31,
                                'unit'         => 'Bytes',
                                'statistics'   => ['Sum'],
                                'startTime'    => $startMonth,
                                'endTime'      => time(),
                            ]), $region);

                            $outBytes = 0;
                            if (!empty($metricData['metricData'])) {
                                foreach ($metricData['metricData'] as $pt) {
                                    $outBytes += ($pt['sum'] ?? 0);
                                }
                            }
                            $outGb = round($outBytes / (1024 * 1024 * 1024), 2);

                            $startStr = date('Y-m-01');
                            $endStr = date('Y-m-d', strtotime('+1 day'));
                            $ceData = $sign('ce', 'ce.us-east-1.amazonaws.com', 'AWSInsightsIndexService.GetCostAndUsage', json_encode([
                                'TimePeriod' => ['Start' => $startStr, 'End' => $endStr],
                                'Granularity' => 'MONTHLY',
                                'Filter' => [
                                    'Dimensions' => [
                                        'Key' => 'SERVICE',
                                        'Values' => ['Amazon Lightsail']
                                    ]
                                ],
                                'Metrics' => ['UnblendedCost']
                            ]), 'us-east-1');

                            $costAmount = '0.00';
                            if (!empty($ceData['ResultsByTime'][0]['Total']['UnblendedCost']['Amount'])) {
                                $costAmount = number_format((float)$ceData['ResultsByTime'][0]['Total']['UnblendedCost']['Amount'], 2);
                            }

                            $awsData = [
                                'instance_name'  => $instName,
                                'bundle_name'    => $bundleName,
                                'monthly_plan'   => 5,
                                'bandwidth_limit'=> '1 TB',
                                'used_gb'        => $outGb,
                                'cost'           => $costAmount,
                                'currency'       => 'USD',
                                'is_free_tier'   => ((float)$costAmount == 0.0),
                                '_cached_at'     => time(),
                            ];
                            @file_put_contents($cacheFile, json_encode($awsData, JSON_PRETTY_PRINT));
                        }
                    }
                } catch (\Throwable $e) {}
            }
            $res['aws'] = $awsData;
        }

        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        PHP;

        Process::run([PHP_BINARY, '-r', $probeCode, $forceAws ? '1' : '0'], '', function (string $out, string $err, int $code) {
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
        $geo = $this->data['egress_geo'];
        $code = '--';
        if ($geo !== '') {
            $parts = explode(',', $geo);
            $country = strtoupper(trim(end($parts)));
            if (strlen($country) === 2) {
                $code = $country;
            } elseif (preg_match('/\b([A-Z]{2})\b/', $geo, $m)) {
                $code = $m[1];
            }
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

        // 本地网关
        $gwPingStr = $this->data['gw_ping'] > 0 ? "{$this->data['gw_ping']} ms" : $this->i18n->t('timeout');
        $gwIp = $this->data['gw_ip'] ?: '-';

        // 系统当前底层连接接口 (Flutter-like)
        $status = Network::status();
        $netTypeStr = match ($status->type) {
            'wifi'     => $this->i18n->t('net_wifi'),
            'cellular' => $this->i18n->t('net_cellular'),
            'wired'    => $this->i18n->t('net_wired'),
            'none'     => $this->i18n->t('net_none'),
            default    => $this->i18n->t('net_other'),
        };

        $items = [
            new MenuItem($this->i18n->t('title_panel', $timeStr), fn() => Clipboard::writeText($timeStr)),
            MenuItem::separator(),
            new MenuItem($this->i18n->t('net_type', $netTypeStr), fn() => Clipboard::writeText($netTypeStr)),
            new MenuItem($this->i18n->t('mac_mini', $tsPingStr, $modeStr), fn() => Clipboard::writeText("Mac mini: {$tsPingStr} ({$modeStr})"), disabled: $this->data['ts_ping'] <= 0),
            new MenuItem($this->i18n->t('egress_ip', $egressIp, $egressGeo), fn() => Clipboard::writeText($egressIp)),
            new MenuItem($this->i18n->t('dns_status', $dnsStr), fn() => Clipboard::writeText($dnsStr), disabled: !$this->data['dns_ok']),
            new MenuItem($this->i18n->t('hotspot', $gwPingStr, $gwIp), fn() => Clipboard::writeText($gwIp), disabled: $this->data['gw_ping'] <= 0),
        ];

        // 仅当 ~/.aws/credentials 与 ~/.aws/config 存在且有数据时，作为一个独立分组展示
        $awsReportPart = '';
        if (self::hasAwsConfig() && !empty($this->data['aws'])) {
            $aws = $this->data['aws'];
            $inst = $aws['instance_name'] ?? 'Server';
            $bundle = $aws['bundle_name'] ?? 'Nano';
            $plan = (string)($aws['monthly_plan'] ?? 5);
            $cost = (string)($aws['cost'] ?? '0.00');
            $isFree = !empty($aws['is_free_tier']);
            $usedGb = $aws['used_gb'] ?? 0;
            $limit = $aws['bandwidth_limit'] ?? '1 TB';

            $serverLine = $this->i18n->t('aws_server', $inst, $bundle, $plan);
            $costStatus = $isFree ? $this->i18n->t('aws_free_tier') : $this->i18n->t('aws_billed');
            $costLine   = $this->i18n->t('aws_cost', $cost, $costStatus);
            $trafficLine= $this->i18n->t('aws_traffic', "{$usedGb} GB", $limit);

            $items[] = MenuItem::separator();
            $items[] = new MenuItem($serverLine, fn() => Clipboard::writeText($serverLine));
            $items[] = new MenuItem($costLine, fn() => Clipboard::writeText($costLine));
            $items[] = new MenuItem($trafficLine, fn() => Clipboard::writeText($trafficLine));

            $awsReportPart = "{$serverLine}\n{$costLine}\n{$trafficLine}\n";
        }

        $items[] = MenuItem::separator();
        $items[] = new MenuItem($this->i18n->t('refresh_now'), fn() => $this->probe(true), 'CmdOrCtrl+R');
        $items[] = new MenuItem($this->i18n->t('restart_ts'), function () {
            $this->tray?->setDualLineTitle('...', 'TS');
            Process::run(['bash', '-c', 'tailscale down && tailscale up'], '', fn() => $this->probe());
        }, 'CmdOrCtrl+T');
        $items[] = new MenuItem($this->i18n->t('copy_log'), function () use ($tsPingStr, $modeStr, $egressIp, $egressGeo, $dnsStr, $gwPingStr, $gwIp, $awsReportPart) {
            $report = $this->i18n->t('report_title') . "\n" .
                $this->i18n->t('time') . ": " . date('Y-m-d H:i:s') . "\n" .
                "Mac mini: {$tsPingStr} ({$modeStr})\n" .
                "Egress:   {$egressIp} ({$egressGeo})\n" .
                "DNS:      {$dnsStr}\n" .
                "Hotspot:  {$gwPingStr} ({$gwIp})\n" .
                $awsReportPart;
            Clipboard::writeText($report);
        }, 'CmdOrCtrl+Shift+C');
        $items[] = MenuItem::separator();
        $items[] = new MenuItem($this->i18n->t('quit'), fn() => App::quit(), role: 'quit');

        return new Menu($items);
    }
}

$app = new NetMonitor();
App::setName('NetMonitor');
App::setActivationPolicy(ActivationPolicy::Accessory);
App::whenReady($app->start(...));
App::run();
