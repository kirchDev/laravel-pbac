# Changelog

## [0.4.0](https://github.com/kirchDev/laravel-pbac/compare/v0.3.0...v0.4.0) (2026-08-27)


### ⚠ BREAKING CHANGES

* **migrations:** the package's migration filenames changed. Consumers are unaffected — they only ever see the published names, which are generated.
* **migrations:** published migrations no longer carry the package's own filenames. A consumer who relied on the removed auto-load and never published must record the published copies as run before migrating; the README upgrade note carries the recipe.
* **migrations:** migrations are no longer auto-loaded. Consumers relying on the auto-load must run `php artisan vendor:publish --tag=pbac-migrations` once; anyone who already published is unaffected, since the filenames are unchanged.

### Features

* **migrations:** publish migrations instead of auto-loading them ([6052e68](https://github.com/kirchDev/laravel-pbac/commit/6052e68db8985cf2d8b9fe0ddcb96a197321d70a)), closes [#39](https://github.com/kirchDev/laravel-pbac/issues/39)
* **migrations:** stamp published migrations at publish time ([0fe57ca](https://github.com/kirchDev/laravel-pbac/commit/0fe57ca22a5b9cc574aabe29a2fb7f0f033733cc))


### Documentation

* **migrations:** document the publish requirement and the upgrade path ([85927ff](https://github.com/kirchDev/laravel-pbac/commit/85927ff67e0cf34942d2474620cec91e88b61fac)), closes [#39](https://github.com/kirchDev/laravel-pbac/issues/39)
* **migrations:** state the unpublished upgrade as a breaking change ([691bcb9](https://github.com/kirchDev/laravel-pbac/commit/691bcb9041e6434be0e56b2ee906f07fbc649f2f))


### Refactor

* **migrations:** move publish naming into PackageMigrations ([ed610a4](https://github.com/kirchDev/laravel-pbac/commit/ed610a47d812d9bc43d77251b0268568be25f9d4))
* **migrations:** name source migrations by sequence instead of date ([160799a](https://github.com/kirchDev/laravel-pbac/commit/160799a63efd9f0b3f40c7317b6ea24968af3e2c))

## [0.3.0](https://github.com/kirchDev/laravel-pbac/compare/v0.2.0...v0.3.0) (2026-08-26)


### Features

* **console:** add role assign and revoke commands ([f3d3f4f](https://github.com/kirchDev/laravel-pbac/commit/f3d3f4f3588c87b12c49fb94bcf766d1abc2bdb9)), closes [#36](https://github.com/kirchDev/laravel-pbac/issues/36)


### Bug Fixes

* **ci:** let the queue PR body wrap itself ([8070d47](https://github.com/kirchDev/laravel-pbac/commit/8070d47e75879d4fd28b0d0ced8c60a594edda60))
* **ci:** read the Queue App PEM from this owner's own -ci mirror ([7835150](https://github.com/kirchDev/laravel-pbac/commit/7835150dc39bb8d97ac39413d11ae830b98087de))


### Documentation

* **readme:** document the role assign and revoke commands ([16f857f](https://github.com/kirchDev/laravel-pbac/commit/16f857fc754f87fc8a9e12a03f0579a0e8c0f525)), closes [#36](https://github.com/kirchDev/laravel-pbac/issues/36)

## [0.2.0](https://github.com/kirchDev/laravel-pbac/compare/v0.1.0...v0.2.0) (2026-07-26)


### Features

* **ci:** run release-please under the kirchDev Release App ([#6](https://github.com/kirchDev/laravel-pbac/issues/6)) ([870c041](https://github.com/kirchDev/laravel-pbac/commit/870c0419abb427f938e37b431e077a7b78f4957f))
* route questions, ideas and possible bugs to the Discord forum ([aaaa26c](https://github.com/kirchDev/laravel-pbac/commit/aaaa26c3ea860b354f8fda1d2d21fab7a8d55ce0))


### Bug Fixes

* align dependabot labels to the stack: convention ([da00b77](https://github.com/kirchDev/laravel-pbac/commit/da00b7793d120c449989f32303da86438910c393))
* align issue-template labels with the label catalog ([ce3aa36](https://github.com/kirchDev/laravel-pbac/commit/ce3aa3658565dba1880ed35c2224aedfc844a667))
* **dependabot:** use area:* labels so Dependabot PRs get labeled ([#17](https://github.com/kirchDev/laravel-pbac/issues/17)) ([4891691](https://github.com/kirchDev/laravel-pbac/commit/489169109f568c807b4619d31e195f42f24a160b))


### Documentation

* add AGENTS.md and document AI/skills setup ([9fda05a](https://github.com/kirchDev/laravel-pbac/commit/9fda05a7fb7aea16dace06d34755a87be16f420e))
* **changelog:** de-duplicate merge-commit entries ([ca2a071](https://github.com/kirchDev/laravel-pbac/commit/ca2a0716bb4f43204add6e5835ff8d0d003930a1))

## 0.1.0 (2026-05-21)


### ⚠ BREAKING CHANGES

* **roles:** passing a role name to assignRole/removeRole/syncRoles without an active organisation context now throws unless `global: true` is set. Existing call sites that relied on the implicit fallback must either pass `global: true`, set the organisation context, or assign by Role instance / primary key (both bypass scope resolution).

### Features

* add spatie/laravel-permission migration command and bulk role assignment API ([#4](https://github.com/kirchDev/laravel-pbac/issues/4)) ([7e352dc](https://github.com/kirchDev/laravel-pbac/commit/7e352dc76ce51a8eac1d965b706f663e63ea0075))
* gate pbac:migrate-from-spatie behind an opt-in config flag ([dd36c8d](https://github.com/kirchDev/laravel-pbac/commit/dd36c8d6598fe2bff16b6a601746e8fd5a6010f0))
* **roles:** require explicit global flag for cross-scope role mutations ([24098be](https://github.com/kirchDev/laravel-pbac/commit/24098bec4d82eb0d7d9049650bebe832edaac44f))
* scaffold laravel-pbac package ([4395272](https://github.com/kirchDev/laravel-pbac/commit/43952723f33bcabbbb4f52d14823576caa4de6f7))
* **trace:** implement decision trace redaction, logging and accessors ([b15d71c](https://github.com/kirchDev/laravel-pbac/commit/b15d71c3518f54f6931dc8b488db805e506bd4ff))


### Performance

* **authorizer:** introduce request-scoped PermissionResolver cache ([0c32645](https://github.com/kirchDev/laravel-pbac/commit/0c32645d87bcc4d3fb8379a63c8516534966e6e3))


### Documentation

* add CLAUDE.md with architecture and tooling guide ([3414430](https://github.com/kirchDev/laravel-pbac/commit/34144306943b4e6f8a9a65384b47695de3d35f0b))
* add code of conduct ([b9c3eff](https://github.com/kirchDev/laravel-pbac/commit/b9c3eff46aa25936bc9db1009f68f45f29581ed2))
* add contributing guide ([2012fa3](https://github.com/kirchDev/laravel-pbac/commit/2012fa32f98c96ae00e1d3d20ad48eaeeafd9f52))
* add migration guide from spatie/laravel-permission ([e12a49a](https://github.com/kirchDev/laravel-pbac/commit/e12a49a02363e2b9024b3b3d3ab353119e4af809))
* add security policy ([5ca398b](https://github.com/kirchDev/laravel-pbac/commit/5ca398b78bd55e741ba69028a314ed0f2e63a923))
* explain cascade-on-delete behaviour for role/permission deletions ([9c50db0](https://github.com/kirchDev/laravel-pbac/commit/9c50db0623311663294bdd7c02c08e837b6b7080))
* polish README with hero, badges, and feature highlights ([f308074](https://github.com/kirchDev/laravel-pbac/commit/f308074d88eb43cada41fea662cd52652ce91f35))
* replace em-dashes with hyphens ([8a2a878](https://github.com/kirchDev/laravel-pbac/commit/8a2a878b19c6a62c35eb86424f5086659736f5f9))
* restore em-dashes, use colons for step headings, add no-warranty notice ([9cbe83d](https://github.com/kirchDev/laravel-pbac/commit/9cbe83d6bcf470b2b1ac0fa648f7fd73a79e8c09))


### Refactor

* **spatie-migration:** extract SpatieMigrationService from console command ([0eed0ae](https://github.com/kirchDev/laravel-pbac/commit/0eed0aef2d4d47bd57a1fb2b1da43aa215a0f389))
