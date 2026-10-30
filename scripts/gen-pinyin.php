<?php
// Writes src/Native/Build/pinyin.bin, the Mandarin readings that slugs
// give Chinese characters (Native\Build\Slug), from the kMandarin field of
// Unicode's Unihan database (mygo's gen_pinyin.go), with ext-zip and
// ext-intl: php scripts/gen-pinyin.php [Unihan.zip]; without the file, it
// is downloaded. Its first line is the readings without tones, separated
// by spaces; then, for each code point of CJK Unified Ideographs (U+4E00
// to U+9FFF), its reading's position on that line from 1, as a big-endian
// uint16, or 0 without a reading.
declare(strict_types=1);

const UNIHAN = 'https://www.unicode.org/Public/16.0.0/ucd/Unihan.zip';
const FIRST = 0x4E00, END = 0xA000;

$fail = function (string $msg): never { fwrite(STDERR, "gen-pinyin: $msg\n"); exit(1); };
$zipFile = $argv[1] ?? null;
if ($zipFile === null) {
    $zipFile = tempnam(sys_get_temp_dir(), 'unihan');
    $data = @file_get_contents(UNIHAN);
    if ($data === false) { $fail('downloading ' . UNIHAN . ' failed'); }
    file_put_contents($zipFile, $data);
}
$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) { $fail("$zipFile is not a zip"); }
$text = $zip->getFromName('Unihan_Readings.txt');
if ($text === false) { $fail("$zipFile has no Unihan_Readings.txt"); }

$readings = array_fill(0, END - FIRST, '');
foreach (explode("\n", $text) as $line) {
    $field = explode("\t", rtrim($line, "\r"));
    if (count($field) !== 3 || $field[1] !== 'kMandarin') { continue; }
    $r = hexdec(substr($field[0], 2));
    if ($r < FIRST || $r >= END) { continue; }
    // The first reading is the most common one, without its tone: the marks above its letters, as of ǚ.
    $first = preg_split('/\s+/', trim($field[2]))[0];
    $reading = preg_replace('/\p{Mn}/u', '', Normalizer::normalize($first, Normalizer::FORM_D));
    if (trim($reading, 'abcdefghijklmnopqrstuvwxyz') !== '') { $fail("$field[0] reads \"$reading\""); }
    $readings[$r - FIRST] = $reading;
}

$syllables = array_values(array_unique(array_filter($readings, fn(string $r): bool => $r !== '')));
sort($syllables, SORT_STRING);
$at = array_flip($syllables);
$out = implode(' ', $syllables) . "\n";
foreach ($readings as $r) {
    $out .= pack('n', $r === '' ? 0 : $at[$r] + 1);
}
file_put_contents(dirname(__DIR__) . '/src/Native/Build/pinyin.bin', $out) !== false || $fail('writing pinyin.bin failed');
echo 'wrote src/Native/Build/pinyin.bin: ' . count($syllables) . " readings\n";
