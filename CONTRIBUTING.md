# Contributing to laravel-pbac

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md).

## Reporting issues

- **Bugs**: a [Bug report](https://github.com/kirchDev/laravel-pbac/issues/new?template=bug_report.yml), ideally with a failing Pest test.
- **Features**: a [Feature request](https://github.com/kirchDev/laravel-pbac/issues/new?template=feature_request.yml).
- **Questions, ideas, maybe-bugs**: the [Discord forum](https://discord.kirch.dev/).
- **Security vulnerabilities**: never a public issue — see [SECURITY.md](SECURITY.md).

## Development setup

PHP 8.4+, Composer 2, Node 24+ and pnpm 12 — pinned in `composer.json`, `.nvmrc` and `package.json`.

```bash
git clone https://github.com/kirchDev/laravel-pbac.git
cd laravel-pbac
composer install
pnpm install   # wires the husky hooks
```

## Checks

`composer test`, `composer pint` (`pint:fix`), `composer larastan` and `pnpm check` (`check:fix`). CI runs the same; keep them green. Husky runs lint-staged on every commit — don't `--no-verify` unless the maintainer asks.

## Branches & pull requests

1. Branch off `dev` and target `dev`; `main` only receives promotions.
2. Conventional Commits, enforced by commitlint. Breaking changes: `feat!:` or a `BREAKING CHANGE:` footer.
3. One concern per PR.
4. Code changes need tests: a regression test for a fix, the happy path plus an edge case for a feature.
5. Changing a default means updating the README, the config docblock and, where it applies, the migration.

## Agent instructions

Agent guidance lives in [AGENTS.md](AGENTS.md). Claude Code 2.1.277+ reads it directly, but only while no `CLAUDE.md` or `CLAUDE.local.md` sits in the working directory or above it — don't commit one.

## Releases

release-please on `main` turns `feat:`/`fix:` commits into a release PR; merging it tags the release, which Packagist picks up.

## License

Contributions are licensed under the [MIT License](LICENSE).
