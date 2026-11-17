# Video player

`VideoPlayer($player, $options)` displays a full hardware-decoded media player and its controls (or a custom video surface), taking the room it is given, as SwiftUI's `VideoPlayer`:

<!-- no-test -->
```php
use MyPHP\UI\MediaPlayerState;

$app = new class {
    public MediaPlayerState $player;
    public function __construct() {
        $this->player = new MediaPlayerState();
        $this->player->open('/path/to/movie.mp4');
    }
};
$view = fn() => VideoPlayer($app->player)->frame(minWidth: 480, minHeight: 270);
```

`MediaPlayerState` supports local files and HTTP/HLS streams, hardware video decoding (VideoToolbox on macOS/iOS, MediaCodec on Android, FFmpeg/VAAPI on Linux/Windows), 10-bit HDR and tone mapping, full `libass`-compatible ASS/SSA styled and animated subtitles rendered under the Liquid Glass control bar, audio/subtitle track selection, playback speed, aspect ratio, crop, rotation, video/audio filters, Picture-in-Picture, and Now Playing media keys. See [`examples/video`](../../examples/video) for the complete IINA-class player application.
