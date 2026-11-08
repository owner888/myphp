# Rendering

> This page is about the engine under the views: its builder, `$c` a
> `Context`, which an app reaches inside a `Representable(fn(Context $c) =>
> …)`, as SwiftUI's `NSViewRepresentable` ([custom widgets](custom-widgets.md)).

MyPHP draws on the GPU with Metal on macOS, and with OpenGL on Linux, in
the GtkGLArea GTK shows. One shader computes rounded rectangles, borders,
gradients and shadows from the distance to their edges, so they stay sharp
at any size and scale, and text comes from a glyph atlas that only uploads
what changes. A frame's quads go into one GPU buffer (16 floats a quad)
and draw in one instanced draw, in the order the painter recorded them; a
backdrop (glass, a blur) splits the draw where it samples what is below it.

Set `MYPHP_FRAME_STATS=1` to log each frame that takes longer than 8 ms,
or `MYPHP_FRAME_STATS=4` for another threshold in milliseconds (`all` logs
every frame). Each line tells how long building the frame (and in how many
passes), laying it out, painting and presenting it took, what the process
came to hold meanwhile, how many texts it laid out, and whether PHP's
collector ran. While the variable is unset, frames measure nothing.
`MYPHP_STATS=1` logs a line a second.

MyPHP draws a frame only when something changes: input,
`Window::invalidate()`, `$c->after()`, or an animation that moves. An idle
window draws nothing. Frames are paced by the display: on macOS by the
view's display link, never faster than the display shows them.
