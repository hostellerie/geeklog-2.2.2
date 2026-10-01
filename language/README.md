# Geeklog Language Maintenance

This directory contains the core language files used by Geeklog.

## Reference language

`english_utf-8.php` is the structural reference for maintained UTF-8 translations.

A translated language file should keep the same language arrays, keys, placeholders, and required metadata as the English reference. Only the human-readable text should change.

At minimum, each UTF-8 language file should define:

- `$LANG_CHARSET`
- `$LANG_ISO639_1`
- the same `$LANGxx` arrays as `english_utf-8.php`
- the same keys inside those arrays

Do not renumber existing keys simply to remove gaps. Existing numeric identifiers are part of the language-file contract and may be referenced by core code or extensions.

## Encoding

New translations should use UTF-8 and follow the current naming convention:

```text
<language>_utf-8.php
<language>_<variant>_utf-8.php
```

Examples:

```text
french_france_utf-8.php
french_canada_utf-8.php
german_formal_utf-8.php
spanish_argentina_utf-8.php
```

Legacy non-UTF-8 files still present in the repository should not be used as templates for new translations.

## Adding or updating strings

When a new English string is added:

1. Add it to `english_utf-8.php` using the existing array and key structure.
2. Preserve every placeholder used by the source string, such as `%s`, `%d`, or positional placeholders.
3. Run the language checker.
4. Update translations where possible.
5. If a translation is not yet available, leave the missing item visible to the maintenance process rather than silently changing array numbering or structure.

When translating an existing string:

- preserve HTML tags and Geeklog template variables where they are required;
- preserve `sprintf` placeholders and their meaning;
- preserve escape sequences required by PHP syntax;
- do not hard-code site-specific URLs or text;
- keep terminology consistent within the same language.

## Automated audit

Run the checker from the repository root:

```bash
php tools/check-languages.php
```

To check one language file only:

```bash
php tools/check-languages.php language/french_france_utf-8.php
```

The checker compares UTF-8 language files with `language/english_utf-8.php`.

It reports:

- missing language arrays;
- extra language arrays;
- missing keys;
- extra keys;
- placeholder mismatches;
- missing or unexpected charset/ISO metadata;
- strings that are identical to English and may need review.

Identical strings are reported as notices, not errors, because many terms such as `SQL`, `URL`, product names, and technical terms are valid in multiple languages.

## Severity

The checker uses two broad levels.

### Errors

These indicate structural or runtime risks, for example:

- a missing language array;
- a missing key;
- incompatible placeholders;
- invalid charset metadata.

Errors should be fixed before considering a translation structurally complete.

### Notices

These indicate content that may need human review, for example:

- a translated value identical to English;
- an extra key that no longer exists in the reference.

A notice does not necessarily mean the translation is wrong.

## Adding a new language

Start from `english_utf-8.php`, not from another translation.

Then:

1. rename the file using the existing Geeklog convention;
2. set `$LANG_CHARSET = 'utf-8'`;
3. set the correct `$LANG_ISO639_1` code;
4. translate the human-readable strings without changing array names or keys;
5. run `php tools/check-languages.php <file>`;
6. review all placeholder warnings and identical-to-English notices;
7. have the translation reviewed by a fluent speaker when possible.

## Regional variants

Geeklog already supports regional or style variants through file names such as:

- `french_france_utf-8.php`
- `french_canada_utf-8.php`
- `spanish_argentina_utf-8.php`
- `german_formal_utf-8.php`

Keep this convention for compatibility. Do not rename existing language files to locale-code file names unless the core loading mechanism is deliberately migrated in a separate change.

## Plugins

Core plugins maintain their own language directories. The same principles apply there:

- no user-facing hard-coded strings;
- English as the structural reference;
- matching keys across translations;
- preserved placeholders;
- UTF-8 for new work.

The initial checker in `tools/check-languages.php` audits the core `language/` directory. Plugin support can be added separately without changing the core language-file format.

## Pull requests

Prefer focused pull requests.

Good examples:

- language maintenance tooling;
- French translation cleanup;
- Italian translation;
- Brazilian Portuguese translation.

Avoid combining large translation rewrites, tooling changes, and several new languages in one pull request unless there is a strong reason.

### Intentional strings identical to English

Some words are legitimately spelled the same in English and another language (for example German `April`, `Status`, `Homepage` or `Computer`). The checker must not force artificial translations merely to make the audit green.

Such cases can be listed explicitly in `tools/language-identical-allowlist.php`. Keep this list conservative and language-specific. An entry should only be added after confirming that the English spelling is also normal usage in the target language.


## Ecosystem coverage report

The same checker can also audit language coverage across public repositories in the
`Geeklog-Plugins` GitHub organization:

```bash
php tools/check-languages.php --plugins
```

To generate the repository dashboard:

```bash
php tools/check-languages.php --plugins --report=docs/language-status.md
```

The ecosystem report includes:

- the current core language status;
- plugin translations that are complete, partial, or missing;
- languages that already exist in plugins but are absent from the core;
- suggested languages for future Geeklog localization.

The strategic list of suggested languages is maintained in
`tools/language-recommendations.php`.

Plugin files are inspected structurally and are not executed. The ecosystem audit
currently compares language key coverage against each plugin's English reference.
The core audit remains the stricter check and additionally validates placeholders
and suspicious strings identical to English.

GitHub Actions generates `docs/language-status.md` with the same checker and
commits the report only when its contents change.
