<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Updater;

/**
 * The texts of the update window in one language (mygo's updater.Strings).
 * The plugin has them in English, Chinese (zh-Hans, zh-Hant), Dutch,
 * French, German, Italian, Japanese, Korean, Polish, Portuguese (pt-BR),
 * Russian, Spanish, Turkish and Ukrainian, and Options::$strings changes
 * them or adds languages; the texts left empty keep the plugin's, else the
 * English ones.
 *
 * Some are sprintf formats, whose arguments are listed: numbered ones such
 * as %2$s let a language order them.
 */
final class Strings
{
    /** The formats among the texts, with the number of their arguments. */
    public const FORMATS = ['upToDateMessage' => 2, 'unavailableMessage' => 1, 'available' => 1, 'availableMessage' => 3, 'progress' => 2, 'megabytes' => 1, 'readyMessage' => 2];

    public function __construct(
        /** Title of the window: "Software Update". */
        public string $title = '',
        /** The label of Updater::menuItem(): "Check for Updates…". */
        public string $menuItem = '',
        /** "Checking for updates…" */
        public string $checking = '',
        public string $cancel = '',
        public string $ok = '',
        /** "You’re up to date!" */
        public string $upToDate = '',
        /** A format: the app's name and version. */
        public string $upToDateMessage = '',
        /** "Updates Unavailable" */
        public string $unavailable = '',
        /** A format explaining that the app cannot write where it is installed: the app's name. */
        public string $unavailableMessage = '',
        /** Explains that development builds do not update. */
        public string $developmentBuild = '',
        /** "Update Error!" */
        public string $error = '',
        /** "An error occurred while checking for updates…" */
        public string $checkError = '',
        /** "An error occurred while installing the update…" */
        public string $installError = '',
        /** A format: the app's name. */
        public string $available = '',
        /** A format: the app's name, the new version and the running one. */
        public string $availableMessage = '',
        /** "Release Notes:" */
        public string $releaseNotes = '',
        /** The checkbox. */
        public string $automaticDownloads = '',
        /** "Skip This Version" */
        public string $skip = '',
        /** "Remind Me Later" */
        public string $remindLater = '',
        /** "Install Update" */
        public string $install = '',
        /** "Downloading update…" */
        public string $downloading = '',
        /** A format: the size downloaded and the whole size, as megabytes. */
        public string $progress = '',
        /** A format: a number, such as 12.3, with the decimal mark of the language. */
        public string $megabytes = '',
        /** "Installing update…" */
        public string $installing = '',
        /** "Ready to Relaunch" */
        public string $ready = '',
        /** A format: the app's name and the new version. */
        public string $readyMessage = '',
        public string $later = '',
        /** "Relaunch Now" */
        public string $relaunch = '',
    ) {}

    /**
     * The first of the formats set that do not fit their arguments, as an
     * error message, or null: their verbs are %s, numbered or not, within
     * the arguments, and %%.
     */
    public function check(): ?string
    {
        foreach (self::FORMATS as $name => $n) {
            $text = $this->$name;
            if ($text === '') {
                continue;
            }
            $plain = 0;
            $ok = preg_match_all('/%(?:%|(?:([0-9]+)\$)?(.?))/s', $text, $m, PREG_SET_ORDER) !== false;
            foreach ($m as $v) {
                if ($v[0] === '%%') {
                    continue;
                }
                $index = $v[1] === '' ? ++$plain : (int)$v[1];
                if (($v[2] ?? '') !== 's' || $index < 1 || $index > $n) {
                    $ok = false;
                }
            }
            if (!$ok) {
                return "$name \"$text\" is not a format of $n strings";
            }
        }
        return null;
    }

    /** These texts, with those left empty taken from $fallback. @param array<string, string> $fallback */
    public function merged(array $fallback): self
    {
        $s = clone $this;
        foreach (get_object_vars($s) as $k => $v) {
            if ($v === '') {
                $s->$k = $fallback[$k] ?? '';
            }
        }
        return $s;
    }
}
