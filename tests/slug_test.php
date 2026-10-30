<?php
// Slugs (mygo's TestSlugify and TestSlug): names in Chinese, Japanese and
// Korean take slugs of their readings, which they had none of before but
// "app" or what Latin they have; an app's own slug names its files, and
// is checked.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Build\Slug;

foreach ([
    'Hello, World!' => 'hello-world', '!!!' => 'app', 'MyGo 云音乐' => 'mygo-yun-yin-le', '云音乐Pro 2' => 'yun-yin-le-pro-2',
    'ラーメン' => 'ramen', 'とうきょう' => 'toukyou', 'じゃんけん' => 'janken', 'ファイル' => 'fairu', 'ウィキ' => 'wiki', 'きって' => 'kitte', 'マッチ' => 'matchi',
    '서울' => 'seoul', '한국어 사전' => 'hangugeo-sajeon', '東京タワー' => 'dong-jing-tawa',
] as $name => $want) {
    same($want, Slug::of($name), "the slug of $name");
}
same(0x3096 - 0x3041 + 1, count(preg_split('/\s+/', Slug::KANA_ROMAJI)), 'a romanization for each kana');
same(['cloud-music', 'yun-yin-le', 'app'], [Slug::ofConfig(['name' => '云音乐', 'slug' => 'cloud-music']), Slug::ofConfig(['name' => '云音乐', 'slug' => '']), Slug::ofConfig(['name' => '…'])], 'an app\'s slug, else its name\'s');
foreach (['my-app' => true, 'app2' => true, 'My-App' => false, '-app' => false, 'app-' => false, 'my--app' => false, 'my_app' => false, '云音乐' => false] as $slug => $ok) {
    same($ok, preg_match(Slug::PATTERN, (string)$slug) === 1, "slug \"$slug\"");
}

// myphp checks the slug of its configuration.
$dir = sys_get_temp_dir() . '/myphp-slug-' . getmypid();
@mkdir($dir);
$run = function (array $config) use ($dir): array {
    file_put_contents("$dir/myphp.json", json_encode($config));
    $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', dirname(__DIR__) . '/bin/myphp', 'generate', $dir], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    $out = stream_get_contents($pipes[1]);
    return [proc_close($p), $out];
};
[$code, $out] = $run(['name' => '云音乐', 'slug' => 'Cloud_Music']);
check($code !== 0 && str_contains($out, 'myphp.json: slug "Cloud_Music" is not a slug'), 'a slug that is none: refused: ' . $out);
[$code, $out] = $run(['name' => '云音乐', 'slug' => 'cloud-music']);
same(0, $code, 'a slug: taken: ' . $out);
exec('rm -rf ' . escapeshellarg($dir));
