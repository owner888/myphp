<?php
// What the Windows backend rests on, tried on Windows before it is written (CI's windows job):
// PHP's FFI calling back into PHP from Win32 (a window procedure, through SendMessage and a
// message loop with a timer), calling COM objects through their vtables (DXGI's adapters, a
// Direct3D 11 device of WARP, which a machine without a GPU has, HLSL compiled by
// d3dcompiler_47), and a COM object of PHP called by Windows (DirectWrite's text layout
// drawn through an IDWriteTextRenderer of closures). Prints what each gave; exits 1 if one failed.
declare(strict_types=1);

if (PHP_OS_FAMILY !== 'Windows') { fwrite(STDERR, "Windows only\n"); exit(2); }

$failed = 0;
$report = function (string $what, bool $ok, string $detail = '') use (&$failed): void {
    if (!$ok) { $failed++; }
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . ($detail !== '' ? ": $detail" : '') . "\n";
    if (!$ok && getenv('GITHUB_ACTIONS') === 'true') { echo "::error::$what" . ($detail !== '' ? ': ' . strtr($detail, ["\r" => '', "\n" => '%0A']) : '') . "\n"; } // seen without the log
};
set_exception_handler(function (Throwable $e): never { echo "::error::uncaught " . strtr((string)$e, ["\r" => '', "\n" => '%0A']) . "\n"; exit(255); });
$try = function (string $what, Closure $fn) use ($report): void {
    try { $fn(); } catch (Throwable $e) { $report($what, false, get_class($e) . ': ' . $e->getMessage() . ' at line ' . $e->getLine()); }
};

// The types, apart from the libraries' functions, which take void* where a type of these is meant (FFI's types of one cdef are not another's).
$t = FFI::cdef(<<<'C'
typedef struct { uint32_t a; uint16_t b, c; uint8_t d[8]; } GUID;
typedef intptr_t (*WNDPROC)(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);
typedef struct { uint32_t cbSize, style; WNDPROC lpfnWndProc; int32_t cbClsExtra, cbWndExtra; void *hInstance, *hIcon, *hCursor, *hbrBackground; const uint16_t *lpszMenuName, *lpszClassName; void *hIconSm; } WNDCLASSEXW;
typedef struct { void *hwnd; uint32_t message; uintptr_t wParam; intptr_t lParam; uint32_t time; int32_t x, y; uint32_t lPrivate; } MSG;

typedef struct { void *qi, *addRef; uint32_t (*Release)(void *self); } IUnknownVtbl;
typedef struct { IUnknownVtbl *v; } IUnknown;
typedef struct { void *r[7]; int32_t (*EnumAdapters)(void *self, uint32_t i, void **adapter); } IDXGIFactoryVtbl;
typedef struct { IDXGIFactoryVtbl *v; } IDXGIFactory;
typedef struct { uint16_t Description[128]; uint32_t VendorId, DeviceId, SubSysId, Revision; size_t DedicatedVideoMemory, DedicatedSystemMemory, SharedSystemMemory; uint32_t luidLow; int32_t luidHigh; } DXGI_ADAPTER_DESC;
typedef struct { void *r[8]; int32_t (*GetDesc)(void *self, DXGI_ADAPTER_DESC *desc); } IDXGIAdapterVtbl;
typedef struct { IDXGIAdapterVtbl *v; } IDXGIAdapter;
typedef struct { void *r[3]; void *(*GetBufferPointer)(void *self); size_t (*GetBufferSize)(void *self); } ID3DBlobVtbl;
typedef struct { ID3DBlobVtbl *v; } ID3DBlob;

typedef struct { float left, top, width, widthIncludingTrailingWhitespace, height, layoutWidth, layoutHeight; uint32_t maxBidiReorderingDepth, lineCount; } DWRITE_TEXT_METRICS;
typedef struct { void *r[15]; int32_t (*CreateTextFormat)(void *self, const uint16_t *family, void *collection, int32_t weight, int32_t style, int32_t stretch, float size, const uint16_t *locale, void **format);
    void *r16, *r17; int32_t (*CreateTextLayout)(void *self, const uint16_t *s, uint32_t n, void *format, float maxW, float maxH, void **layout); } IDWriteFactoryVtbl;
typedef struct { IDWriteFactoryVtbl *v; } IDWriteFactory;
typedef struct { void *r[58]; int32_t (*Draw)(void *self, void *context, void *renderer, float x, float y); void *r59; int32_t (*GetMetrics)(void *self, DWRITE_TEXT_METRICS *m); } IDWriteTextLayoutVtbl;
typedef struct { IDWriteTextLayoutVtbl *v; } IDWriteTextLayout;
typedef struct { void *fontFace; float fontEmSize; uint32_t glyphCount; const uint16_t *glyphIndices; const float *glyphAdvances; const void *glyphOffsets; int32_t isSideways; uint32_t bidiLevel; } DWRITE_GLYPH_RUN;
typedef struct {
    int32_t (*QueryInterface)(void *self, const GUID *iid, void **out);
    uint32_t (*AddRef)(void *self);
    uint32_t (*Release)(void *self);
    int32_t (*IsPixelSnappingDisabled)(void *self, void *context, int32_t *disabled);
    int32_t (*GetCurrentTransform)(void *self, void *context, float *matrix);
    int32_t (*GetPixelsPerDip)(void *self, void *context, float *ppd);
    int32_t (*DrawGlyphRun)(void *self, void *context, float x, float y, int32_t mode, const DWRITE_GLYPH_RUN *run, const void *desc, void *effect);
    int32_t (*DrawUnderline)(void *self, void *context, float x, float y, const void *underline, void *effect);
    int32_t (*DrawStrikethrough)(void *self, void *context, float x, float y, const void *strike, void *effect);
    int32_t (*DrawInlineObject)(void *self, void *context, float x, float y, void *object, int32_t sideways, int32_t rtl, void *effect);
} IDWriteTextRendererVtbl;
typedef struct { IDWriteTextRendererVtbl *v; } IDWriteTextRenderer;
C);

$wide = function (string $s) use ($t): FFI\CData {
    $u = (string)iconv('UTF-8', 'UTF-16LE', $s) . "\0\0";
    $buf = $t->new('uint16_t[' . (strlen($u) / 2) . ']', false); // kept: a class name lives as long as its class
    FFI::memcpy($buf, $u, strlen($u));
    return FFI::addr($buf[0]); // a NUL-terminated string of UTF-16, as a uint16_t *
};
$narrow = function (FFI\CData $chars): string { // WCHAR[n], to its first NUL
    $s = (string)iconv('UTF-16LE', 'UTF-8', FFI::string($chars, FFI::sizeof($chars)));
    $end = strpos($s, "\0");
    return $end === false ? $s : substr($s, 0, $end);
};
$guid = function (string $s) use ($t): FFI\CData {
    $h = str_replace('-', '', $s);
    $g = $t->new('GUID', false);
    $g->a = hexdec(substr($h, 0, 8)); $g->b = hexdec(substr($h, 8, 4)); $g->c = hexdec(substr($h, 12, 4));
    for ($i = 0; $i < 8; $i++) { $g->d[$i] = hexdec(substr($h, 16 + 2 * $i, 2)); }
    return $g;
};
$hex = fn(int $hr): string => sprintf('0x%08X', $hr & 0xFFFFFFFF);

$kernel32 = FFI::cdef('void *GetModuleHandleW(const void *name); uint32_t GetLastError(void);', 'kernel32.dll');
$user32 = FFI::cdef(<<<'C'
uint16_t RegisterClassExW(const void *wc);
void *CreateWindowExW(uint32_t exStyle, const void *cls, const void *title, uint32_t style, int32_t x, int32_t y, int32_t w, int32_t h, void *parent, void *menu, void *inst, void *param);
intptr_t DefWindowProcW(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);
intptr_t SendMessageW(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);
int32_t PostMessageW(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);
int32_t PeekMessageW(void *msg, void *hwnd, uint32_t min, uint32_t max, uint32_t remove);
int32_t GetMessageW(void *msg, void *hwnd, uint32_t min, uint32_t max);
int32_t TranslateMessage(const void *msg);
intptr_t DispatchMessageW(const void *msg);
uintptr_t SetTimer(void *hwnd, uintptr_t id, uint32_t ms, void *fn);
int32_t KillTimer(void *hwnd, uintptr_t id);
int32_t DestroyWindow(void *hwnd);
C, 'user32.dll');

// 1. A window procedure of PHP: called by SendMessage (in the call), by DispatchMessage (posted), by a timer (through GetMessage, which waits).
$try('window procedure', function () use ($t, $user32, $kernel32, $wide, $report): void {
    $WM_USER = 0x0400; $WM_APP = 0x8000; $WM_TIMER = 0x0113;
    $seen = [];
    $proc = function ($hwnd, int $msg, int $wp, int $lp) use (&$seen, $user32, $WM_USER, $WM_APP, $WM_TIMER): int {
        if ($msg === $WM_USER + 1 || $msg === $WM_APP || $msg === $WM_TIMER) { $seen[] = [$msg, $wp, $lp]; return $msg === $WM_USER + 1 ? $wp * $lp : 0; }
        return $user32->DefWindowProcW($hwnd, $msg, $wp, $lp);
    };
    $wc = $t->new('WNDCLASSEXW');
    $wc->cbSize = FFI::sizeof($wc);
    $wc->lpfnWndProc = $proc;
    $wc->hInstance = $kernel32->GetModuleHandleW(null);
    $wc->lpszClassName = $wide('MyPHPProbe');
    $atom = $user32->RegisterClassExW(FFI::addr($wc));
    $report('RegisterClassExW', $atom !== 0, $atom === 0 ? 'error ' . $kernel32->GetLastError() : "atom $atom");
    $hwnd = $user32->CreateWindowExW(0, $wide('MyPHPProbe'), $wide('probe'), 0, 0, 0, 100, 100, null, null, $wc->hInstance, null);
    $report('CreateWindowExW', $hwnd !== null, $hwnd === null ? 'error ' . $kernel32->GetLastError() : '');
    if ($hwnd === null) { return; }
    $r = $user32->SendMessageW($hwnd, $WM_USER + 1, 6, 7);
    $report('SendMessageW into PHP and its result back', $r === 42 && $seen === [[$WM_USER + 1, 6, 7]], "returned $r");
    $user32->PostMessageW($hwnd, $WM_APP, 1, 2);
    $msg = $t->new('MSG');
    while ($user32->PeekMessageW(FFI::addr($msg), null, 0, 0, 1) !== 0) { $user32->TranslateMessage(FFI::addr($msg)); $user32->DispatchMessageW(FFI::addr($msg)); }
    $report('PostMessageW, dispatched from the queue', in_array([$WM_APP, 1, 2], $seen, true));
    $user32->SetTimer($hwnd, 7, 20, null);
    $start = microtime(true);
    while (microtime(true) - $start < 5 && $user32->GetMessageW(FFI::addr($msg), null, 0, 0) > 0) {
        $user32->DispatchMessageW(FFI::addr($msg));
        if (in_array($WM_TIMER, array_column($seen, 0), true)) { break; }
    }
    $user32->KillTimer($hwnd, 7);
    $report('a timer through GetMessageW, which waits', in_array([$WM_TIMER, 7, 0], $seen, true), sprintf('%.0f ms', (microtime(true) - $start) * 1000));
    $user32->DestroyWindow($hwnd);
});

$ole32 = FFI::cdef('int32_t CoInitializeEx(void *reserved, uint32_t model);', 'ole32.dll');
$ole32->CoInitializeEx(null, 2); // apartment threaded, as a UI thread

// 2. COM through vtables: DXGI's first adapter, a Direct3D 11 device of WARP, HLSL compiled.
$try('DXGI', function () use ($t, $guid, $hex, $narrow, $report): void {
    $dxgi = FFI::cdef('int32_t CreateDXGIFactory(const void *iid, void **factory);', 'dxgi.dll');
    $out = $t->new('void *');
    $hr = $dxgi->CreateDXGIFactory(FFI::addr($guid('7b7166ec-21c7-44ae-b21a-c9ae321ae369')), FFI::addr($out));
    $report('CreateDXGIFactory', $hr >= 0, $hex($hr));
    if ($hr < 0) { return; }
    $f = $t->cast('IDXGIFactory *', $out);
    $a = $t->new('void *');
    $hr = ($f->v->EnumAdapters)($f, 0, FFI::addr($a));
    $report('IDXGIFactory::EnumAdapters', $hr >= 0, $hex($hr));
    if ($hr < 0) { return; }
    $adapter = $t->cast('IDXGIAdapter *', $a);
    $desc = $t->new('DXGI_ADAPTER_DESC');
    $hr = ($adapter->v->GetDesc)($adapter, FFI::addr($desc));
    $report('IDXGIAdapter::GetDesc', $hr >= 0, $narrow($desc->Description) . sprintf(' (vendor 0x%04X)', $desc->VendorId));
    ($t->cast('IUnknown *', $a)->v->Release)($a);
    ($t->cast('IUnknown *', $out)->v->Release)($out);
});
$try('Direct3D 11', function () use ($t, $hex, $report): void {
    $d3d = FFI::cdef('int32_t D3D11CreateDevice(void *adapter, int32_t type, void *software, uint32_t flags, const void *levels, uint32_t n, uint32_t sdk, void **device, int32_t *level, void **context);', 'd3d11.dll');
    $dev = $t->new('void *'); $ctx = $t->new('void *'); $level = $t->new('int32_t');
    $hr = $d3d->D3D11CreateDevice(null, 5 /* WARP */, null, 0x20 /* BGRA support, for DirectWrite and Direct2D */, null, 0, 7, FFI::addr($dev), FFI::addr($level), FFI::addr($ctx));
    $report('D3D11CreateDevice (WARP)', $hr >= 0, $hex($hr) . sprintf(', feature level 0x%X', $level->cdata));
    if ($hr >= 0) { ($t->cast('IUnknown *', $ctx)->v->Release)($ctx); ($t->cast('IUnknown *', $dev)->v->Release)($dev); }
});
$try('HLSL', function () use ($t, $hex, $report): void {
    $c = FFI::cdef('int32_t D3DCompile(const char *src, size_t n, const char *name, const void *defines, void *include, const char *entry, const char *target, uint32_t f1, uint32_t f2, void **code, void **errors);', 'd3dcompiler_47.dll');
    $src = "float4 main(float2 p : POSITION) : SV_POSITION { return float4(p, 0, 1); }";
    $code = $t->new('void *'); $err = $t->new('void *');
    $hr = $c->D3DCompile($src, strlen($src), 'probe', null, null, 'main', 'vs_5_0', 0, 0, FFI::addr($code), FFI::addr($err));
    $detail = $hex($hr);
    if ($hr >= 0) { $b = $t->cast('ID3DBlob *', $code); $detail .= ', ' . ($b->v->GetBufferSize)($b) . ' bytes of bytecode'; ($b->v->Release)($b); }
    elseif (!FFI::isNull($err)) { $b = $t->cast('ID3DBlob *', $err); $detail .= ': ' . FFI::string(($b->v->GetBufferPointer)($b), ($b->v->GetBufferSize)($b)); }
    $report('D3DCompile (vs_5_0)', $hr >= 0, $detail);
});

// 3. DirectWrite: a layout measured, then drawn through a renderer of PHP's, which Windows calls.
$try('DirectWrite', function () use ($t, $guid, $hex, $wide, $report): void {
    $dw = FFI::cdef('int32_t DWriteCreateFactory(int32_t type, const void *iid, void **factory);', 'dwrite.dll');
    $out = $t->new('void *');
    $hr = $dw->DWriteCreateFactory(0, FFI::addr($guid('b859ee5a-d838-4b5b-a2e8-1adc7d93db48')), FFI::addr($out));
    $report('DWriteCreateFactory', $hr >= 0, $hex($hr));
    if ($hr < 0) { return; }
    $f = $t->cast('IDWriteFactory *', $out);
    $fmt = $t->new('void *');
    $hr = ($f->v->CreateTextFormat)($f, $wide('Segoe UI'), null, 400, 0, 5, 16.0, $wide('en-us'), FFI::addr($fmt));
    $report('IDWriteFactory::CreateTextFormat', $hr >= 0, $hex($hr));
    if ($hr < 0) { return; }
    $text = 'Hello, 世界';
    $u16 = $wide($text);
    $len = intdiv(strlen((string)iconv('UTF-8', 'UTF-16LE', $text)), 2);
    $l = $t->new('void *');
    $hr = ($f->v->CreateTextLayout)($f, $u16, $len, $fmt, 1000.0, 1000.0, FFI::addr($l));
    $report('IDWriteFactory::CreateTextLayout', $hr >= 0, $hex($hr));
    if ($hr < 0) { return; }
    $layout = $t->cast('IDWriteTextLayout *', $l);
    $m = $t->new('DWRITE_TEXT_METRICS');
    $hr = ($layout->v->GetMetrics)($layout, FFI::addr($m));
    $report('IDWriteTextLayout::GetMetrics', $hr >= 0 && $m->width > 10 && $m->height > 10 && $m->lineCount === 1, sprintf('%s, %.1f × %.1f, %d line', $hex($hr), $m->width, $m->height, $m->lineCount));

    $glyphs = 0; $runs = 0; $refs = 1;
    $vt = $t->new('IDWriteTextRendererVtbl', false);
    $vt->QueryInterface = function ($self, $iid, $outp) { return 0x80004002 - 0x100000000; }; // E_NOINTERFACE: Draw needs none of it
    $vt->AddRef = function ($self) use (&$refs) { return ++$refs; };
    $vt->Release = function ($self) use (&$refs) { return --$refs; };
    $vt->IsPixelSnappingDisabled = function ($self, $ctx, $disabled) { $disabled[0] = 0; return 0; };
    $vt->GetCurrentTransform = function ($self, $ctx, $matrix) { foreach ([1.0, 0.0, 0.0, 1.0, 0.0, 0.0] as $i => $v) { $matrix[$i] = $v; } return 0; };
    $vt->GetPixelsPerDip = function ($self, $ctx, $ppd) { $ppd[0] = 1.0; return 0; };
    $vt->DrawGlyphRun = function ($self, $ctx, $x, $y, $mode, $run, $desc, $effect) use (&$glyphs, &$runs) { $runs++; $glyphs += $run->glyphCount; return 0; };
    $vt->DrawUnderline = fn($self, $ctx, $x, $y, $u, $e) => 0;
    $vt->DrawStrikethrough = fn($self, $ctx, $x, $y, $s, $e) => 0;
    $vt->DrawInlineObject = fn($self, $ctx, $x, $y, $o, $sw, $rtl, $e) => 0;
    $renderer = $t->new('IDWriteTextRenderer', false);
    $renderer->v = FFI::addr($vt);
    $hr = ($layout->v->Draw)($layout, null, FFI::addr($renderer), 0.0, 0.0);
    $report('IDWriteTextLayout::Draw through a renderer of PHP', $hr >= 0 && $glyphs >= 9, sprintf('%s, %d runs, %d glyphs', $hex($hr), $runs, $glyphs));
});

echo $failed === 0 ? "all passed\n" : "$failed failed\n";
exit($failed === 0 ? 0 : 1);
