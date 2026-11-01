<?php
declare(strict_types=1);

namespace MyPHP\PlayerPlugins;

/** What failed as a plugin was installed (IINA's PluginError), its message the user's (IINA's "plugin.install_error.*"). */
final class PluginInstallError extends \RuntimeException {}
