# Core update ZIP format v1 (experimental, #1291)

A package is a deterministic ZIP built from two explicitly provided source trees and an explicit allowlist of changed files. An authenticated catalog supplies the archive byte size and SHA-256. The archive contains exactly:

- `manifest.json` (compact UTF-8 JSON, no newline)
- `files/<repository-relative-path>` for each listed file

Manifest example (illustrative only):

```json
{
  "format": 1,
  "id": "geeklog-2.2.3.1",
  "kind": "release",
  "from": "2.2.3",
  "version": "2.2.3.1",
  "from_schema": "2.2.3",
  "schema": "2.2.3",
  "files": [
    {
      "path": "public_html/example.php",
      "action": "replace",
      "before": "<SHA-256 of original file>",
      "after": "<SHA-256 of replacement>",
      "bytes": 12
    }
  ]
}
```

Only additions and replacements are supported in this phase. Additions have null before hashes. Deletions, migrations, scripts, permission changes and rollback are not supported. The package must preserve the source database schema. The source and target identifiers must correspond to a trusted catalog entry; manifest metadata is not separately trusted.

The virtual repository roots are public_html (public site root), system, plugins and language (private root). An installation-specific adapter will map these to actual configured paths later. Config files and persistence directories (including public images/backend/layout) are excluded for the initial prototype; no SQL or migrations. Reject symlinks, nonregular ZIP entries, unsafe or case-colliding paths, unexpected entries, file/directory conflicts and oversized data. Current limits: 200 payloads, 8 MiB per file, 64 MiB total uncompressed, 70 MiB ZIP, 256 KiB manifest. A staged artifact must be private and immutable.

## Build (development only)

```sh
python3 system/build/core-update-package.py \
  --base /path/to/approved-baseline \
  --target /path/to/approved-target \
  --output /tmp/geeklog-2.2.3.1.zip \
  --id geeklog-2.2.3.1 --kind release \
  --from-version 2.2.3 --version 2.2.3.1 --schema 2.2.3 \
  --file public_html/example.php
```

The CLI emits the archive size and SHA-256 for the catalog. The publisher must cross-check the two trees and commits against reviewed code, verify the ZIP and sign the catalog before publication.

## Verification today

`Geeklog\\UpdatePackage::verify($archivePath, $update)` verifies ZIP size/digest, the exact manifest identity/source/target against an authenticated catalog record, regular-file attributes, permitted paths, entry count, payload sizes and hashes. It returns a manifest without extracting or changing files. It does not verify installed file hashes yet and is not an installer. The next phase needs read-only preflight of the real installed files, site root mapping, permissions/disk-space and staging/rollback.

The CI publishes a disposable fixture artifact with two synthetic PHP files, not a real security or maintenance release. Never install that fixture on a site.


## Read-only installed-site preflight

`Geeklog\\UpdatePreflight::inspect($archivePath, $update, $rootMap, $installedCode, $installedSchema, $sharedCode)` re-verifies the archive, then inspects the configured public, system, plugins and language roots. The caller supplies version values from trusted local state and explicitly confirms whether code is shared; unknown or shared code is blocked in this first prototype. Roots must exist, be readable, nonoverlapping and not themselves symlinks. The root map must be built from Geeklog's actual configured paths, not assumed relative to the web root.

Each manifest entry must resolve to an exact-case, regular, readable original with the expected SHA-256 or an absent addition target. Existing files, links (including hardlinks), case aliases, absent parent directories, local customizations, missing files and inadequate writable permissions block the operation; there is no force option. Available space is estimated per filesystem with a 16 MiB reserve plus backup and staged payload bytes. The report contains relative paths and machine-readable statuses; it does not write any files.

A ready report is advisory, not permission to apply. Recheck immediately under locks, validate the actual configured roots, permissions and quotas, stage files without following links, and back up originals before any changes. No database backup, recovery or installation is performed by this class. Multisite needs a later inventory and coordinated locking/rollback rather than a single-site override. Linux CI covers the path/permission fixtures; Windows/IIS runtime behavior and actual web-server privileges need separate validation.


## Laboratory transaction prototype

`Geeklog\\UpdateTransactionLab` is intentionally CLI-only and accepts only disposable trees explicitly marked with `.geeklog-update-lab` containing `geeklog-update-laboratory-v1`. It has no production entrypoint or Core bootstrap. The four virtual roots are direct children of that laboratory; do not create this marker on an actual Geeklog site.

The prototype acquires a nonblocking filesystem lock, repeats preflight and ZIP verification, creates a private transaction directory, saves verified originals, stages verified payloads, records prepared metadata and appends journal events. Each replacement uses a temporary sibling and rename; additions use a hardlink followed by removal of the temporary sibling to avoid overwriting an existing destination. File modes are retained for replacements. On a caught exception after a replacement, the prototype restores applied files in reverse order, verifies hashes, removes only additions whose contents still match, and reports rolled_back or recovery_required. Backups and journal are intentionally retained.

Tests inject exceptions after the first and second file operations and cover successful commit, lock contention, local modifications, unmarked trees and replay. This is not an atomic multi-file update: a process kill, power loss, concurrent non-updater edits, filesystem/permission changes or journal write failure can require manual recovery. The journal is flushed, not fsync-durable. Ownership, ACLs, Windows/IIS, DB backups, version persistence and real multisite locking remain unimplemented or unvalidated. Implement recovery from an interrupted journal, review independently and retest before any production integration.
