<?php
declare(strict_types=1);

namespace MyPHP\Platform;

use MyPHP\Rectangle;
use MyPHP\UI\{DisplayList, Image};

/**
 * A native window (mygo's platform.Window and, for native UI, its
 * Surface): the core window (\MyPHP\Window) does what the app asks
 * through it, and hears what happens to it. myphp's carries what a
 * player's window needs besides (the legacy full screen, fitting a film,
 * the players' pictures and the system materials under the content).
 */
interface Window
{
    public function handle(): mixed;

    public function setContent(Page $page, \MyPHP\WindowOptions $o): void;

    public function createSurface(\MyPHP\WindowOptions $o, \Closure $frame, ?\Closure $afterPresent): void;

    public function restoreFrame(Rectangle $r, bool $onDisplay): void;

    public function startFullScreen(): void;

    public function startMaximized(): void;

    public function isLegacyFullScreen(): bool;

    public function hasPopover(): bool;

    public function placeWebViews(): void;

    public function placeVideos(): void;

    public function newWebView(Page $page, bool $scrollToContainer, bool $transparent): WebView;

    public function contentView(): mixed;

    public function release(): void;

    public function show(): void;

    public function showInactive(): void;

    public function hide(): void;

    public function close(): void;

    public function setTitle(string $title): void;

    public function setRepresentedFile(string $path): void;

    public function setDocumentEdited(bool $edited): void;

    public function setBackgroundColor(array $rgba): void;

    public function themeChanged(): void;

    public function hasVibrancy(): bool;

    /** Whether the system draws Node::systemMaterial's materials under the content's holes; else myphp's own draws them (Node::systemMaterial). */
    public function hasSystemMaterials(): bool;

    public function requestFrame(int $every = 1, bool $repaint = false): void;

    public function wake(float $seconds, bool $repaint): void;

    public function repaintAsked(): bool;

    public function displayRate(): float;

    /** Shows progress on the Dock icon or taskbar button: a ProgressBar state, $value 0 to 1 (mygo's SetProgressBar). */
    public function setProgressBar(string $state, float $value): void;

    /** How the last frame was drawn, for FrameStats. */
    public function lastDraw(): string;

    public function backingScale(): float;

    public function isKey(): bool;

    public function setAcceptsFirstMouse(bool $on): void;

    public function setDropFormats(array $formats): void;

    public function command(string $cmd): void;

    public function passThrough(\Closure $at): void;

    public function lastFrame(): array;

    public function capture(): string;

    public function underFrame(): ?array;

    public function pageDrag(): void;

    public function hasHiddenTitleBar(): bool;

    public function sizeLimits(): array;

    public function frameSize(): array;

    public function startDrag(array $o): void;

    public function cancelDrag(): void;

    public function titleBar(): array;

    public function dragWindow(int $clicks): void;

    public function refreshRate(): float;

    public function showCursor(\MyPHP\UI\Cursor $cursor): void;

    public function setVibrancy(string $material): void;

    public function showing(): bool;

    public function isVisible(): bool;

    public function focus(): void;

    public function blur(): void;

    public function isFocused(): bool;

    public function minimize(): void;

    public function isMinimized(): bool;

    public function maximize(): void;

    public function unmaximize(): void;

    public function isMaximized(): bool;

    public function restore(): void;

    public function setFullScreen(bool $on): void;

    public function isFullScreen(): bool;

    public function setLegacyFullScreen(bool $on): void;

    public function center(): void;

    public function setBounds(Rectangle $r): void;

    public function bounds(): Rectangle;

    public function setContentBounds(Rectangle $r): void;

    public function contentBounds(): Rectangle;

    public function magnify(float $by): void;

    public function fitVideo(array $o): void;

    public function setMinimumSize(int $width, int $height): void;

    public function setMaximumSize(int $width, int $height): void;

    public function setAspectRatio(float $ratio): void;

    public function setResizable(bool $on): void;

    public function isResizable(): bool;

    public function setMovable(bool $on): void;

    public function isMovable(): bool;

    public function setFullSizeContentView(bool $on): void;

    public function setTitleBarHidden(bool $on): void;

    public function setMinimizable(bool $on): void;

    public function isMinimizable(): bool;

    public function setMaximizable(bool $on): void;

    public function isMaximizable(): bool;

    public function setClosable(bool $on): void;

    public function isClosable(): bool;

    public function setAlwaysOnTop(bool $on): void;

    public function isAlwaysOnTop(): bool;

    public function setOpacity(float $v): void;

    public function opacity(): float;

    public function setHasShadow(bool $on): void;

    public function hasShadow(): bool;

    public function setWindowButtonVisibility(bool $visible): void;

    public function setIgnoreMouseEvents(bool $on): void;

    public function setContentProtection(bool $on): void;

    public function flashFrame(bool $flash): void;

    public function setVisibleOnAllWorkspaces(bool $on): void;

    public function placePopover(): void;

    public function snapshot(float $x, float $y, int $w, int $h): Image;
}
