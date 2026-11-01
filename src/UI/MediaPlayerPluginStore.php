<?php
declare(strict_types=1);

namespace MyPHP\UI;

use Closure;
use MyPHP\App;
use MyPHP\Media\Https;
use MyPHP\PlayerPlugins\Plugins;
use MyPHP\Window;
use MyPHP\WindowOptions;
use RuntimeException;

/**
 * IINA's plugin store (PluginStorePanel), a sheet of 600 by 500: a GitHub
 * repository's plugin installed by its "owner/repo" or URL (the sheet
 * closing once one was); the official plugins and the community's (a
 * list of myphp's repository's, Plugins::COMMUNITY_LIST) to choose from,
 * each marked installed or not; the one chosen's name, identifier,
 * description, link and Install button, and its repository as GitHub
 * tells it (its owner's picture, stars, forks, issues, language, when it
 * was last pushed to); Done.
 *
 * @internal MediaPlayerPluginsPage
 */
final class MediaPlayerPluginStore
{
    /** Shows the store over $parent; $closed told as it closes. */
    public static function show(?Window $parent, Closure $closed): void
    {
        $sheet = null;
        $st = (object)['url' => new TextState(''), 'installing' => false, 'selected' => null, 'list' => null, 'error' => null, 'detailInstalling' => false, 'repos' => []];
        $close = function () use (&$sheet, $closed): void { App::later(function () use (&$sheet, $closed): void { $sheet?->close(); $closed(); }); };
        $sheet = new Window(new WindowOptions(parent: $parent, modal: true, width: 600, height: 500,
            content: Content::view(function (Context $c) use ($st, $close, &$sheet): void { self::view($c, $st, $close, $sheet); })));
        App::go(function () use ($st, &$sheet): void { // the community's list (IINA's GitHubService.fetchPluginList)
            try {
                $list = json_decode(self::fetch(Plugins::COMMUNITY_LIST), true);
                if (!is_array($list)) { throw new RuntimeException('The data couldn\'t be read because it isn\'t in the correct format.'); }
                $st->list = array_values(array_filter($list, fn($p) => is_array($p) && is_string($p['name'] ?? null) && is_string($p['url'] ?? null) && is_string($p['id'] ?? null) && is_string($p['desc'] ?? null)));
            } catch (RuntimeException $e) {
                $st->error = $e->getMessage();
                $st->list = [];
            }
            $sheet?->invalidate();
        });
    }

    /** GitHub's $url (IINA's GitHubService.request): its body; 403 rate limited, 404 not found, else what failed, thrown. */
    private static function fetch(string $url): string
    {
        [$status, $body] = Https::request('GET', $url, ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'], null, 'plugin store');
        return match ($status) {
            200 => $body,
            403 => throw new RuntimeException(Plugins::t('iinaStoreRateLimited')),
            404 => throw new RuntimeException(Plugins::t('iinaStoreRepositoryNotFound')),
            default => throw new RuntimeException("HTTP $status"),
        };
    }

    /** Whether the plugin of identifier $id is installed. */
    private static function installed(string $id): bool
    {
        foreach (Plugins::all() as $p) { if ($p->identifier === $id) { return true; } }
        return false;
    }

    /** $s ("owner/repo" or an https URL) as the URL to install from, '' for one neither (IINA's PluginStorePanel.install). */
    private static function urlOf(string $s): string
    {
        return str_starts_with($s, 'https://') ? $s : (preg_match(Plugins::GITHUB_REPO, $s) === 1 ? "https://github.com/$s" : '');
    }

    private static function view(Context $c, object $st, Closure $close, ?Window &$sheet): void
    {
        $t = $c->theme;
        $k = MediaPlayerSettings::colors($t);
        $rt = $c->runtime;
        $c->column(0, 8, 1)->padding(20, 20, 16, 20)->background($k['page']);
          // The URL: owner/repo, Install (prominent; a spinner while installing).
          $c->row(0, 8)->fillWidth()->alignItems(Node::CENTER);
            $c->text(MediaPlayerSettings::tr($c, '$InputGithubURL'), 13, $t->text);
            $field = $c->input($st->url, 'owner/repo', 0, 13)->grow(1);
            $text = trim($st->url->text);
            $valid = preg_match(Plugins::GITHUB_REPO, $text) === 1 || preg_match(Plugins::GITHUB_URL, $text) === 1;
            $b = $c->row(0, 6)->height(24)->paddingX(12)->radius(8)->alignItems(Node::CENTER)->role(Role::BUTTON)->background($t->accent, $valid && !$st->installing ? 1.0 : 0.45);
            if (!$valid || $st->installing) { $b->disabled(); }
              if ($st->installing) { $c->spinner()->size(12, 12); }
              $c->text(MediaPlayerSettings::tr($c, $st->installing ? '$Installing' : '$Install'), 13, 0xffffff);
            $c->end();
            if ($valid && !$st->installing && ($c->clicked($b) || $c->submitted($field))) {
                $st->installing = true;
                $before = count(Plugins::all());
                App::later(fn() => MediaPlayerPluginsPage::installFromGitHub(self::urlOf($text), $sheet, function () use ($st, $before, $close, $rt): void {
                    $st->installing = false;
                    if (count(Plugins::all()) > $before) { $close(); } // installed from the URL: the sheet closes, IINA's (else nothing tells it went in)
                    $rt?->changed();
                }));
            }
          $c->end();
          $c->box(0, 1, $k['line'])->fillWidth()->margin(8, 0, 8, 0);
          $c->text(MediaPlayerSettings::tr($c, '$OrSelectFrom'), 13, $t->text);
          $c->row(0, 4)->grow(1)->fillWidth()->alignItems(Node::STRETCH);
            // The lists: the official plugins, the community's.
            $c->scroll()->width(240);
              $c->column(0, 2)->fillWidth();
                $section = function (string $title) use ($c, $t): void { $c->text($title, 11, $t->textMuted, Font::BOLD)->margin(8, 0, 2, 8); };
                $item = function (array $p) use ($c, $t, $st, $k): void {
                    $on = ($st->selected['url'] ?? null) === $p['url'];
                    $r = $c->row(0, 6)->height(26)->paddingX(8)->radius(6)->alignItems(Node::CENTER)->fillWidth()->role(Role::BUTTON)->label($p['name']);
                    if ($on) { $r->background($t->accent, 0.85); }
                      MediaPlayerSettings::sf($c, self::installed($p['id']) ? 'checkmark.circle.fill' : 'square.and.arrow.down', 13, $on ? 0xffffff : $t->text);
                      $c->text($p['name'], 13, $on ? 0xffffff : $t->text)->singleLine()->ellipsis('…');
                    $c->end();
                    if ($c->clicked($r)) { $st->selected = $p; }
                };
                $section(MediaPlayerSettings::tr($c, '$OfficialPlugins'));
                foreach (Plugins::OFFICIAL as $p) { $item($p); }
                $section(MediaPlayerSettings::tr($c, '$CommunityPlugins'));
                if ($st->error !== null) {
                    $c->text(Plugins::t('iinaStoreError', ['message' => $st->error]), 13, $t->textMuted)->fillWidth()->paddingX(8)->maxLines(5);
                } elseif ($st->list === null) {
                    $c->row(0, 6)->paddingX(8)->alignItems(Node::CENTER); $c->spinner()->size(12, 12); $c->text(Plugins::t('iinaStoreLoading'), 13, $t->textMuted); $c->end();
                } else {
                    foreach ($st->list as $p) { $item($p); }
                }
              $c->end();
            $c->end();
            // The one chosen (IINA's PluginDetailView in a GroupBox).
            $c->column()->width(0)->grow(1)->radius(8)->background($t->dark ? 0xffffff : 0x000000, 0.05)->borderWidth(1)->borderColor($t->dark ? 0x3a3a3a : 0xd8d8d8);
              $c->scroll()->grow(1)->fillWidth();
                $c->column(0, 4)->fillWidth()->padding(8, 8, 8, 8);
                  self::detail($c, $st, $sheet);
                $c->end();
              $c->end();
            $c->end();
          $c->end();
          $c->row()->fillWidth()->justify(Node::END)->margin(8, 0, 0, 0);
            if (MediaPlayerSettings::pushButton($c, Plugins::t('done'), false) || $c->shortcut(Key::ESCAPE)) { $close(); }
          $c->end();
        $c->end();
    }

    /** The plugin chosen: its name, identifier, description; its link and Install button and its repository where it is GitHub's, else IINA's word that it is not. */
    private static function detail(Context $c, object $st, ?Window $sheet): void
    {
        $t = $c->theme;
        $p = $st->selected;
        if ($p === null) {
            $c->text(MediaPlayerSettings::tr($c, '$NoSelection'), 13, $t->textMuted, Font::BOLD);
            return;
        }
        $c->text($p['name'], 14, $t->text, Font::BOLD);
        $c->text($p['id'], 11, $t->text, Font::MONO)->margin(0, 0, 6, 0);
        $c->text($p['desc'], 13, $t->textMuted)->fillWidth()->margin(0, 0, 4, 0);
        $link = $c->text($p['url'], 11, $t->accent)->fillWidth()->role(Role::LINK);
        if ($c->clicked($link)) { $c->openURL($p['url']); }
        if (preg_match(Plugins::GITHUB_URL, $p['url']) !== 1) {
            $c->text(MediaPlayerSettings::tr($c, '$ThisPluginIsNot'), 11, $t->text)->fillWidth()->margin(8, 0, 0, 0);
            return;
        }
        $installed = self::installed($p['id']);
        $rt = $c->runtime;
        $b = $c->row(0, 6)->height(24)->paddingX(12)->radius(8)->alignItems(Node::CENTER)->role(Role::BUTTON)->margin(8, 0, 8, 0)->background($t->accent, $installed || $st->detailInstalling ? 0.45 : 1.0);
        if ($installed || $st->detailInstalling) { $b->disabled(); }
          $st->detailInstalling ? $c->spinner()->size(12, 12) : MediaPlayerSettings::sf($c, $installed ? 'checkmark.circle.fill' : 'square.and.arrow.down', 12, 0xffffff);
          $c->text(MediaPlayerSettings::tr($c, $installed ? '$Installed' : ($st->detailInstalling ? '$Installing' : '$Install')), 13, 0xffffff);
        $c->end();
        if (!$installed && !$st->detailInstalling && $c->clicked($b)) {
            $st->detailInstalling = true;
            App::later(fn() => MediaPlayerPluginsPage::installFromGitHub($p['url'], $sheet, function () use ($st, $rt): void { $st->detailInstalling = false; $rt?->changed(); }));
        }
        $c->box(0, 1, MediaPlayerSettings::colors($t)['line'])->fillWidth()->margin(8, 0, 12, 0);
        self::repo($c, $st, (string)parse_url($p['url'], PHP_URL_PATH), $sheet);
    }

    /** The repository at $path ("/owner/repo") as GitHub tells it (IINA's RepoDetailView), fetched once. */
    private static function repo(Context $c, object $st, string $path, ?Window $sheet): void
    {
        $t = $c->theme;
        $r = $st->repos[$path] ?? null;
        if ($r === null) {
            $st->repos[$path] = ['loading' => true];
            App::go(function () use ($st, $path, $sheet): void {
                try {
                    $data = json_decode(self::fetch("https://api.github.com/repos$path"), true);
                    if (!is_array($data) || !is_string($data['name'] ?? null) || !is_array($data['owner'] ?? null)) { throw new RuntimeException('The data couldn\'t be read because it isn\'t in the correct format.'); }
                    $avatar = null;
                    if (is_string($data['owner']['avatar_url'] ?? null)) {
                        try { [$status, $bytes] = Https::request('GET', $data['owner']['avatar_url'], [], null, 'plugin store'); $avatar = $status === 200 ? Image::fromBytes($bytes, 72) : null; } catch (\Throwable) { $avatar = null; } // its picture, else the gray IINA shows meanwhile
                    }
                    $st->repos[$path] = ['data' => $data, 'avatar' => $avatar];
                } catch (RuntimeException $e) {
                    $st->repos[$path] = ['error' => $e->getMessage()];
                }
                $sheet?->invalidate();
            });
            $r = $st->repos[$path];
        }
        if (isset($r['loading'])) {
            $c->row(0, 6)->alignItems(Node::CENTER); $c->spinner()->size(12, 12); $c->text(Plugins::t('iinaStoreLoading'), 13, $t->textMuted); $c->end();
            return;
        }
        if (isset($r['error'])) {
            $c->column(0, 6)->fillWidth()->alignItems(Node::CENTER);
              MediaPlayerSettings::sf($c, 'exclamationmark.triangle', 20, $t->text);
              $c->text(Plugins::t('iinaStoreFailedToLoad'), 13, $t->text, Font::BOLD);
              $c->text($r['error'], 11, $t->textMuted)->textAlign(Node::CENTER);
            $c->end();
            return;
        }
        $d = $r['data'];
        $c->column(0, 12)->fillWidth();
          $c->row(0, 12)->alignItems(Node::CENTER);
            $box = $c->column()->size(36, 36)->radius(6)->clip()->background(0x808080, 0.2);
              if ($r['avatar'] !== null) { $c->image($r['avatar'], 36, 36); }
            $c->end();
            $c->column(0, 0);
              $c->text((string)$d['name'], 13, $t->text, Font::BOLD);
              $c->text((string)($d['owner']['login'] ?? ''), 11, $t->textMuted);
            $c->end();
          $c->end();
          if (is_string($d['description'] ?? null)) { $c->text($d['description'], 13, $t->textMuted)->fillWidth(); }
          $c->row(0, 20)->alignItems(Node::CENTER);
            foreach ([['star.fill', (string)($d['stargazers_count'] ?? 0)], ['tuningfork', (string)($d['forks_count'] ?? 0)], ['exclamationmark.circle', (string)($d['open_issues_count'] ?? 0)],
                ...(is_string($d['language'] ?? null) ? [['chevron.left.forwardslash.chevron.right', $d['language']]] : [])] as [$symbol, $label]) {
                $c->row(0, 4)->alignItems(Node::CENTER);
                  MediaPlayerSettings::sf($c, $symbol, 12, $t->textMuted);
                  $c->text($label, 12, $t->textMuted);
                $c->end();
            }
          $c->end();
          $when = strtotime((string)($d['pushed_at'] ?? $d['updated_at'] ?? ''));
          if ($when !== false) { $c->text(self::updated($when, time()), 11, $t->textMuted); }
        $c->end();
    }

    /** When a repository was last pushed to, as IINA tells it: "Updated 3 days ago" within 30 days, else "Updated on" its date (medium). */
    public static function updated(int $when, int $now): string
    {
        $days = intdiv($now - $when, 86400);
        if ($days >= 30) { return Plugins::t('iinaStoreUpdatedOn', ['date' => date('M j, Y', $when)]); }
        $s = max(0, $now - $when);
        [$n, $unit] = match (true) {
            $s < 60 => [$s, 'second'],
            $s < 3600 => [intdiv($s, 60), 'minute'],
            $s < 86400 => [intdiv($s, 3600), 'hour'],
            $s < 7 * 86400 => [intdiv($s, 86400), 'day'],
            default => [intdiv($s, 7 * 86400), 'week'],
        };
        return Plugins::t('iinaStoreUpdatedAgo', ['ago' => "$n $unit" . ($n === 1 ? '' : 's') . ' ago']);
    }
}
