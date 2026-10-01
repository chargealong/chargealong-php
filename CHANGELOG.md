# Changelog

## 0.1.0

First release.

- `nearby`, `planTrip`, `search`, `site`, `vehicles` and `vehicle`, plus the
  directory: `countries`, `overview`, `country`, `region`, `networks`,
  `network`, `locality` and `redirect`. The whole read surface of
  api.chargealong.io.
- Errors keep the service's own words: `NotFound`, `BadRequest`, `NoRoute` for
  a trip with no road, and `RateLimited`. A removed site answers with its status
  set to `removed`.
- `Paths::pageUrl` turns any row's path into its page on chargealong.io, with
  builders for the pages rows only carry slugs for.
- Options are checked against the service's bounds before a request goes out,
  so NAN and out of range figures never reach a URL.
- PHP 8.1 or later, no dependencies. cURL by default, or a transport of your
  own in a few lines.
