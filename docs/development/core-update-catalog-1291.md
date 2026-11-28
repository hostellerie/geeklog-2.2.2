# Core Update Manager — catalog format v1 (proposal, #1291)

Status: development proposal, not a published update feed. Do not configure a production site to use the example below.

## Distribution model

Use a cumulative stable maintenance release as the default. A targeted patch is an optional, separately identified artifact for a specific source version; never consume a pull request URL, branch tip, unapproved build or executable script directly. All approved patches must be included in the next cumulative release.

The publisher generates an immutable ZIP artifact and a JSON catalog, and signs the exact UTF-8 catalog bytes with an approved release key. Publish on the official HTTPS distribution origin only after review and CI. The official URL, trust keys and signing authority must be approved by maintainers.

## Format

A catalog contains:

| Field | Type | Meaning |
|---|---|---|
| format | integer | 1 |
| sequence | integer | Strictly increasing publication revision |
| published_at | string | UTC ISO 8601 timestamp |
| expires_at | string | UTC ISO 8601 expiry; no installs from expired catalogs |
| channel | string | stable (other channels require explicit opt-in and separate trust policy) |
| updates | array | Available approved artifacts |

Each update contains:

| Field | Meaning |
|---|---|
| id | Immutable unique identifier |
| kind | release or patch |
| from | Explicit supported source code versions (canonical strings) |
| from_schema | Explicit supported source schema versions |
| version | Target code version; for individual patches, current code version |
| schema | Target schema version |
| php_min | Minimum PHP version |
| php_max | Maximum tested/supported PHP version when explicitly established; do not infer support |
| databases | Explicit supported DB engines |
| requires | IDs of required applied patches |
| conflicts | IDs of incompatible applied patches |
| supersedes | IDs included or replaced by this artifact |
| issues | Positive issue numbers in Geeklog-Core/geeklog |
| security | Boolean |
| notes | Plain-text release summary |
| package | Object with HTTPS URL, byte size and SHA-256 digest |

Example (illustrative only; example.invalid is not a release host, digest is a placeholder):

```json
{
  "format": 1,
  "sequence": 1,
  "published_at": "2026-12-01T00:00:00Z",
  "expires_at": "2026-12-31T00:00:00Z",
  "channel": "stable",
  "updates": [
    {
      "id": "geeklog-2.2.3.1",
      "kind": "release",
      "from": ["2.2.3"],
      "from_schema": ["2.2.3"],
      "version": "2.2.3.1",
      "schema": "2.2.3",
      "php_min": "5.6.4",
      "databases": ["mysql"],
      "requires": [],
      "conflicts": [],
      "supersedes": [],
      "issues": [1291],
      "security": false,
      "notes": "Illustrative code-only maintenance release",
      "package": {
        "url": "https://example.invalid/geeklog-2.2.3.1.zip",
        "bytes": 123456,
        "sha256": "0000000000000000000000000000000000000000000000000000000000000000"
      }
    }
  ]
}
```

## Trust, selection and installation

1. Fetch bounded catalog bytes and detached signature from a fixed official HTTPS origin. Permit no untrusted redirects, private/loopback targets or arbitrary user-configured package URLs. Do not send site identifiers or credentials.
2. Verify signature over the exact downloaded bytes before interpreting JSON. Use a trusted pinned public key and an explicit algorithm (initial candidate RSA-SHA256 through OpenSSL, verified on the supported PHP matrix). Key rotation must be authenticated by existing trust or an official release. Fail closed if cryptographic support is unavailable. A SHA-256 checksum alone is not authentication.
3. Reject malformed JSON, invalid/unknown format, duplicate identifiers/fields, unexpected critical fields, oversized lists, unknown channel, expiry and replay (persist the highest accepted sequence per trusted channel; allow re-reading the same sequence only with the same digest). Require canonical stable version strings; do not pass arbitrary version text to the legacy comparator. Stable releases must advance the code version; a patch may preserve it but must have a unique unapplied ID.
4. Check exact source code and schema compatibility, PHP/database support, patch dependencies/conflicts and downgrade protection. A code-only package must not change schema. No installation merely because a newer version exists.
5. Fetch and verify package size and SHA-256 against the authenticated catalog before unpacking. Archive manifest must identify public/private roots, allowed paths, previous file hashes and target hashes; reject unsafe paths, symlinks, duplicates and unexpected files. Never overwrite configuration, site data or local modifications silently.
6. Present notes and preflight to an authorized Root administrator; require CSRF protection and explicit confirmation. Back up code and database as appropriate, lock shared code and all affected sites, stage files, perform approved migrations through existing installer machinery where needed, verify installation, record applied IDs and target versions only after success. Restore from tested backups after failure; irreversible SQL changes require a separate recovery plan.
7. Keep a manual/CLI fallback if web-server permissions do not allow safe deployment.

## Initial implementation boundary

First implement a read-only catalog verifier and update selection service with local fixtures. No network installation, no writes to site files, no official publication and no automatic update in this phase. Then add package-generation CI, package verification, staging/rollback and the administration UI as separately reviewed steps.

Schema migrations, multisite locking, signature implementation, packaging and release authority remain to be validated. The example is not a claim of PostgreSQL or PHP compatibility.
