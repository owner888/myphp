# OpenSubtitles for myphp

myphp's official OpenSubtitles plugin, a port to PHP of IINA's
[plugin-opensub](https://github.com/iina/plugin-opensub): a sidebar that searches
opensubtitles.com (languages, type, machine and AI translations, trusted sources, hearing
impaired), logs in (the token kept in the Keychain where the settings say), and downloads and
loads subtitles; a provider of Find Online Subtitles, which opens the sidebar.

## Install

In myphp, Settings > Plugins > Get Plugins…, choose Online Subtitles; or enter
`owner888/myphp-plugin-opensub`.

## Languages

English, Simplified and Traditional Chinese, and Japanese (`locales/`): it speaks the language the app shows. A translation to another language is a `locales/<tag>.json` of the same keys as `en.json`.
