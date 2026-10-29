# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - unreleased

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
