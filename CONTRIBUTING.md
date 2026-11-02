# Contributing

Bug reports and pull requests are welcome at
[github.com/apiauto-space/apiauto-php](https://github.com/apiauto-space/apiauto-php/issues).
For questions about the API itself, data or plans, write to
[@apiautospace_bot](https://t.me/apiautospace_bot).

## Development

```bash
git clone https://github.com/apiauto-space/apiauto-php
cd apiauto-php
composer install
```

Before sending a pull request, run the same checks as CI:

```bash
composer validate --strict --no-check-lock
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

Unit tests use a fake transport and never touch the network. `tests/LiveTest.php` runs against the
real API only when `APIAUTO_KEY` is set and spends at most 10 records.

Keep the client thin: it mirrors the API and adds no logic of its own beyond pagination, retries
and error mapping. Add a test for every change and a line to `CHANGELOG.md`.
