<?php
// Enforces AGENTS.md Rule 17 ("Every feature change updates both GitHub and smart-book docs"):
// 1. No public-facing docs in `docs/` or `README.md` regress to "PHP 8.3" requirements.
// 2. When the sibling `smart-book` repository exists on the developer's machine, every
//    markdown file in `docs/` matches `smart-book/app/Views/myphp/docs/` byte-for-byte,
//    and `smart-book/app/Views/myphp/docs/zh-cn/` has all core Chinese translations.
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
$docsDir = "$root/docs";

// 1. Check that no public user-facing doc regresses to "Install PHP 8.3" or "PHP ≥ 8.3"
$readme = (string)file_get_contents("$root/README.md");
check(!str_contains($readme, 'PHP ≥ 8.3'), 'README.md does not advertise PHP ≥ 8.3');

$gs = (string)file_get_contents("$docsDir/getting-started.md");
check(!str_contains($gs, 'Install PHP 8.3'), 'docs/getting-started.md does not advertise PHP 8.3');

$cli = (string)file_get_contents("$docsDir/cli.md");
check(!str_contains($cli, 'PHP 8.3 or'), 'docs/cli.md does not advertise PHP 8.3');

// 2. Check that bundled skill matches ~/.claude/skills/myphp-app/SKILL.md when present
$home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
$claudeSkill = "$home/.claude/skills/myphp-app/SKILL.md";
if ($home !== '' && is_file($claudeSkill)) {
    same(
        file_get_contents("$root/bin/skills/myphp-app/SKILL.md"),
        file_get_contents($claudeSkill),
        'bin/skills/myphp-app/SKILL.md is synced with ~/.claude/skills/myphp-app/SKILL.md'
    );
}

// 3. Check that GitHub `docs/` and `smart-book/app/Views/myphp/docs/` are 100% in sync when sibling repo is present
$sbDocs = dirname($root) . '/smart-book/app/Views/myphp/docs';
if (is_dir($sbDocs)) {
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($docsDir, \FilesystemIterator::SKIP_DOTS));
    $outOfSync = [];
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'md') {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($docsDir) + 1);
        $target = "$sbDocs/$rel";
        if (!is_file($target) || file_get_contents($file->getPathname()) !== file_get_contents($target)) {
            $outOfSync[] = $rel;
        }
    }
    same([], $outOfSync, 'All docs/*.md files are synced with smart-book/app/Views/myphp/docs/');

    // Verify core Chinese docs exist under smart-book/app/Views/myphp/docs/zh-cn/
    $requiredZh = [
        'index.md', 'getting-started.md', 'swiftui.md', 'windows.md', 'app.md',
        'menus.md', 'documents.md', 'i18n.md', 'native.md', 'data-transfer.md',
        'audio.md', 'mobile.md', 'android.md', 'ios.md', 'frontend.md',
        'bindings.md', 'distribution.md', 'interop.md', 'cli.md', 'architecture.md',
        'ui/README.md', 'ui/scene3d.md', 'plugins/game.md',
    ];
    $missingZh = [];
    foreach ($requiredZh as $rel) {
        if (!is_file("$sbDocs/zh-cn/$rel")) {
            $missingZh[] = $rel;
        }
    }
    same([], $missingZh, 'Core Chinese docs exist in smart-book/app/Views/myphp/docs/zh-cn/');
}
