# GPU rendering pipeline

MyPHP renders declarative views directly on the GPU across all 5 platforms:
- **macOS & iOS / iPadOS**: Metal (`MTKView`, `CAMetalLayer`) + CoreText
- **Windows**: Direct3D 11 + DirectWrite (`IDWriteTextLayout`)
- **Linux**: OpenGL 3.3 Core (`GtkGLArea`) + Pango / Cairo
- **Android**: OpenGL ES 3.0 (`EGLSurface`) + Android Canvas glyph rasterizer

One Uber-Shader computes rounded rectangles, per-side borders, linear/radial/conic gradients, Oklab perceptual interpolation, and Gaussian box shadows analytically from signed distance fields (SDF), so every control stays razor-sharp at any DPI scale or window zoom. Text is shaped with the platform's native typography engine and packed into shelf-evicting R8/RGBA glyph atlases.

Every frame packs all UI primitives into a single contiguous GPU buffer (**16 floats / 64 bytes per quad**) and submits them in a single instanced draw call in painter's order. Backdrop materials (`glassEffect`, `Blur`, `ScrollEdge`) split the pass to downsample and blur what lies beneath them into a backdrop texture before resuming.

## Frame pacing and diagnostics

MyPHP draws a frame only on demand: when an input event arrives, when a reactive `State` or watched property mutates, or when an active `Animation` or `TimelineView` requests the next display tick. An idle window consumes **0% CPU and 0% GPU**.

Set `MYPHP_FRAME_STATS=1` to log any frame that takes longer than 8 ms (or `MYPHP_FRAME_STATS=all` for every frame), detailing build time, layout time, quad recording, GPU presentation, shaped text count, and GC activity. On macOS it also logs a frame that came more than 100 ms after it was asked for, with how it was to come (at a tick of the display link, at the next turn of the run loop), and a window whose frames are held because none of it shows: stops that no frame's own times show. Set `MYPHP_STATS=1` for a once-per-second summary.
