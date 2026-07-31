# Changelog

## [0.1.0](https://github.com/deskhq/laravel-worktree/compare/v0.0.1...v0.1.0) (2026-07-31)


### Features

* add the worktree host binary and strip the skeleton ([#19](https://github.com/deskhq/laravel-worktree/issues/19)) ([474d36d](https://github.com/deskhq/laravel-worktree/commit/474d36df126784f8634e323966752823ba5d238a))
* allocate slots and ports from a machine-global registry ([#23](https://github.com/deskhq/laravel-worktree/issues/23)) ([a894f40](https://github.com/deskhq/laravel-worktree/commit/a894f4017aea704fd33e5e0869f3bd993e359ed5))
* boot and tear a worktree down behind a runtime seam ([#29](https://github.com/deskhq/laravel-worktree/issues/29)) ([14811b8](https://github.com/deskhq/laravel-worktree/commit/14811b8f376951c54aa045af994e9570f1be518a))
* derive every worktree name from one argument, marker included ([#25](https://github.com/deskhq/laravel-worktree/issues/25)) ([d0efbf8](https://github.com/deskhq/laravel-worktree/commit/d0efbf8b19168d205c10a86ad12468c3cd1602dd))
* enforce the host-only entry point and its stream discipline ([#21](https://github.com/deskhq/laravel-worktree/issues/21)) ([d561271](https://github.com/deskhq/laravel-worktree/commit/d56127170b025594fe0bf235d8e3cd0fc0c2399d))
* generate a worktree's .env, ports offset and services detached ([#26](https://github.com/deskhq/laravel-worktree/issues/26)) ([2efd24d](https://github.com/deskhq/laravel-worktree/commit/2efd24d0da00057a1f6db8e7354d587e0ac5a57b))
* generate the compose overlay through SAIL_FILES, not compose.override.yaml ([#27](https://github.com/deskhq/laravel-worktree/issues/27)) ([4a81d51](https://github.com/deskhq/laravel-worktree/commit/4a81d510e69086a98ee82074a63de298dac0dcad))
* list command — repo-scoped table, --all, --json, orphan warning ([#31](https://github.com/deskhq/laravel-worktree/issues/31)) ([393ed03](https://github.com/deskhq/laravel-worktree/commit/393ed03f738b57059d67a4343a683d7cc51a552f))
* publishable stubs, a validated worked example, and a README that leads with the problem ([#37](https://github.com/deskhq/laravel-worktree/issues/37)) ([bd80556](https://github.com/deskhq/laravel-worktree/commit/bd8055695d785aacdf5bbe6e33285039dd62363e))
* read config/worktree.php without booting Laravel ([#22](https://github.com/deskhq/laravel-worktree/issues/22)) ([dc4d042](https://github.com/deskhq/laravel-worktree/commit/dc4d042cf1466e81b1f31f2c36f710f404e2beb8))
* reap command — orphan scan, human gate, and a re-check under the lock ([#33](https://github.com/deskhq/laravel-worktree/issues/33)) ([a1f677e](https://github.com/deskhq/laravel-worktree/commit/a1f677e46242d9dc8a6e341c7051fe98d6da130a))
* remove command — teardown, slot release, and working without a registry entry ([#32](https://github.com/deskhq/laravel-worktree/issues/32)) ([6c290fc](https://github.com/deskhq/laravel-worktree/commit/6c290fc03674246586b65e46e24852145494c47f))
* resolve base refs and attach worktrees without git's DWIM ([#24](https://github.com/deskhq/laravel-worktree/issues/24)) ([71af6bf](https://github.com/deskhq/laravel-worktree/commit/71af6bff64e7049ea22f3a971b2d1f945ec8d2b7))
* run the bootstrap as a bounded declarative step list ([#28](https://github.com/deskhq/laravel-worktree/issues/28)) ([aca15b0](https://github.com/deskhq/laravel-worktree/commit/aca15b037402777d2b4d215bdfae3248a04ec30e))
* wire create, with resume, re-entry and the stdout contract ([#30](https://github.com/deskhq/laravel-worktree/issues/30)) ([74e5817](https://github.com/deskhq/laravel-worktree/commit/74e5817455895a70bb503f02ad2ffb1cd09734ff))


### Bug Fixes

* stop reading the .env's own APP_ENV as one the shell exported ([#38](https://github.com/deskhq/laravel-worktree/issues/38)) ([64859e4](https://github.com/deskhq/laravel-worktree/commit/64859e4ac82f55db17e0934cba97034c8b2da29d))


### Documentation

* the community files a public repository is read through ([#42](https://github.com/deskhq/laravel-worktree/issues/42)) ([d65750b](https://github.com/deskhq/laravel-worktree/commit/d65750b87664bc456e44654148628e91a315250e))

## Changelog

All notable changes to `laravel-worktree` will be documented in this file.
