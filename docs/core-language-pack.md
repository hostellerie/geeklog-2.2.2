# Geeklog 2.2.2 — Core language pack

This package contains **only the core CMS language PHP files** from the `language/` directory of the Geeklog 2.2.2 `lang` branch.

**Plugin translations are deliberately excluded.** Each plugin will have its own separate language package.

## Contents

The ZIP has the following installation-ready structure:

```text
language/
  english_utf-8.php
  french_france_utf-8.php
  german_utf-8.php
  ...
```

It includes every `language/*.php` file in the source branch, including legacy non-UTF-8 variants where present. It does not include the installer translations, plugin language files, themes, tests, or CMS PHP code outside `language/`.

## Install

1. Back up existing files in your Geeklog installation's private `language/` directory.
2. Download the archive from the **Build Geeklog 2.2.2 core language pack** GitHub Actions run.
3. Extract it into your Geeklog root, merging its `language/` directory into your existing one (not the `public_html/` directory).
4. Configure the desired language in Geeklog and check the administration screens.

Files with identical names will be overwritten. Unrelated files will remain untouched.

## Build and validation

The workflow `.github/workflows/core-language-pack.yml` runs the existing core translation checker before making `geeklog_2.2.2_languages.zip`. The archive contains **only** `language/*.php` paths, and a separate SHA-256 checksum is produced.

To run it: **Actions → Build Geeklog 2.2.2 core language pack → Run workflow** (select the branch containing the workflow).

The archive is available as a workflow artifact; it is **not yet a published GitHub Release**.

## Source

[hostellerie/geeklog-2.2.2 — lang branch](https://github.com/hostellerie/geeklog-2.2.2/tree/lang)

Community translations may require review by fluent speakers. Successful structural checks are not a guarantee that every string has been fully translated.
