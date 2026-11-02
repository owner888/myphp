<?php
declare(strict_types=1);

echo "[myphp:app.php] ========================================\n";
echo "[myphp:app.php] Hello from myphp user script on Android!\n";
echo "[myphp:app.php] PHP Version: " . PHP_VERSION . "\n";
echo "[myphp:app.php] OS Family: " . PHP_OS_FAMILY . "\n";
echo "[myphp:app.php] Loaded extensions:\n";
foreach (get_loaded_extensions() as $ext) {
    echo "  - " . $ext . " (" . (phpversion($ext) ?: "builtin") . ")\n";
}
echo "[myphp:app.php] FFI test:\n";
if (extension_loaded('ffi')) {
    echo "  - FFI is loaded!\n";
    try {
        $libc = FFI::cdef("int getpid(void);");
        echo "  - FFI::cdef getpid() = " . $libc->getpid() . "\n";
    } catch (\Throwable $e) {
        echo "  - FFI error: " . $e->getMessage() . "\n";
    }
} else {
    echo "  - FFI is NOT loaded!\n";
}
echo "[myphp:app.php] ========================================\n";
