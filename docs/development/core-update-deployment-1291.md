# Core update manager: deployment gates (#1291)

Status: experimental branch only. Nothing here authorizes production installation.

## Release lifecycle

1. An issue and a pull request describe and review the fix. An open pull request is never an executable update source.
2. The maintainer reviews, merges and tests the exact source revision.
3. Build a reproducible allowlisted code-only ZIP against a supported baseline, with expected original SHA-256 hashes and new hashes. Use a separate patch ID for a hotfix, or a four-component version for a cumulative maintenance release.
4. Test the package against supported PHP and database engines, including a real installed Geeklog tree. Publish a catalog record including prerequisites, conflicts and superseded patches.
5. An authorized release signer signs canonical catalog bytes using an offline or otherwise isolated key. The server hosts the signed catalog, detached signature and immutable packages at the approved HTTPS origin.
6. The administrator checks for updates; the updater verifies signatures, expiration and persistent sequence/digest before showing compatible updates.
7. Preview changes and require deliberate Root confirmation with CSRF protection. Download, verify, backup, drain requests, apply, validate, commit version and receipt, then restore service. Never run arbitrary PR code automatically.

## Current laboratory interface

- `UpdateRunnerLab::install` demonstrates the complete signed catalog to HTTPS download, code-only transaction and SQL readback on disposable installations.
- The laboratory requires its explicit marker and CLI. It does not support arbitrary production layout, database changes or web execution.
- `public_html/admin/coreupdates.php` is an opt-in read-only Root status page. Set `$_CONF['core_update_status'] = true;` in protected local configuration to expose the page on a test instance; default is off. It displays code, database schema and PHP versions. It does not fetch, select or apply an update. Remove the flag to hide it.
- Never create the laboratory marker on a production system.

## Required before enabling the write action

- Maintainer agreement on official distribution origin, public key bootstrap, rotation/revocation and who may publish signed releases. Do not ship a test key.
- Persist catalog state per trust channel/site with deliberate recovery and durable storage; do not silently reset state.
- Map actual split Geeklog paths, configuration and data directories; enforce ownership, permissions, symlinks and file identity. Preserve customizations; support multisite and refuse unsupported layouts.
- Define a supported maintenance gate for all web/API/CLI/cron entry points; verify request drainage and behavior on PHP-FPM/IIS. Do not expose recovery files to the web server.
- Define a narrowly authorized worker rather than executing package mutations in a general HTTP request. Bind approval to exact ID, digest, site and compatibility snapshot. Require Root, CSRF, explicit confirmation and audit.
- Invalidate OPcache safely and run authenticated application health checks. Verify real site bootstrap, frontend, login and admin after changes; automatic rollback or safe maintenance state if checks fail.
- Full upgrade paths from 2.2.2 and 2.1.1 remain installer work. Packages changing schema must be rejected until a distinct migration/backup/recovery contract is implemented and validated on both engines.
- Test filesystem crash durability and recovery on supported hosting environments, disk-full and interrupted process scenarios. Validate permissions under actual deployment user.
- Run a genuine installed-site end-to-end test with real data on MariaDB and PostgreSQL, PHP 5.6/8.3/8.4/8.5 as applicable, IIS if supported, and official plugins/themes.
- Review independently before enabling installation and prepare administrator documentation and recovery procedure.

## Release decision

The code-only updater is a separate feature from the beta installation/migration PRs. The status page may remain opt-in, but the write action must not ship enabled until all relevant gates are met. A beta is not an authorization to weaken update integrity.
