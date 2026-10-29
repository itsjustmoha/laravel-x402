# Contributing

Thanks for helping. Issues and pull requests are welcome.

## Ground rules

- `src/Core` must stay framework-free: no `Illuminate`, Symfony, Guzzle or PSR imports.
  `tests/Core/ArchitectureTest.php` enforces this.
- PHP 8.3+, `declare(strict_types=1)`, final classes, readonly value objects, full types.
- Protocol behaviour must follow the x402 specification (https://github.com/coinbase/x402,
  `specs/`). When you deviate from the reference SDKs, say so in the README "Behaviour" section.
- Never add code that handles private keys or holds funds.

## Running the tests

```bash
composer install
vendor/bin/phpunit                         # core + Laravel feature tests
vendor/bin/phpunit -c phpunit-core.xml     # core only, also works without composer install
```

Core tests must keep working with PHPUnit 9.6 and 12: name test methods `test...`, do not use
`@test` annotations or data providers.

## Pull requests

- One topic per pull request, with tests.
- Add an entry under "Unreleased" in `CHANGELOG.md`.
- Security issues: please report privately via GitHub security advisories, not in public issues.
