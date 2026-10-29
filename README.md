# laravel-x402

Charge AI agents (and any other HTTP client) per request from a Laravel API using the
[x402 protocol](https://github.com/coinbase/x402): HTTP `402 Payment Required` plus
stablecoin payments that a third-party **facilitator** verifies and settles on a chain.

> **Status: 0.1.0, early.** The core payment logic and the Laravel integration are tested here, but
> no real payment has gone through it yet. Read [Verification status](#verification-status) before
> you rely on it, and start on testnet.

> The package is published as `itsjustmoha/laravel-x402` (namespace `Itsjustmoha\X402`).
> If you fork it, change the vendor name in `composer.json` and the namespace with a
> search and replace; nothing else depends on it.

## What is x402?

x402 is an open protocol that uses the HTTP `402 Payment Required` status code to tell a
client the price of a resource in machine-readable form. The client (often an AI agent with
a wallet) signs a payment authorization, for example an EIP-3009 USDC transfer, and retries the
request with that authorization in a header. The server asks a facilitator to verify the
authorization, serves the response, and then has the facilitator settle the transfer on chain,
straight from the payer to your wallet.

This package is **noncustodial**. It never sees private keys and never holds funds; it only
forwards signed authorizations to the facilitator. Money moves from the payer to your `pay_to`
address.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- A cache store with atomic locks (redis, database, memcached, dynamodb, file, or array)
- The **public** address of a wallet you control (no private key needed)

## Installation

```bash
composer require itsjustmoha/laravel-x402
php artisan vendor:publish --tag=x402-config
```

`.env`:

```dotenv
X402_PAY_TO=0xYourPublicWalletAddress
# Defaults (testnet first):
# X402_NETWORK=eip155:84532                          # Base Sepolia
# X402_FACILITATOR_URL=https://x402.org/facilitator  # public testnet facilitator
```

## Protect a route in three lines

```php
use Illuminate\Support\Facades\Route;

Route::get('/api/report', ReportController::class)->paid('$0.01');
```

That's it. Calls without a payment get a `402` with the payment requirements; calls with a
valid payment run your controller and are charged 0.01 USDC.

Alternatives and options:

```php
// Middleware alias with parameters: x402:<price>[,<network>]
Route::post('/api/summarize', SummarizeController::class)->middleware('x402:0.05');

// Per-route options
Route::get('/api/weather', WeatherController::class)->paid('$0.001', [
    'description' => 'Current weather for a city',
    'mime_type' => 'application/json',       // default: application/json
    'network' => 'eip155:84532',             // default: config('x402.network')
    'pay_to' => '0x...',                     // default: config('x402.pay_to')
    'max_timeout_seconds' => 60,             // default: config('x402.max_timeout_seconds')
    'resource' => 'https://api.example.com/api/weather', // default: the request URL
    'discovery' => [/* see "Bazaar discovery" */],
]);
```

Prices are strings: `"$0.01"`, `"0.01"` and `"1"` all work. They are converted to atomic units
exactly (string arithmetic, no floats). A price with more decimals than the token supports
(USDC: 6) is rejected with an exception instead of being rounded.

## Testnet quickstart

1. Create a wallet (any EVM wallet) and copy its **public** address into `X402_PAY_TO`.
2. Keep the defaults: network `eip155:84532` (Base Sepolia) and facilitator
   `https://x402.org/facilitator` (the public testnet facilitator documented by the x402 project).
3. Protect a route with `->paid('$0.01')` and run `php artisan serve`.
4. `curl -i http://127.0.0.1:8000/api/report` returns `402` with a `PAYMENT-REQUIRED` header.
5. Fund a **separate test buyer wallet** with Base Sepolia USDC from
   <https://faucet.circle.com/> and pay with an x402 client (next section).
6. The paid response carries a `PAYMENT-RESPONSE` header with the transaction hash; look it up on
   <https://sepolia.basescan.org>.

### Going to mainnet

- Set `X402_NETWORK=eip155:8453` (Base mainnet USDC is built in).
- Set `X402_FACILITATOR_URL` to a production facilitator, for example Coinbase CDP
  (`https://api.cdp.coinbase.com/platform/v2/x402`, needs API keys, see below) or another
  provider from the [x402 facilitator list](https://github.com/coinbase/x402/blob/main/docs/dev-tools/facilitators.md).
  The package refuses to combine a mainnet network with the testnet-only `x402.org` facilitator.
- Use a shared cache store for `X402_LOCK_STORE` if you run more than one server.

### Facilitators that need credentials

Implement `AuthHeadersProvider` and name the class in `X402_FACILITATOR_AUTH`. It is called for
every facilitator request, so it can mint short-lived tokens:

```php
use Itsjustmoha\X402\Core\Facilitator\AuthHeadersProvider;
use Itsjustmoha\X402\Core\Http\HttpRequest;

final class CdpAuthHeaders implements AuthHeadersProvider
{
    public function headersFor(string $operation, HttpRequest $request): array
    {
        // $operation is "verify", "settle" or "supported"; $request has method + URL.
        return ['Authorization' => 'Bearer ' . $this->jwtFor($request->method, $request->url)];
    }
}
```

Coinbase CDP expects a per-request JWT signed with your CDP API key. Generating that JWT is
not included in this version (see [Open decisions](#roadmap--open-decisions)).

## How an agent pays

Any x402 v2 client works. With the official TypeScript client (`@x402/fetch`, `@x402/evm`):

```ts
import { x402Client, wrapFetchWithPayment } from "@x402/fetch";
import { ExactEvmScheme } from "@x402/evm/exact/client";
import { privateKeyToAccount } from "viem/accounts";

const client = new x402Client();
client.register("eip155:*", new ExactEvmScheme(privateKeyToAccount(process.env.BUYER_KEY as `0x${string}`)));

const fetchWithPayment = wrapFetchWithPayment(fetch, client);
const response = await fetchWithPayment("http://127.0.0.1:8000/api/report");
```

The buyer key belongs to the **agent**, never to your Laravel app. On the wire:

```text
GET /api/report                          -> 402, PAYMENT-REQUIRED: base64(PaymentRequired)
GET /api/report + PAYMENT-SIGNATURE: ... -> server: POST {facilitator}/verify
                                            your controller runs
                                            server: POST {facilitator}/settle
                                         <- 200 + PAYMENT-RESPONSE: base64(SettlementResponse)
```

If browsers call your API directly, expose the headers in `config/cors.php`:
`'exposed_headers' => ['PAYMENT-REQUIRED', 'PAYMENT-RESPONSE']`.

## Behaviour

The middleware follows the default `authorization` payment flow of the specification
(verify, then run the resource, then settle) and mirrors the official Express/FastAPI middleware.

| Situation | Response | Facilitator calls | Handler runs | Charged |
|---|---|---|---|---|
| No payment header | `402`, `PAYMENT-REQUIRED` header, same object as JSON body, `Cache-Control: no-store` | none | no | no |
| Header is not base64 JSON / wrong shape | `402`, error `invalid_payload` | none | no | no |
| Payment for other terms (amount, payTo, asset, network, ...) | `402`, error `No matching payment requirements` | none | no | no |
| Same signed payment already in flight or just settled | `402`, error `duplicate_payment` | none | no | no |
| Facilitator says invalid | `402`, error = facilitator `invalidReason` (e.g. `insufficient_funds`) | verify | no | no |
| Facilitator unreachable, timeout, 5xx or malformed during verify | `502 {"error":"facilitator_unavailable"}` | verify | no | no |
| Valid payment, handler returns 2xx | handler response + `PAYMENT-RESPONSE`, `Cache-Control: private` | verify, settle | yes | **yes** |
| Valid payment, handler returns non-2xx or throws | handler response / exception unchanged | verify | yes | no |
| Settlement rejected by facilitator | `402`, `PAYMENT-RESPONSE` with `success:false`, handler output discarded | verify, settle | yes | no |
| `settlement_pending` | settle is retried once (like the reference server); if still pending: `402` with the pending `PAYMENT-RESPONSE` | verify, settle x2 | yes | maybe, check the tx hash |
| Facilitator unreachable during settle | `502 {"error":"facilitator_unavailable"}`, handler output discarded | verify, settle | yes | unknown, the payment stays locked |

Differences from the reference middleware, on purpose:

- **Only 2xx responses are settled.** The reference settles anything below 400, so redirects
  would be charged; here they are not.
- **All facilitator failures are 502.** The reference answers 502 for timeouts and malformed
  responses but 402 for other facilitator HTTP errors. A 402 would tell the agent its payment was
  bad when the problem is upstream, so this package always answers 502 and logs the details.
- **Malformed payment headers get a 402 with `invalid_payload`** instead of being treated as
  "no payment", so clients learn why they were rejected.
- **Concurrent use of one payment is blocked with a cache lock** (see Security).
- **The 402 body repeats the PaymentRequired object** (the reference sends `{}`), which helps
  agents that only read bodies.

Streamed responses (`StreamedResponse`, file downloads) are settled before the body is streamed.

## Events and logging

| Event | When | Useful fields |
|---|---|---|
| `Itsjustmoha\X402\Laravel\Events\PaymentVerified` | facilitator accepted the payment; handler runs next | `requirements`, `payer`, `version` |
| `Itsjustmoha\X402\Laravel\Events\PaymentSettled` | money moved | `settlement->transaction`, `settlement->network`, `requirements->amount` |
| `Itsjustmoha\X402\Laravel\Events\PaymentFailed` | a presented payment did not unlock the resource | `stage` (`verify`/`settle`), `reason`, `status`, `payer` |

```php
Event::listen(PaymentSettled::class, function (PaymentSettled $event) {
    Revenue::record($event->requirements->amount, $event->settlement->transaction);
});
```

Exceptions thrown by `PaymentSettled` listeners are reported but do not replace the paid
response (the payer has already been charged). Put slow work in queued listeners (`ShouldQueue`).

Logs go to `X402_LOG_CHANNEL` (default channel if empty; `X402_LOGGING=false` turns them off). They
contain path, network, amount, asset, reason and transaction hash. They never contain payer
addresses, payment headers, or signatures. The events do carry the payer address, so if you persist
it, treat it as personal data.

## Bazaar discovery metadata

Facilitators that implement the `bazaar` extension can list your endpoint in a discovery catalog
so agents find it. Add a `discovery` option; the package builds the `extensions.bazaar` object
(`info` + JSON Schema) like the reference `declareDiscoveryExtension()` helper:

```php
Route::get('/api/weather', WeatherController::class)->paid('$0.001', [
    'description' => 'Current weather for a city',
    'discovery' => [
        'input' => ['city' => 'Berlin'],                       // example query params (or body for POST/PUT/PATCH)
        'input_schema' => ['properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        'output' => ['city' => 'Berlin', 'temperature' => 12], // example response
        // 'body_type' => 'json',                              // POST/PUT/PATCH only: json | form-data | text
    ],
]);
```

For routes with parameters (`/users/{user}`), `routeTemplate` (`/users/:user`) and `pathParams`
are added automatically. Service-level metadata comes from `config('x402.service')`
(`name`, `tags`, `icon_url`); values that break the specification's rules (non-ASCII or over 32
characters, more than 5 tags, IP or localhost icon URLs) are dropped silently, as the spec requires.

Use `(object) []` for empty JSON objects inside your own schemas.

## Protocol versions

| | x402 v2 (default) | x402 v1 (opt-in: `X402_V1_COMPATIBILITY=true`) |
|---|---|---|
| Requirements | `PAYMENT-REQUIRED` header (base64) + JSON body | JSON body `{x402Version:1, accepts:[...]}`; the v2 header is still sent |
| Client payment header | `PAYMENT-SIGNATURE` | `X-PAYMENT` |
| Settlement header | `PAYMENT-RESPONSE` | `X-PAYMENT-RESPONSE` |
| Networks | CAIP-2 (`eip155:84532`) | legacy names (`base-sepolia`) |
| Amount field | `amount` | `maxAmountRequired` |
| Matching | full requirements must match (`extra` may only be extended) | scheme + network |
| Discovery | `extensions.bazaar` | `outputSchema` (best effort) |

The version is taken from the payload's `x402Version`; `PAYMENT-SIGNATURE` wins if both headers
are present. With v1 compatibility off (the default, like the official v2 servers), v1 payments get
`402 invalid_x402_version`. Turning it on changes the 402 JSON body to the v1 format; v2 clients
are unaffected because they read the header first.

Supported scheme: `exact` on EVM networks with EIP-3009 tokens. Built-in USDC: Base Sepolia
`eip155:84532`, Arbitrum Sepolia `eip155:421614`, Base `eip155:8453`, Ethereum `eip155:1`,
Polygon `eip155:137`, Arbitrum One `eip155:42161`, Avalanche `eip155:43114` (addresses and EIP-712
domains from `typescript/packages/mechanisms/evm/src/defaultAssets.ts`). Add more under
`x402.assets`. Not supported yet: Solana/SVM, Permit2 tokens, the `upto` and `batch-settlement`
schemes, the paywall HTML page.

## Security

- **Testnet first.** The defaults are Base Sepolia and the public testnet facilitator. Move to
  mainnet only after you have seen settlements on testnet.
- **Never put private keys in your app.** `pay_to` is a public address. The package has no code
  path that signs anything. Keep the receiving wallet's keys in a hardware wallet or custody service.
- **Trust model.** You trust the facilitator to tell the truth about `/verify` and `/settle`
  and to be available. It cannot redirect funds: the payer's signature fixes amount, token and
  recipient, and the token contract enforces them. A dishonest facilitator could, however, claim
  a payment is valid when it is not, and you would serve the resource for free. Use a facilitator
  you trust, and reconcile `PaymentSettled` transaction hashes against the chain for revenue you
  care about. Auth credentials are only sent to the configured facilitator origin; cross-origin
  redirects are refused.
- **Replay protection** lives in the EIP-3009 nonce: the token contract accepts each signed
  authorization once, and the facilitator checks the nonce, signature, amount, and validity window.
  On top of that the package holds a cache lock per signed payment from verification until the
  authorization window (`maxTimeoutSeconds` + 60 s) has passed, so one payment cannot run your
  handler twice in parallel. This lock is an optimisation, not the security boundary; use a store
  shared by all servers.
- **Payments are matched exactly.** A payment whose `accepted` terms differ in any field
  (amount, asset, payTo, network, timeout) is rejected before the facilitator is called.
- **Logging without PII.** Logs contain no payer addresses, headers, or signatures.
- **Rate limiting.** Unpaid 402 responses are cheap but not free; put Laravel's `throttle`
  middleware in front of paid routes.

## FAQ

**Do I need a crypto wallet for the server?** Only a public address to receive funds.

**Can I charge in EUR?** Not yet. Prices are USD stablecoin amounts.

**What happens if my controller fails?** Nothing is charged; the client can retry with the same
signed payment.

**Can I use different prices per route?** Yes, every `->paid()` call has its own price.

**Can I use this without Laravel?** `src/Core` has no framework dependencies: build a
`PaymentRequired` with `RequirementsBuilder`, call `PaymentGate::evaluate()`, run your handler,
then `PaymentGate::settle()`. Use `CurlHttpTransport` and your own `PaymentLock`.

**How do I test my app?** Use `Http::fake()` for the facilitator URL
(`/verify` and `/settle`), or bind `Core\Testing\FakeHttpTransport` to `HttpTransport` and
`Core\Testing\InMemoryPaymentLock` to `PaymentLock`.

**Clients get a 502 from nginx on the 402 response.** The `PAYMENT-REQUIRED` header grows with
large discovery schemas. Raise `fastcgi_buffer_size` / `proxy_buffer_size` (e.g. `16k`) or keep
`discovery` schemas small.

**Is there something similar already?** Yes, see [Related projects](#related-projects).

## Verification status

What has been verified for 0.1.0:

- `composer install` resolves on PHP 8.4.27 with Laravel 13.39.0, Testbench 11.3.0 and
  PHPUnit 12.5.42.
- Full suite (`vendor/bin/phpunit`): 97 tests, 688 assertions, all passing. That includes the
  Testbench feature tests in `tests/Feature` (middleware, `->paid()` routes, cache lock, events,
  `Http::fake()` facilitator round trips) and the core suite below.
- Core suite (`phpunit -c phpunit-core.xml`, no framework autoloading): 76 tests, 566 assertions,
  all passing. It covers price conversion, base64/JSON codec, v1/v2 payload parsing, requirement
  matching, the facilitator client (request shape, error policy, redirects, auth headers), the full
  payment gate (challenge, verify, settle, settlement_pending retry, locks, v1 compatibility),
  Bazaar and service metadata rules, and real HTTP round trips through `CurlHttpTransport` against
  a local `php -S` stand-in facilitator. It also ran on PHPUnit 9.6 / PHP 8.3.6 without Composer.
- Our `PAYMENT-REQUIRED` and `PAYMENT-RESPONSE` encodings are byte-identical to the examples in
  `specs/transports-v2/http.md`, and the spec's `PAYMENT-SIGNATURE` and v1 `X-PAYMENT` examples
  are parsed correctly.
- Pint (Laravel preset): clean.
- Larastan / PHPStan (`phpstan.neon.dist`, level 9, `src/`): no errors.

CI is set up to run Pint and Larastan, plus both test suites on PHP 8.3 and 8.4 with highest and lowest
dependencies (`.github/workflows/tests.yml`).

Not verified yet:

- Laravel 12 / Testbench 10, PHP 8.3 with Composer dependencies, and the lowest dependency set
  have not been run. The CI matrix covers them, but CI has not run yet.
- No real payment: nothing was sent to `x402.org/facilitator` or any other facilitator, and no
  testnet transaction was made.
- No real x402 client (`@x402/fetch`, Python `x402`) has talked to this package.
- Coinbase CDP authentication.
- `tests/` is not covered by Larastan (level 9 reports issues around Testbench's nullable `$app`).

## Specification sources

Implemented against [coinbase/x402](https://github.com/coinbase/x402) at commit
`0e6e8c55d86b4f14234fb6e49a4052074fb93e57` (2026-10-15), protocol version 2 (SDK 2.28.0):

- `specs/x402-specification-v2.md`: types, facilitator API (`/verify`, `/settle`, `/supported`),
  payment flows, error codes, `settlement_pending`
- `specs/transports-v2/http.md`: `PAYMENT-REQUIRED` / `PAYMENT-SIGNATURE` / `PAYMENT-RESPONSE`
- `specs/schemes/exact/scheme_exact_evm.md`: `exact` on EVM, EIP-3009, `extra.name`/`extra.version`
- `specs/extensions/bazaar.md`: discovery extension, service metadata rules, `routeTemplate`
- `specs/x402-specification-v1.md`, `specs/transports-v1/http.md`: v1 compatibility
- `DEFAULT_ASSETS.md`, `typescript/packages/mechanisms/evm/src/defaultAssets.ts`: USDC addresses
  and EIP-712 domains
- `typescript/packages/mechanisms/evm/src/constants.ts`: v1 network names
- Reference behaviour: `typescript/packages/core/src/http/x402HTTPResourceServer.ts`,
  `typescript/packages/core/src/server/x402ResourceServer.ts`,
  `typescript/packages/core/src/http/httpFacilitatorClient.ts`,
  `typescript/packages/http/express/src/index.ts`, `python/x402/http/x402_http_server_base.py`
- `docs/getting-started/quickstart-for-sellers.mdx`: `x402.org` facilitator is testnet only

## Related projects

Other PHP implementations existed before this package; compare before choosing:

- [sandermuller/laravel-x402](https://github.com/SanderMuller/laravel-x402) (Laravel adapter, alpha)
  and [sandermuller/php-x402](https://github.com/SanderMuller/php-x402) (framework-agnostic PSR-15/18)
- [johnguoy/laravel-x402](https://github.com/JohnGuoy/laravel-x402) (Laravel, x402 v2, release candidate)

## Roadmap / open decisions

- Coinbase CDP JWT auth provider
- `php artisan x402:check` command (config + `/supported` check)
- Multiple networks per route (the core already supports several `accepts` entries)
- Solana (`exact` on SVM), Permit2 tokens, `upto` scheme

## License

MIT, see [LICENSE](LICENSE).
