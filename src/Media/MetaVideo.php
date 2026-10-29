<?php
declare(strict_types=1);

namespace MyPHP\Media;

/**
 * What a video file's name tells of the film or the episode it is, as
 * agc_player's MetaVideo (its Flutter client's) reads it: the name (the
 * Chinese one if any, else the English), the year, whether it is of a TV
 * show and its season and episode, and a TMDB id written in it
 * ({tmdb-123}, {tmdbid=123}) or in its folder's name. What it reads of
 * the release (its resolution, source, codecs) is only to tell where the
 * name ends; it is not kept.
 *
 * @internal UI\VideoPlayerState
 */
final class MetaVideo
{
    private const SEASON = '/S(\d{3})|^S(\d{1,3})$|S(\d{1,3})E/i';
    private const SEASON_CN = '/第(.*?)季/iu';
    private const SEASON_CN_NUMBER = '/(?<=第).(?=季)/u';
    private const SEASON_NAME = '/第(.*?)季(\d{1,4})/iu';
    private const SEASON_CN_NAME = '/[\x{4e00}-\x{9fa5}](\d{2})(?!\d)/iu';
    private const NUMBERED_FILE = '/^(\d+)\.(mp4|mkv|avi|mov|wmv|flv|webm|m4v|3gp|rm|rmvb|asf|mpg|mpeg|ts|m2ts|vob|iso)$/i';
    private const EPISODE = '/\bEP?(\d{2,4})$|^EP?(\d{1,4})$|^S\d{1,2}EP?(\d{1,4})$|\bS\d{2}EP?(\d{2,4})/i';
    private const EPISODE_CN = '/第(.*?)(集|回|章)/iu';
    private const EPISODE_CN_NUMBER = '/(?<=第).(?=集)/iu';
    private const PART = '/(^PART[0-9ABI]{0,2}$|^CD[0-9]{0,2}$|^DVD[0-9]{0,2}$|^DISK[0-9]{0,2}$|^DISC[0-9]{0,2}$)/i';
    private const ROMAN = '/^(?=[MDCLXVI])M*(C[MD]|D?C{0,3})(X[CL]|L?X{0,3})(I[XV]|V?I{0,3})$/i';
    private const SOURCE = '^BLURAY$|^HDTV$|^UHDTV$|^HDDVD$|^WEBRIP$|^DVDRIP$|^BDRIP$|^BLU$|^WEB$|^BD$|^HDRip$|^REMUX$|^UHD$';
    private const EFFECT = '^SDR$|^HDR\d*$|^DOLBY$|^DOVI$|^DV$|^3D$|^REPACK$';
    private const NAME_NO_BEGIN = '/^[\[【].+?[\]】]/u';
    private const NAME_NO_STRING = '/^PTS|^JADE|^AOD|^CHC|^[A-Z]{1,4}TV[\-0-9UVHDK]*'
        . '|HBO$|\s+HBO|\d{1,2}th|\d{1,2}bit|NETFLIX|AMAZON|IMAX|^3D|\s+3D|^BBC\s+|\s+BBC|BBC$|DISNEY\+?|XXX|\s+DC$'
        . '|[第\s共]+[0-9一二三四五六七八九十\-\s]+季'
        . '|[第\s共]+[0-9一二三四五六七八九十百零\-\s]+[集话話]'
        . '|连载|日剧|美剧|电视剧|动画片|动漫|欧美|西德|日韩|超高清|高清|无水印|下载|蓝光|翡翠台|梦幻天堂·龙网|★?\d*月?新番'
        . '|最终季|合集|[多中国英葡法俄日韩德意西印泰台港粤双文语简繁体特效内封官译外挂]+字幕|版本|出品|台版|港版|\w+字幕组|\w+字幕社'
        . '|未删减版|UNCUT$|UNRATE$|WITH EXTRAS$|RERIP$|SUBBED$|PROPER$|REPACK$|SEASON$|EPISODE$|Complete$|Extended$|Extended Version$'
        . '|S\d{2}\s*-\s*S\d{2}|S\d{2}|\s+S\d{1,2}|EP?\d{2,4}\s*-\s*EP?\d{2,4}|EP?\d{2,4}|\s+EP?\d{1,4}'
        . '|CD[\s.]*[1-9]|DVD[\s.]*[1-9]|DISK[\s.]*[1-9]|DISC[\s.]*[1-9]'
        . '|[248]K|\d{3,4}[PIX]+'
        . '|CD[\s.]*[1-9]|DVD[\s.]*[1-9]|DISK[\s.]*[1-9]|DISC[\s.]*[1-9]|\s+GB/iu'; // of no case, as MoviePilot's (agc_player's source) has it: Season, 1080p
    private const PIX = '/^[SBUHD]*(\d{3,4}[PI]+)|\d{3,4}X(\d{3,4})/i';
    private const PIX_K = '/(^[248]+K)/i';
    private const VIDEO_ENCODE = '/^[HX]26[45]$|^AVC$|^HEVC$|^VC\d?$|^MPEG\d?$|^Xvid$|^DivX$|^HDR\d*$/i';
    private const AUDIO_ENCODE = '/^DTS\d?$|^DTSHD$|^DTSHDMA$|^Atmos$|^TrueHD\d?$|^AC3$|^\dAudios?$|^DDP\d?$|^DD\d?$|^LPCM\d?$|^AAC\d?$|^FLAC\d?$|^HD\d?$|^MA\d?$/i';
    private const NAME_SE_WORDS = ['共', '第', '季', '集', '话', '話', '期'];
    private const NAME_MOVIE_WORDS = '/剧场版|劇場版|电影版|電影版/u';
    private const NAME_NO_CHINESE = '/.*版|.*字幕/u';
    private const NCOP = '/NCOP(\d{1,2})/', NCED = '/NCED(\d{1,2})/';
    private const CHINESE_ENGLISH = '/([\x{4e00}-\x{9fa5}]+)[A-Za-z]+$/u';
    private const CHINESE = '/[\x{4e00}-\x{9fa5}]+/u';
    private const TV_EPISODE = '/^\D*[-\s](\d{2})(?!\d).*/u';
    private const SEZON = '/\((\d+)\.sezon\.(\d+)\.serija\.iz\.(\d+)\)/';
    private const SE = '/SE\d{2}\.\d{2}/';
    private const TMDB_ID = '/\{tmdb(?:id)?[-=](\d+)\}/';
    private const AV = '/^([A-Za-z0-9]{2,6}(?:-[A-Za-z0-9]{2,6})?)-(\d{3,7}|(?:\d{8}))(?:-([A-Za-z0-9]+))?(\[.*?\])?(?:[_-]([A-Za-z0-9]+))?\.(mp4|mkv|avi|mov|wmv|flv|webm|m4v|3gp|rm|rmvb|asf|mpg|mpeg|ts|m2ts|vob|iso)$/i';
    private const TOKENS = '/[.\s()\[\]\-+【】\/～;&|#_「」~]+/u';
    private const IGNORE = ['中影论坛'];
    private const REPLACE = ['影视帝国(bbs.dg2012.com)', '追光寻影（zgxybbs.fdns.uk）', '魅力社989pa.com', '修正', 'HD中字'];
    /** agc_player's videoSuffix: the extensions, and codecs' names, left out of a name. */
    private const SUFFIXES = ['mkv', 'mp4', 'avi', 'rmvb', 'wmv', 'flv', 'mov', 'ts', 'm2ts', 'm4v', '3gp', '3g2', 'webm', 'mpeg', 'mpg', 'dat', 'vob', 'rm', 'asf', 'divx', 'xvid', 'f4v', 'h264', 'h265', 'hevc', 'vp9', 'vp8', 'av1', 'avc', 'x264', 'x265', 'mpeg2', 'mpeg4', 'h263', 'h261'];

    public ?string $cnName = null, $enName = null;
    public ?int $year = null;
    public bool $tv = false;
    public ?int $season = null, $episode = null;
    public ?int $tmdbId = null;

    private ?int $endEpisode = null;
    private bool $pix = false, $stopName = false, $stopCnName = false, $continue = true;
    private string $lastType = '';
    private ?string $unknownName = null;
    /** @var list<string> */
    private array $tokens = [];
    private int $at = 0;

    /** What file name $title (its extension with it), in a folder of name $dir, tells. */
    public function __construct(string $title, string $dir = '')
    {
        if ($title === '') {
            return;
        }
        if (preg_match(self::AV, $title, $m) === 1) { // a code of an adult video's: no film to look up
            $this->cnName = $this->enName = "$m[1]-$m[2]";
            return;
        }
        if (preg_match(self::TMDB_ID, $title, $m) === 1) {
            $this->tmdbId = (int)$m[1];
            $title = (string)preg_replace(self::TMDB_ID, '', $title);
        }
        if ($this->tmdbId === null && preg_match_all(self::TMDB_ID, $dir, $all) > 0) {
            $this->tmdbId = (int)end($all[1]); // the folder's (tinyMediaManager's, Jellyfin's way), its last
        }
        $dir = (string)preg_replace(self::TMDB_ID, '', $dir);
        if (preg_match(self::SEZON, $title, $m) === 1) {
            [$this->season, $this->episode, $this->tv] = [(int)$m[1], (int)$m[2], true];
            $title = str_replace("$m[0].", '', $title);
        }
        if (preg_match(self::SE, $title, $m) === 1) {
            [$this->season, $this->episode, $this->tv] = [(int)substr($m[0], 2, 2), (int)substr($m[0], 5, 2), true];
            $title = str_replace("$m[0].", '', $title);
        }
        if (self::digits($title) && strlen($title) < 5) {
            [$this->episode, $this->tv] = [(int)$title, true];
            return;
        }
        if (preg_match('/^Season\s+(\d{1,3})$|^S(\d{1,3})$/', $title, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            $this->tv = true;
            if ($m[1] !== null) { $this->season = (int)$m[1]; }
            return;
        }
        $title = str_replace([...self::REPLACE, "\u{200E}"], '', $title);
        $title = (string)preg_replace(self::NAME_NO_BEGIN, '', $title, 1);
        $title = (string)preg_replace('/([\s.]+)(\d{4})-(\d{4})/u', '', $title); // a range of years
        $title = (string)preg_replace('/[0-9.]+\s*[MGT]i?B(?![A-Z]+)/iu', '', $title); // a size: 1.5GiB
        $title = (string)preg_replace('/\d{4}[\s._-]\d{1,2}[\s._-]\d{1,2}/u', '', $title); // a date
        $this->tokens = array_values(array_filter(preg_split(self::TOKENS, $title) ?: [], fn(string $t) => $t !== ''));
        while (($token = $this->next()) !== null) {
            foreach (self::IGNORE as $ignored) {
                if (str_contains($token, $ignored)) { continue 2; }
            }
            $this->part($token);
            if ($this->continue) { $this->nameOf($token); }
            if ($this->continue) { $this->year($token); }
            if ($this->continue) { $this->pix($token); }
            if ($this->continue) { $this->seasonOf($token); }
            if ($this->continue) { $this->episodeOf($token); }
            if ($this->continue) { $this->source($token); }
            if ($this->continue) { $this->videoEncode($token); }
            if ($this->continue) { $this->audioEncode($token); }
            $this->continue = true;
        }
        $this->cnName = self::fixName($this->cnName);
        $this->enName = self::fixName($this->enName);
        if (($this->cnName ?? '') === '' && ($this->enName ?? '') === '') { // a name of no title (01.mkv, 第3集.mp4): the folder's
            $this->fromFolder($title, $dir);
        }
        if (!$this->tv && preg_match(self::TV_EPISODE, $title, $m) === 1) { // a name, then a number of two digits: 葬送的芙莉蓮 - 12
            [$this->tv, $this->season, $this->episode] = [true, 1, (int)$m[1]]; // agc_player's took NUMBERED_FILE's whole match, which is no number, so 1
        }
    }

    /** The name to look it up by: the Chinese one if any, else the English, else ''. */
    public function name(): string
    {
        return trim($this->cnName ?? '') !== '' ? $this->cnName : ($this->enName ?? '');
    }

    private function fromFolder(string $title, string $dir): void
    {
        $this->cnName = trim($dir);
        $seasonCn = preg_match(self::SEASON_CN, $dir) === 1;
        $numbered = preg_match(self::NUMBERED_FILE, $title) === 1;
        $episodeCn = preg_match(self::EPISODE_CN, $title) === 1;
        $episode = preg_match(self::EPISODE, $title) === 1;
        if (!$seasonCn && !$numbered && !$episodeCn && !$episode) {
            return;
        }
        [$this->tv, $this->season] = [true, 1];
        if ($seasonCn) {
            $this->season = self::chineseNumber(self::match(self::SEASON_CN_NUMBER, self::match(self::SEASON_CN, $dir) ?? '') ?? '一');
            $this->cnName = trim((string)preg_replace(self::SEASON_CN, '', $dir));
        }
        if ($numbered) {
            preg_match(self::NUMBERED_FILE, $title, $m);
            $this->episode = (int)$m[1]; // its number (agc_player's took the whole match, which is no number, so 1)
        }
        if ($episodeCn) {
            $this->episode = self::chineseNumber(self::match(self::EPISODE_CN_NUMBER, self::match(self::EPISODE_CN, $title) ?? '') ?? '一');
        }
        if ($episode && $this->episode === null) {
            preg_match_all(self::EPISODE, $title, $all, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($all as $m) {
                $value = $m[1] ?? $m[2] ?? $m[3] ?? $m[4] ?? null;
                if ($value !== null && $this->episode === null) { $this->episode = (int)$value; }
            }
        }
    }

    private function next(): ?string
    {
        $t = $this->tokens[$this->at] ?? null;
        if ($t !== null) { $this->at++; }
        return $t;
    }

    /** Whether the token taken is the last but one (agc_player's isEnd, after its getNext): before the extension. */
    private function nearEnd(): bool
    {
        return $this->at >= count($this->tokens) - 1;
    }

    private function nameOf(string $token): void
    {
        if ($token === '') {
            return;
        }
        if (preg_match(self::CHINESE_ENGLISH, $token) === 1) { // a Chinese name with English after: the Chinese
            $cn = (string)self::match(self::CHINESE, $token);
            $token = substr($token, 0, (int)strpos($token, $cn) + strlen($cn));
        }
        if (preg_match(self::SEASON_NAME, $token, $m) === 1) {
            $token = str_replace($m[0], '', $token);
            $season = self::match(self::SEASON_CN, $m[0]) ?? '';
            $this->season = self::chineseNumber(self::match(self::SEASON_CN_NUMBER, $season) ?? '一');
            $this->episode = self::int(str_replace($season, '', $m[0]));
        } elseif (preg_match(self::SEASON_CN_NAME, $token, $m) === 1 && $m[0] === $token) {
            [$this->season, $this->episode, $this->tv] = [1, null, true]; // agc_player's: the number of a name such as 剧01 is not read
        }
        if ($this->unknownName !== null) { // a number kept, now part of the name
            if ($this->cnName === null) {
                $this->enName = $this->enName === null ? $this->unknownName : "$this->enName $this->unknownName";
                $this->lastType = 'enname';
            }
            $this->unknownName = null;
        }
        if ($this->stopName) {
            return;
        }
        if (strtoupper($token) === 'AKA') {
            [$this->continue, $this->stopName] = [false, true];
            return;
        }
        if (in_array($token, self::NAME_SE_WORDS, true)) {
            $this->lastType = 'name_se_words';
            return;
        }
        if (self::chinese($token)) {
            $this->lastType = 'cnname';
            if ($this->cnName === null) {
                $this->cnName = $token;
            } elseif (!$this->stopCnName) {
                if (preg_match(self::NAME_MOVIE_WORDS, $token) === 1 || preg_match(self::NAME_NO_CHINESE, $token) !== 1) {
                    $this->cnName = "$this->cnName $token";
                }
                $this->stopCnName = true;
            }
            return;
        }
        $roman = preg_match(self::ROMAN, $token) === 1;
        if (self::digits($token) || $roman) {
            if ($this->lastType === 'name_se_words') {
                return;
            }
            if ($this->cnName !== null) {
                if (str_starts_with($token, '0') || !self::digits($token) || ($this->lastType === 'cnname' && (int)$token < 1900)) {
                    return; // agc_player's int.parse: a Roman numeral after a Chinese name not taken
                }
                if (strlen($token) < 4) {
                    if ($this->lastType === 'cnname') { $this->cnName = "$this->cnName $token"; }
                    elseif ($this->lastType === 'enname') { $this->enName = "$this->enName $token"; }
                    $this->continue = false;
                }
            } elseif ($this->unknownName === null && strlen($token) !== 4 && !self::digits($token) && !in_array($token, self::SUFFIXES, true)) {
                $this->unknownName = $token; // a Roman numeral, kept for the name if one follows
            }
        } elseif (preg_match(self::SEASON, $token) === 1) {
            if ($this->enName !== null && preg_match('/SEASON$/i', $this->enName) === 1) { $this->enName .= ' '; }
            $this->stopName = true;
        } elseif (preg_match(self::EPISODE, $token) === 1 || preg_match('/' . self::SOURCE . '|' . self::EFFECT . '/i', $token) === 1 || preg_match(self::PIX, $token) === 1) {
            $this->stopName = true;
        } elseif (!in_array(strtolower($token), self::SUFFIXES, true)) {
            $this->enName = $this->enName === null ? $token : "$this->enName $token";
            $this->lastType = 'enname';
        }
    }

    private static function fixName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return $name;
        }
        $name = (string)preg_replace('/\s+/u', ' ', trim((string)preg_replace(self::NAME_NO_STRING, '', trim($name)))); // trimmed first: the "Season " of a name such as Game.of.Thrones.Season.8 off
        if ($name !== '' && str_contains($name, ' ') && self::chinese(str_replace(' ', '', $name))) {
            $name = explode(' ', $name)[0]; // a Chinese name's first word
        }
        return $name;
    }

    private function pix(string $token): void
    {
        if (preg_match(self::PIX, $token) === 1 || preg_match(self::PIX_K, $token) === 1) {
            [$this->pix, $this->lastType, $this->continue, $this->stopName] = [true, 'pix', false, true];
        }
    }

    private function seasonOf(string $token): void
    {
        if (preg_match_all(self::SEASON, $token, $all) > 0) {
            [$this->lastType, $this->tv, $this->stopName, $this->continue] = ['season', true, true, true];
            foreach ($all[0] as $s) {
                $n = self::int(str_replace(['s', 'e'], '', strtolower($s)));
                if ($n !== null) { $this->season ??= $n; }
            }
        } elseif (preg_match_all(self::SEASON_CN, $token, $all) > 0) {
            [$this->lastType, $this->tv, $this->stopName, $this->continue] = ['season', true, true, true];
            foreach ($all[0] as $s) { $this->season ??= self::chineseNumber(str_replace(['第', '季'], '', $s)); }
        } elseif (self::digits($token)) {
            if ($this->lastType === 'SEASON' && $this->season === null && strlen($token) < 3) {
                [$this->season, $this->lastType, $this->stopName, $this->continue, $this->tv] = [(int)$token, 'season', true, false, true];
            }
        } elseif (strtoupper($token) === 'SEASON' && $this->season === null) {
            $this->lastType = 'SEASON';
        } elseif ($this->tv && $this->season === null) {
            $this->season = 1;
        }
    }

    private function episodeOf(string $token): void
    {
        if (preg_match_all(self::EPISODE, $token, $all, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) > 0) {
            [$this->lastType, $this->continue, $this->stopName, $this->tv] = ['episode', false, true, true];
            foreach ($all as $m) {
                $value = $m[1] ?? $m[2] ?? $m[3] ?? $m[4] ?? null;
                if ($value !== null) { $this->episodes((int)$value); }
            }
        } elseif (preg_match_all(self::EPISODE_CN, $token, $all) > 0) {
            foreach ($all[0] as $e) {
                $n = self::int(str_replace(['第', '集'], '', $e));
                if ($n !== null) { $this->episodes($n); $this->tv = true; }
            }
        } elseif (preg_match(self::NCOP, $token, $m) === 1 || preg_match(self::NCED, $token, $m) === 1) {
            [$this->episode, $this->tv] = [(int)$m[1], true]; // an anime's opening or ending without credits
        } elseif (self::digits($token) && (int)$token !== 265) {
            $n = (int)$token;
            if ($this->episode !== null && $this->endEpisode === null && strlen($token) < 5 && $n > $this->episode && $this->lastType === 'episode') {
                $this->endEpisode = $n - $this->episode + 1 > 2 ? null : $n; // a file of two episodes at the most
                [$this->continue, $this->tv] = [false, true];
            } elseif ($this->episode === null && $this->season === null && $this->nearEnd() && $n < 100 && $this->lastType !== 'audioencode') { // not the 1 of DDP5.1, the 0 of AAC2.0
                [$this->episode, $this->tv] = [$n, true];
            } elseif ($this->lastType === 'EPISODE' && $this->episode === null && strlen($token) < 5) {
                [$this->episode, $this->lastType, $this->continue, $this->stopName, $this->tv] = [$n, 'episode', false, true, true];
            }
        } elseif (strtoupper($token) === 'EPISODE') {
            $this->lastType = 'EPISODE';
        }
    }

    /** An episode's number read: the first, or the last of a file of two. */
    private function episodes(int $n): void
    {
        if ($this->episode === null) {
            $this->episode = $n;
        } elseif ($n > $this->episode) {
            $this->endEpisode = $n - $this->episode + 1 > 2 ? null : $n;
        }
    }

    private function source(string $token): void
    {
        if (preg_match('/' . self::SOURCE . '/i', $token) === 1) {
            [$this->lastType, $this->continue, $this->stopName] = ['source', false, true];
        } elseif (strtoupper($token) === 'WEBDL') {
            $this->continue = false;
        } elseif (preg_match('/' . self::EFFECT . '/i', $token) === 1) {
            [$this->lastType, $this->continue, $this->stopName] = ['effect', false, true];
        }
    }

    private function videoEncode(string $token): void
    {
        $upper = strtoupper($token);
        if (preg_match(self::VIDEO_ENCODE, $token) === 1 || $upper === 'H' || $upper === 'X') {
            [$this->continue, $this->stopName, $this->lastType] = [false, true, 'videoencode'];
        } elseif ($upper === '10BIT') {
            $this->lastType = 'videoencode';
        }
    }

    private function audioEncode(string $token): void
    {
        if (preg_match(self::AUDIO_ENCODE, $token) === 1) {
            [$this->continue, $this->stopName, $this->lastType] = [false, true, 'audioencode'];
        }
    }

    private function part(string $token): void
    {
        if (($this->year === null && $this->season === null && $this->episode === null && !$this->pix) || preg_match(self::PART, $token) !== 1) {
            return;
        }
        $next = $this->tokens[$this->at] ?? '';
        if ($next !== '' && (strlen($next) === 1 || (strlen($next) === 2 && $next[0] === '0') || in_array(strtoupper($next), ['A', 'B', 'C', 'I', 'II', 'III'], true))) {
            $this->next(); // its number: Part 2, CD 01
        }
        [$this->lastType, $this->continue] = ['part', false];
    }

    private function year(string $token): void
    {
        if (!self::digits($token) || strlen($token) !== 4 || (int)$token <= 1900 || (int)$token >= 2050) {
            return;
        }
        [$this->year, $this->lastType, $this->continue, $this->stopName] = [(int)$token, 'year', false, true];
    }

    private static function digits(string $s): bool
    {
        return preg_match('/^\d+$/', $s) === 1;
    }

    private static function int(string $s): ?int
    {
        return self::digits($s) ? (int)$s : null;
    }

    private static function chinese(string $s): bool
    {
        return preg_match(self::CHINESE, $s) === 1;
    }

    private static function match(string $re, string $s): ?string
    {
        return preg_match($re, $s, $m) === 1 ? $m[0] : null;
    }

    /** A number in digits or in Chinese (三, 十二, 二十, 一百零五), as agc_player's FormatUtil.convertChineseToInt; 1 for what is neither. */
    public static function chineseNumber(string $s): int
    {
        if (self::digits($s)) {
            return (int)$s;
        }
        $digits = ['零' => 0, '一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];
        [$total, $digit] = [0, 0];
        foreach (mb_str_split($s) as $ch) {
            if (isset($digits[$ch])) {
                $digit = $digits[$ch];
            } elseif ($ch === '十' || $ch === '百') {
                $total += ($digit === 0 ? 1 : $digit) * ($ch === '十' ? 10 : 100);
                $digit = 0;
            } else {
                return 1;
            }
        }
        $total += $digit;
        return $total > 0 ? $total : 1;
    }
}
