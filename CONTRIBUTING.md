# Contributing

## Development workflow

This repository uses a Docker-first workflow. Run all local commands through `Makefile`.

Build the local runtime image:

```bash
make build
```

Refresh autoload files:

```bash
make composer ARGS="dump-autoload"
```

Run quality checks:

```bash
make cs
make stan
make test
```

Run integration tests:

```bash
make integration-up
make test-integration
make integration-down
```

## Coding standards

- PSR-12 via PHP_CodeSniffer
- PHPStan for static analysis
- PHPUnit for unit and integration coverage
- Prefer explicit, deterministic behavior over implicit magic

## Pull requests

- Keep changes focused and reviewable.
- Add or update tests when behavior changes.
- Update documentation when public behavior or limitations change.
- Make sure `make build`, `make cs`, `make stan`, `make test`, and `make test-integration` pass before opening a PR.
