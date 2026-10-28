#!/usr/bin/env php
<?php
// A yt-dlp of tests/yt_dlp_test.php and player_test.php (Media\YtDlp::$path):
// the JSON yt-dlp -J prints, of the pages of a local server, its base in
// MYPHP_YTDLP_BASE: /watch?v=… a video of a video's format and an audio's
// (clip-dash.mp4, clip-dash.m4a, read only with the header X-Myphp-Test), its
// chapters and subtitles; /list a playlist of two; anything else fails as
// yt-dlp does.
$url = end($argv);
$base = (string)getenv('MYPHP_YTDLP_BASE');
parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
$headers = ['User-Agent' => 'myphp-test', 'X-Myphp-Test' => 'yes'];
$path = (string)parse_url($url, PHP_URL_PATH);
if ($path === '/watch') {
    echo json_encode(['_type' => 'video', 'id' => $q['v'] ?? '', 'title' => 'A clip of ' . ($q['v'] ?? ''), 'chapters' => [['start_time' => 0.0, 'end_time' => 1.0, 'title' => 'One'], ['start_time' => 1.0, 'end_time' => 2.0, 'title' => 'Two']],
        'requested_formats' => [
            ['url' => "$base/clip-dash.mp4?need", 'protocol' => 'https', 'http_headers' => $headers, 'vcodec' => 'avc1', 'acodec' => 'none'],
            ['url' => "$base/clip-dash.m4a?need", 'protocol' => 'https', 'http_headers' => $headers, 'vcodec' => 'none', 'acodec' => 'mp4a'],
        ],
        'subtitles' => ['zh' => [['ext' => 'json3', 'url' => "$base/none.json3"], ['ext' => 'srt', 'url' => "$base/clip-subs.zh.srt?need", 'name' => 'Chinese', 'http_headers' => $headers]]],
        'automatic_captions' => ['en' => [['ext' => 'vtt', 'url' => "$base/auto.vtt"]]],
    ]);
    exit(0);
}
if ($path === '/list') {
    echo json_encode(['_type' => 'playlist', 'id' => 'L', 'title' => 'Two clips', 'entries' => [
        ['_type' => 'url', 'id' => 'a', 'url' => "$base/watch?v=a", 'title' => 'First'],
        ['_type' => 'url', 'id' => 'b', 'url' => "$base/watch?v=b", 'title' => 'Second'],
    ]]);
    exit(0);
}
fwrite(STDERR, "ERROR: Unsupported URL: $url\n");
exit(1);
