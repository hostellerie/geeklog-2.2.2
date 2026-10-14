# Nightly source tarball

This workflow packages the **master** branch every night at 02:25 UTC (and on manual dispatch).

- Source snapshot built using `git archive`, without the `.git` folder.
- Filename includes the UTC date and abbreviated source commit SHA.
- A SHA-256 checksum and a build-information file accompany the archive.
- GitHub Actions artifacts are kept for 30 days and require GitHub artifact access; they are **not** stable public download URLs.
- Source archive is **not** the same as a tested Geeklog install/release package and is **not recommended for production deployment**.
- Unmerged pull requests are excluded.
- Does not write to the repository or create tags/releases.

After the upstream maintainers approve, a separate publication mechanism (rolling nightly release or hosted storage with a stable URL) may be implemented, with tested packaging semantics and retention policies. Do not replace or overwrite stable release assets.
