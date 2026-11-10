<?php
declare(strict_types=1);

namespace MyPHP\Native\Build;

use MyPHP\Native\Update\{Feed, Publish};

/**
 * The install script of Windows apps with updates (mygo's installps1.go):
 * install.ps1 downloads the installer of the latest version for the
 * machine's architecture, checks its SHA-256 against SHA256SUMS of the
 * same release (Checksums), quits the app if it runs, and runs the
 * installer silently, for the user, in the folder of the installed
 * version, then opens the app:
 *
 *     irm https://github.com/me/my-app/releases/latest/download/install.ps1 | iex
 *
 * It runs in Windows PowerShell 5.1 and PowerShell 7. GitHub renames the
 * spaces of uploaded files to dots, which it follows, else it finds the
 * installer among the release's assets with the GitHub API. For tests,
 * $env:<SLUG>_DOWNLOAD_URL is where the files are instead and
 * $env:<SLUG>_NO_OPEN=1 leaves the app closed.
 *
 * @internal bin/myphp
 */
final class InstallPs1
{
    public const NAME = 'install.ps1';

    /** $s quoted for PowerShell in ASCII (mygo's psQuote): Windows PowerShell 5.1 reads a script irm downloads without a charset as Latin-1, so other characters are [char] codes. */
    public static function quote(string $s): string
    {
        $parts = [];
        $ascii = '';
        foreach (unpack('v*', (string)iconv('UTF-8', 'UTF-16LE', $s)) ?: [] as $u) {
            if ($u >= 0x20 && $u < 0x7f) {
                $ascii .= chr($u);
                continue;
            }
            if ($ascii !== '') {
                $parts[] = "'" . str_replace("'", "''", $ascii) . "'";
                $ascii = '';
            }
            $parts[] = sprintf('[char]0x%04X', $u);
        }
        if ($parts === []) {
            return "'" . str_replace("'", "''", $ascii) . "'";
        }
        if ($ascii !== '') {
            $parts[] = "'" . str_replace("'", "''", $ascii) . "'";
        }
        return "('' + " . implode(' + ', $parts) . ')'; // a string first, so that + joins strings
    }

    /** The environment prefix of the app's test variables: its slug in capitals, its hyphens underscores. */
    public static function envName(string $slug): string
    {
        return strtoupper(str_replace('-', '_', $slug));
    }

    /** The script, for $c (myphp.json, its windows section Windows::config's) published as $p says. */
    public static function script(array $c, Publish $p): string
    {
        $w = $c['windows'];
        $title = implode(' ', preg_split('/\s+/', trim($w['displayName'])) ?: []);
        if (preg_match('/[^\x20-\x7e]/', $title) === 1) {
            $title = 'the app';
        }
        $github = $p->github();
        $releases = $api = $prefix = $assets = '';
        $isGitHub = false;
        if ($github !== '' && $p->tagPrefix() !== 'v') { // the newest release tagged with the prefix, through the API
            $releases = "https://github.com/$github/releases/download/";
            $api = Feed::$githubApi . "/repos/$github/releases?per_page=100";
            $prefix = $p->tagPrefix();
            $assets = Feed::$githubApi . "/repos/$github/releases/tags/";
            $isGitHub = true;
            $irm = $p->file(self::NAME, false);
        } elseif ($github !== '') {
            $releases = $p->file('', true);
            $assets = Feed::$githubApi . "/repos/$github/releases/latest";
            $isGitHub = true;
            $irm = $p->file(self::NAME, true);
        } else {
            $releases = $p->file('', true);
            $irm = $p->file(self::NAME, true);
        }
        $q = self::quote(...);
        $env = self::envName(Slug::ofConfig($c));
        $vars = [
            '{{Title}}' => $title, '{{Irm}}' => $irm,
            '{{Name}}' => $q($w['displayName']), '{{Exe}}' => $q(Windows::exeName($c)), '{{Folder}}' => $q($w['installDirName']),
            '{{Setup}}' => $q(Nsis::installerPrefix($c)), '{{Identifier}}' => $q($c['identifier']),
            '{{Releases}}' => $q($releases), '{{API}}' => $q($api), '{{TagPrefix}}' => $q($prefix), '{{Assets}}' => $q($assets),
            '{{GitHub}}' => $isGitHub ? '$true' : '$false', '{{Env}}' => $env,
        ];
        return strtr(self::TEMPLATE, $vars);
    }

    private const TEMPLATE = <<<'PS'
        # Installs {{Title}} for the current user: the installer of the latest version
        # for this PC, its SHA-256 checked against SHA256SUMS of its release, run
        # silently. Made by myphp build.
        #
        #   irm {{Irm}} | iex

        & {
        $ErrorActionPreference = 'Stop'
        $ProgressPreference = 'SilentlyContinue'

        $appName = {{Name}}
        $exeName = {{Exe}}
        $setupPrefix = {{Setup}}
        $identifier = {{Identifier}}
        $defaultFolder = Join-Path $env:LOCALAPPDATA ('Programs\' + {{Folder}})
        # Where the files of the latest version are: in the release of the newest
        # tag with the prefix when the repository's releases (api) hold others too.
        $releases = {{Releases}}
        $api = {{API}}
        $tagPrefix = {{TagPrefix}}
        # GitHub renames the spaces of uploaded files to dots; its API lists them.
        $github = {{GitHub}}
        $assetsApi = {{Assets}}
        # For tests only: where the files are instead, and leaving the app closed.
        $downloadUrl = $env:{{Env}}_DOWNLOAD_URL
        $noOpen = $env:{{Env}}_NO_OPEN
        if ($downloadUrl) {
        	$releases = $downloadUrl.TrimEnd('/') + '/'
        	$api = ''
        	$github = $false
        }

        function Get-Arch {
        	$arch = $env:PROCESSOR_ARCHITEW6432
        	if (-not $arch) { $arch = $env:PROCESSOR_ARCHITECTURE }
        	if ($arch -eq 'AMD64') {
        		# An x64 PowerShell on an Arm PC.
        		try {
        			if ((Get-CimInstance Win32_Processor -ErrorAction Stop | Select-Object -First 1).Architecture -eq 12) { $arch = 'ARM64' }
        		} catch {}
        	}
        	switch ($arch) {
        		'ARM64' { return 'arm64' }
        		'AMD64' { return 'amd64' }
        		default { throw "$appName is not made for $arch" }
        	}
        }

        function Get-File([string]$url, [string]$path) {
        	Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $path
        }

        # The tag of the newest release tagged $tagPrefix that is neither a draft nor a prerelease.
        function Get-LatestTag {
        	for ($page = 1; $page -le 10; $page++) {
        		$list = @(Invoke-RestMethod -UseBasicParsing -Uri "$api&page=$page" | ForEach-Object { $_ })
        		foreach ($r in $list) {
        			if (-not $r.draft -and -not $r.prerelease -and $r.tag_name.StartsWith($tagPrefix)) { return $r.tag_name }
        		}
        		if ($list.Count -lt 100) { break }
        	}
        	throw "no release of $appName is published"
        }

        # The processes running from the folder, found with CIM, which reads every path at once.
        function Get-AppProcess([string]$folder) {
        	$dir = $folder.TrimEnd('\') + '\'
        	Get-CimInstance Win32_Process -Property ProcessId, ExecutablePath -ErrorAction SilentlyContinue | Where-Object {
        		$_.ExecutablePath -and $_.ProcessId -ne $PID -and $_.ExecutablePath.StartsWith($dir, [StringComparison]::OrdinalIgnoreCase)
        	} | ForEach-Object { Get-Process -Id $_.ProcessId -ErrorAction SilentlyContinue }
        }

        function Wait-Exit($processes, [int]$seconds) {
        	$deadline = (Get-Date).AddSeconds($seconds)
        	foreach ($p in $processes) {
        		$left = [int]($deadline - (Get-Date)).TotalMilliseconds
        		if ($left -lt 0) { $left = 0 }
        		try { [void]$p.WaitForExit($left) } catch {}
        	}
        }

        function Install-App {
        	try {
        		[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
        	} catch {}
        	$arch = Get-Arch
        	$base = $releases
        	$assets = $assetsApi
        	if ($api) {
        		$tag = Get-LatestTag
        		$base = $releases + [uri]::EscapeDataString($tag) + '/'
        		$assets = $assetsApi + [uri]::EscapeDataString($tag)
        	}
        	$work = Join-Path ([IO.Path]::GetTempPath()) ([IO.Path]::GetRandomFileName())
        	New-Item -ItemType Directory -Path $work | Out-Null
        	try {
        		$sumsFile = Join-Path $work 'SHA256SUMS'
        		Get-File ($base + 'SHA256SUMS') $sumsFile
        		$sum = $null
        		$name = $null
        		$pattern = '^([0-9a-fA-F]{64}) [ *](' + [regex]::Escape($setupPrefix) + '(\S+)-' + $arch + '\.exe)$'
        		foreach ($line in [IO.File]::ReadAllLines($sumsFile)) {
        			if ($line.TrimEnd() -match $pattern) {
        				$sum = $Matches[1]
        				$name = $Matches[2]
        				$version = $Matches[3]
        				break
        			}
        		}
        		if (-not $name) { throw "the release has no installer of $appName for $arch" }

        		Write-Host "Downloading $appName $version for $arch"
        		$setup = Join-Path $work ($name -replace ' ', '.')
        		if ($github) {
        			try {
        				Get-File ($base + ($name -replace ' ', '.')) $setup
        			} catch {
        				# Not the name GitHub gave it: the release's assets name it.
        				$key = $name -replace '[^0-9A-Za-z]', ''
        				$asset = (Invoke-RestMethod -UseBasicParsing -Uri $assets).assets |
        					Where-Object { ($_.name -replace '[^0-9A-Za-z]', '') -eq $key } | Select-Object -First 1
        				if (-not $asset) { throw }
        				Get-File $asset.browser_download_url $setup
        			}
        		} else {
        			Get-File ($base + [uri]::EscapeDataString($name)) $setup
        		}
        		$actual = (Get-FileHash -Algorithm SHA256 -Path $setup).Hash
        		if ($actual -ne $sum.ToUpperInvariant()) {
        			throw "$name does not have the SHA-256 of SHA256SUMS: not installing it"
        		}

        		# The installed version's folder, which a silent install would otherwise leave for the default one.
        		$folder = $defaultFolder
        		$uninstallKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\' + $identifier
        		$location = (Get-ItemProperty -Path $uninstallKey -ErrorAction SilentlyContinue).InstallLocation
        		if ($location) { $folder = $location.TrimEnd('\') }

        		# Quit the app: ask its windows to close and, after 10 s, stop the processes still running from its folder.
        		$running = @(Get-AppProcess $folder)
        		if ($running.Count -gt 0) {
        			Write-Host "Quitting $appName"
        			foreach ($p in $running) { try { [void]$p.CloseMainWindow() } catch {} }
        			Wait-Exit $running 10
        			$running = @(Get-AppProcess $folder)
        			if ($running.Count -gt 0) {
        				$running | Stop-Process -Force -ErrorAction SilentlyContinue
        				Wait-Exit $running 10
        				if (@(Get-AppProcess $folder).Count -gt 0) { throw "$appName is still running: quit it and run this again" }
        			}
        		}

        		Write-Host "Installing $appName in $folder"
        		# /D, last, takes the rest of the command line, unquoted.
        		$process = Start-Process -FilePath $setup -ArgumentList "/S /D=$folder" -Wait -PassThru
        		if ($process.ExitCode -ne 0) { throw "the installer of $appName failed with exit code $($process.ExitCode)" }
        		$exe = Join-Path $folder $exeName
        		if (-not (Test-Path -LiteralPath $exe)) { throw "the installer did not install $exe" }
        		if (-not $noOpen) { Start-Process -FilePath $exe }
        		Write-Host "Installed $appName $version in $folder"
        	} finally {
        		Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
        	}
        }

        try {
        	Install-App
        } catch {
        	Write-Host "install.ps1: $_" -ForegroundColor Red
        }
        }

        PS;
}
