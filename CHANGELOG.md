# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [0.1.0] — Unreleased

First release.

### Added

- `Apiauto\Client` for the apiauto.space API: Encar (Korea), Che168 (China), Copart and IAAI (USA).
- Catalog search: `cars()`, `iterCars()` with `maxItems`, `car()`.
- Change feed: `changes()` and `iterChanges()`; `since` takes a date or an ISO 8601 string.
- References and usage: `brands()`, `models()`, `dictionaries()`, `markets()`, `usage()`.
- Live US search: `usLive()`.
- An exception class per API error, under `Apiauto\Exception`.
- Retries on 429 and 503 after `Retry-After`, and on 5xx and network errors with backoff.
- Quota and rate-limit headers in `lastResponse()`.
- Readonly model classes for every response, under `Apiauto\Model`.

[0.1.0]: https://github.com/apiauto-space/apiauto-php/releases/tag/v0.1.0
