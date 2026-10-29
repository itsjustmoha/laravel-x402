# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-10-29

### Added

- Framework-free core (`src/Core`): exact price to atomic unit conversion, x402 v2 types
  (`PaymentRequired`, `PaymentRequirements`, `PaymentPayload`, `SettlementResponse`), base64 JSON
  header codec, version negotiation (v2, optional v1), facilitator client for `/verify`, `/settle`
  and `/supported` behind an `HttpTransport` interface (curl implementation and test fake), and
  the `PaymentGate` decision logic (challenge, verify, settle, abandon).
- Per-payment cache lock against concurrent use of one signed payment.
- Bazaar discovery extension builder and service metadata validation.
- Laravel integration: service provider, publishable `config/x402.php`, `x402` middleware alias,
  `Route::paid()` macro, `PaymentVerified` / `PaymentSettled` / `PaymentFailed` events, PII-free
  logging, Laravel HTTP client transport (works with `Http::fake()`), cache-backed lock.
- Built-in USDC for Base Sepolia, Arbitrum Sepolia, Base, Ethereum, Polygon, Arbitrum One and
  Avalanche; guard against using the testnet-only x402.org facilitator on mainnet.
- Pint (Laravel preset) and Larastan (level 9 for `src/`, level 8 for `tests/`) in CI, next to the
  test matrix for PHP 8.3 and 8.4 with Laravel 12 and 13.

[Unreleased]: https://github.com/itsjustmoha/laravel-x402/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/itsjustmoha/laravel-x402/releases/tag/v0.1.0
