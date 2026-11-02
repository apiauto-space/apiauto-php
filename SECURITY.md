# Security policy

## Reporting a vulnerability

Please do not open a public issue for a security problem in this client or in the
apiauto.space API. Write to the support of the Telegram bot
[@apiautospace_bot](https://t.me/apiautospace_bot) instead and describe what you found and how
to reproduce it. If your API key leaked, report it the same way so that it can be replaced.

## Supported versions

Security fixes go into the latest release. PHP 8.2 or newer is required: hiding the key in stack
traces relies on `#[\SensitiveParameter]`, which PHP 8.1 ignores.

## What the tests check

- The key does not appear in `var_dump()`, `print_r()`, `var_export()`, `json_encode()` or an
  `(array)` cast of the client, or in the same dumps of a connection error and its stack trace, with
  `zend.exception_ignore_args=0`. `serialize()` of a client is refused. A bad key is refused without
  showing it.
- The default transport does not follow 301, 302, 303, 307 or 308 redirects, so the key does not
  reach another host.
- A plain `http` base URL is refused except for localhost; a base URL with a user, query or fragment
  is refused.
- An answer above 64 MiB is cut while reading, compressed answers included, without using that much
  memory.
- `Retry-After` is read as seconds or an RFC 7231 date and kept between 0 and 60. Iteration stops on a
  repeated cursor and on a page without records.
- The error code, detail and reset time have no control characters and at most 500 characters.

## What the tests do not check

- TLS: the default transport sets `CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST` on, but no test
  connects to a host with a bad certificate.
- Code inside your process can still read the key through reflection, a debugger or a memory dump. A
  stack trace can show arguments of your own functions.
- Your own `TransportInterface` receives the key in its headers. It is responsible for logging them or
  following redirects, and should mark `$headers` with `#[\SensitiveParameter]`.
- `ApiautoException::$body` and all listing data are untrusted text from the API and its sources.
  Escape them before showing them in HTML.

Keep the key in the `APIAUTO_KEY` environment variable or a secret store, and call the API from your
server only: it does not accept browser requests.
