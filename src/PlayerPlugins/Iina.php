<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

use Closure;

/**
 * What a plugin's entry is given, IINA's global `iina`: its APIs, those of
 * a player (core, mpv, event, playlist, subtitle, overlay, sidebar) only in a player's
 * instance, null in the global one's; and IINA's timers (setTimeout,
 * setInterval and their clearing, JavaScript's in IINA). What IINA's
 * promise gives, these return, waiting meanwhile (in a fiber of the
 * plugin's: the app goes on); what it rejects, they throw.
 */
final class Iina
{
    public readonly ConsoleApi $console;
    public readonly PreferencesApi $preferences;
    public readonly MenuApi $menu;
    public readonly UtilsApi $utils;
    public readonly FileApi $file;
    public readonly HttpApi $http;
    public readonly ?CoreApi $core;
    public readonly ?MpvApi $mpv;
    public readonly ?EventApi $event;
    public readonly ?PlaylistApi $playlist;
    public readonly ?SubtitleApi $subtitle;
    public readonly ?OverlayApi $overlay;
    public readonly ?SidebarApi $sidebar;
    public readonly StandaloneWindowApi $standaloneWindow;
    /** The global instance's controller (GlobalApi), a player's child of it (GlobalChildApi), or null where the plugin has no globalEntry. */
    public readonly GlobalApi|GlobalChildApi|null $global;

    public function __construct(private readonly Instance $instance)
    {
        $this->console = new ConsoleApi($instance);
        $this->preferences = new PreferencesApi($instance);
        $this->menu = new MenuApi($instance);
        $this->utils = new UtilsApi($instance);
        $this->file = new FileApi($instance);
        $this->http = new HttpApi($instance);
        $player = !$instance->isGlobal;
        $this->core = $player ? new CoreApi($instance) : null;
        $this->mpv = $player ? new MpvApi($instance) : null;
        $this->event = $player ? new EventApi($instance) : null;
        $this->playlist = $player ? new PlaylistApi($instance) : null;
        $this->subtitle = $player ? new SubtitleApi($instance) : null;
        $this->overlay = $player ? new OverlayApi($instance) : null;
        $this->sidebar = $player ? new SidebarApi($instance) : null;
        $this->standaloneWindow = new StandaloneWindowApi($instance);
        $this->global = $instance->isGlobal ? new GlobalApi($instance) : ($instance->plugin->globalEntryURL !== null ? new GlobalChildApi($instance) : null);
    }

    /** Calls $fn after $ms milliseconds: the timer's id. */
    public function setTimeout(Closure $fn, float $ms): string
    {
        return $this->instance->timer($fn, $ms, false);
    }

    /** Calls $fn every $ms milliseconds: the timer's id. */
    public function setInterval(Closure $fn, float $ms): string
    {
        return $this->instance->timer($fn, $ms, true);
    }

    public function clearTimeout(string $id): void
    {
        $this->instance->clearTimer($id);
    }

    public function clearInterval(string $id): void
    {
        $this->instance->clearTimer($id);
    }
}
