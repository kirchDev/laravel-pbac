# Changelog

## 0.1.0 (2026-05-21)


### ⚠ BREAKING CHANGES

* **roles:** passing a role name to assignRole/removeRole/syncRoles without an active organisation context now throws unless `global: true` is set. Existing call sites that relied on the implicit fallback must either pass `global: true`, set the organisation context, or assign by Role instance / primary key (both bypass scope resolution).

### Features

* add bulk role assignment API and spatie-permission migration command ([794be9e](https://github.com/kirchDev/laravel-pbac/commit/794be9e746a1affab08adc40e93b70be1a76c6b4))
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
