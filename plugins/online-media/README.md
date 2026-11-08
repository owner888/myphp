# Online Media for myphp

myphp's official plugin for playing online media via yt-dlp / youtube-dl, a port to PHP of IINA's
[plugin-online-media](https://github.com/iina/plugin-online-media). With it on, the player's own
yt-dlp support is off: pages are read through the plugin's hooks.

- Pages read by yt-dlp as they open (after the player could not open them, or first where the
  preferences say), their video, audio, title, subtitles and chapters given to the player.
- The formats of a video in the Plugin menu; Download this video.
- Manage yt-dlp and Downloads…: the yt-dlp used, downloaded or updated into the plugin's data
  folder; Deno downloaded for YouTube; the videos downloaded into ~/Downloads.

## Install

In myphp, Settings > Plugins > Get Plugins…, choose Online Media; or enter
`owner888/myphp-plugin-online-media`.

## Languages

English, Simplified and Traditional Chinese, and Japanese (`locales/`): it speaks the language the app shows. A translation to another language is a `locales/<tag>.json` of the same keys as `en.json`.
