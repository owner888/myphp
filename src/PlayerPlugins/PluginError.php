<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

/** What a plugin asked of its API wrongly or without the permission (IINA's throwError, a JavaScript Error): thrown into its code. */
class PluginError extends \RuntimeException {}
