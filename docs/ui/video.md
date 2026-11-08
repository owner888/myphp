# Video

`VideoPlayer($player)` shows a film and its controls, taking the room it
is given, as SwiftUI's; `$player` a `MediaPlayerState` the app keeps, which
opens, plays and seeks.

<!-- no-test -->
```php
$view = fn() => VideoPlayer($this->player)->frame(minWidth: 480, minHeight: 270);
```

> The rest of this page is about the engine under the views: its builder,
> `$c` a `Context`, which an app reaches inside a `Representable(fn(Context
> $c) => …)` ([custom widgets](custom-widgets.md)).

`$c->video()` shows a video that plays: a short clip looping behind the
content, as a launcher's library or a music player's hero shows one, or a
preview in a grid. It shows a `VideoPlayer`, which plays the frames a
decoder gives it, and is laid out, fitted and rounded as an
[Image](image.md) is.

<!-- no-test -->
```php
$waves = (new VideoPlayer(GifDecoder::fromFile(__DIR__ . '/waves.gif')))->setLoop(true);

// In the view:
$c->video($waves)->fill()->fit(Node::COVER);
```

For films with sound, controls and subtitles, `$c->movie()` and
`$c->mediaPlayer()` play them.

## Players

`new VideoPlayer($decoder)` decodes the first frame at once, which
`video()` shows from its first frame and which throws for a video that
does not decode, so make players once, not in the view, as images. A new
player plays as soon as a `video()` shows it. `pause()` holds it at the
frame it shows, and `play()` goes on from there, or plays it again from
its start once it ended; `playing()` tells which. `setLoop(true)` plays it
again each time it ends, without a gap. A video that ends shows its last
frame, and `err()` tells what stopped one before its end. `close()` stops
decoding and closes the decoder, when it has `close()`. Call a player's
methods on the main thread, in the view or an input handler, and show it
in one `video()` at a time.

```php
$decoder = new class implements VideoDecoder {
    private int $next = 0;
    public function next(): ?VideoFrame
    {
        if ($this->next === 30) { return null; }
        $shade = chr(8 * $this->next++);
        return new VideoFrame(4, 4, str_repeat($shade . "\x40\x80\xff", 16), 1 / 30);
    }
    public function rewind(): void { $this->next = 0; }
};
$waves = (new VideoPlayer($decoder))->setLoop(true);
$view = function (Context $c) use ($waves) {
    $c->video($waves, 160, 90);
    $label = $waves->playing() ? 'Pause' : 'Play';
    if ($c->clicked($c->key('play')->button($label))) {
        $waves->playing() ? $waves->pause() : $waves->play();
    }
};
```

Videos have no sound.

## Playing

A player decodes its frames as the playhead reaches them, and each frame
reaches the screen through its [image's `update()`](image.md#bitmaps) as it
comes due: the window draws one frame for each of the video's, which
`after()` asks for, and none between. Frames that came due meanwhile are
passed over, so the video keeps to the clock rather than lag behind it.

A video moves on only while the view builds it and some of the window
shows. One that went out of the view, as on another tab, or in a window
that is minimized, hidden or covered by other windows, goes on from where
it stood once it shows again, and decodes nothing meanwhile. While the
desktop asks for less motion (`Preferences::$reduceMotion`), a video shows
its first frame and does not play; it goes on from where it stood once the
desktop no longer asks.

## Decoders

`GifDecoder` decodes animated GIFs as browsers show them, through the
system's ImageIO (macOS): each frame drawn over what the ones before it
left, as its disposal says, and a frame of 10 ms or less shown for 100 ms.
It plays once or loops as `setLoop()` says, whatever loop count the GIF
gives.

A `VideoDecoder` of your own plays another format, as above: `next()`
returns the next `VideoFrame` (its size, its premultiplied RGBA pixels and
how long it shows, in seconds), or null after the last, and `rewind()` goes
back to before the first.

## Accessibility

Videos are decorations that assistive technology does not see, unless
`label()` names them, as images are.
