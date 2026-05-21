# Security Policy

## Supported Versions

This package follows [Semantic Versioning](https://semver.org/). Security fixes are applied to the latest minor release of each currently-supported major branch.

While the package is pre-1.0, only the **latest `0.x` release** receives security updates. Breaking changes may ship in minor versions per SemVer's `0.x` exception — please pin a tight version constraint.

|        Version         | Supported |
| :--------------------: | :-------: |
|     `0.x` (latest)     |    ✅     |
| `< 0.x` (older minors) |    ❌     |

Once `1.0.0` is released, this table will be updated to reflect the supported major lines.

## Reporting a Vulnerability

**Please do not file a public GitHub issue for security problems.**

Use one of the following private channels:

1. **GitHub Private Vulnerability Reporting** (preferred): open a private advisory at <https://github.com/kirchDev/laravel-pbac/security/advisories/new>.
2. **Email**: [titus.kirch@kirch.dev](mailto:titus.kirch@kirch.dev). PGP available on request.

Please include:

- A description of the vulnerability and its impact.
- Steps to reproduce (a minimal failing test case is ideal).
- The affected version(s).
- Any suggested fix, if you have one.

### What to expect

| Stage                        | Target timeline                                   |
| :--------------------------- | :------------------------------------------------ |
| Acknowledgement of report    | within **3 business days**                        |
| Initial assessment & triage  | within **7 business days**                        |
| Patch released (if accepted) | depends on severity — critical issues prioritised |
| Public disclosure & advisory | coordinated with reporter after the patch ships   |

If the report is **declined** (e.g. behaviour is intentional, out of scope, or a duplicate), you will receive a written explanation. You may publish your findings after we close the report.

### Scope

In scope:

- The `kirchdev/laravel-pbac` Composer package source code (`src/`, `config/`, `database/migrations/`).
- Documented APIs (`HasRoles` trait, `PbacManager`, `Gate` integration, `OrganisationResolver` contract).
- Default configuration values shipped in `config/pbac.php`.

Out of scope:

- Vulnerabilities in upstream dependencies (Laravel, Illuminate components, Pest, Larastan) — report those to their respective maintainers.
- Misconfiguration in consuming applications (e.g. permissive `gate.fallback_to_laravel_gates` settings).
- Issues that require an already-compromised host system or database.

## Credit

Reporters who follow this process responsibly are credited in the [CHANGELOG](CHANGELOG.md) and the corresponding GitHub Security Advisory, unless they prefer to remain anonymous.

---

Maintained by [Titus Kirch](https://github.com/TitusKirch/) / [IT-Dienstleistungen Titus Kirch](https://kirch.dev).
