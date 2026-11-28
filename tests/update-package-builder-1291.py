#!/usr/bin/env python3
"""Generate disposable fixtures and test the real update package builder."""
import hashlib
import importlib.util
import json
import pathlib
import sys
import warnings
import zipfile

root = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("builder", root / "system/build/core-update-package.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
fixture = pathlib.Path(sys.argv[1])
fixture.mkdir(parents=True, exist_ok=False)
base = fixture / "base"
target = fixture / "target"
for directory in (base, target):
    (directory / "public_html").mkdir(parents=True)
    (directory / "system/classes").mkdir(parents=True)
    (directory / "public_html/siteconfig.php").write_text("protected")
    (directory / "system/classes/Unchanged.php").write_text("same")
(base / "public_html/example.php").write_text("<?php // old\n")
(target / "public_html/example.php").write_text("<?php // new\n")
(target / "system/classes/Added.php").write_text("<?php // add\n")
(target / "system/classes/Symlink.php").symlink_to(target / "system/classes/Added.php")
paths = ["public_html/example.php", "system/classes/Added.php"]
passed = total = 0


def check(label, condition):
    global passed, total
    total += 1
    passed += bool(condition)
    print(("PASS" if condition else "FAIL") + ": " + label)


def build(output, files=paths, version="2.2.3.1"):
    return builder.build(base, target, fixture / output, "geeklog-2.2.3.1",
                         "release", "2.2.3", version, "2.2.3", files)


def refuse(label, callback):
    try:
        callback()
        check(label, False)
    except (ValueError, OSError):
        check(label, True)


metadata = build("geeklog-2.2.3.1.zip")
metadata2 = build("same.zip", list(reversed(paths)))
check("reproducible ZIP bytes", metadata == metadata2)
with zipfile.ZipFile(fixture / "geeklog-2.2.3.1.zip") as archive:
    entries = [(info.filename, archive.read(info), info.external_attr) for info in archive.infolist()]
    manifest = json.loads(archive.read("manifest.json"))
check("replace original hash", manifest["files"][0]["before"] ==
      hashlib.sha256((base / paths[0]).read_bytes()).hexdigest())
check("add has no original hash", manifest["files"][1]["before"] is None)
refuse("protected config rejected", lambda: build("protected.zip", ["public_html/siteconfig.php"]))
refuse("path traversal rejected", lambda: build("traversal.zip", ["public_html/../system/classes/Added.php"]))
refuse("duplicate path rejected", lambda: build("duplicate.zip", paths + paths))
refuse("unchanged file rejected", lambda: build("unchanged.zip", ["system/classes/Unchanged.php"]))
refuse("symlink rejected", lambda: build("link.zip", ["system/classes/Symlink.php"]))
refuse("downgrade rejected", lambda: build("downgrade.zip", paths, "2.2.2"))
refuse("existing output not overwritten", lambda: build("geeklog-2.2.3.1.zip"))


def mutate(name, changes):
    with warnings.catch_warnings():
        warnings.simplefilter("ignore", UserWarning)
        with zipfile.ZipFile(fixture / name, "w") as archive:
            for path, content, attributes in changes:
                info = zipfile.ZipInfo(path, (1980, 1, 1, 0, 0, 0))
                info.create_system = 3
                info.external_attr = attributes
                info.compress_type = zipfile.ZIP_DEFLATED
                archive.writestr(info, content)


regular = 0o100644 << 16
mutate("payload-tampered.zip", [entries[0], (entries[1][0], b"X" * len(entries[1][1]), regular), entries[2]])
mutate("extra.zip", entries + [("files/system/extra.php", b"extra", regular)])
mutate("traversal.zip", entries + [("files/system/../bad.php", b"bad", regular)])
mutate("duplicate.zip", entries + [entries[1]])
mutate("symlink.zip", [entries[0], (entries[1][0], entries[1][1], 0o120777 << 16), entries[2]])
mutate("missing.zip", entries[:2])
mutate("protected.zip", entries + [("files/public_html/siteconfig.php", b"bad", regular)])
mutate("case.zip", entries + [(entries[1][0].upper(), b"bad", regular)])
bad = json.loads(entries[0][1])
bad["schema"] = "2.2.3.1"
mutate("schema.zip", [("manifest.json", builder.encode(bad), regular)] + entries[1:])
(fixture / "metadata.json").write_text(json.dumps(metadata))
patch = builder.build(base, target, fixture / "patch-1291.zip", "patch-1291",
                      "patch", "2.2.3", "2.2.3", "2.2.3", paths)
(fixture / "patch-metadata.json").write_text(json.dumps(patch))

next_target = fixture / "next-target"
(next_target / "public_html").mkdir(parents=True)
(next_target / "public_html/example.php").write_text("<?php // cumulative\\n".replace("\\n", "\n"))
cumulative = builder.build(target, next_target, fixture / "cumulative.zip", "geeklog-2.2.3.1",
                           "release", "2.2.3", "2.2.3.1", "2.2.3", ["public_html/example.php"])
(fixture / "cumulative-metadata.json").write_text(json.dumps(cumulative))
invalid_target = fixture / "invalid-php"
(invalid_target / "public_html").mkdir(parents=True)
(invalid_target / "public_html/example.php").write_text("<?php function (")
invalid = builder.build(base, invalid_target, fixture / "invalid-php.zip", "geeklog-2.2.3.1",
                        "release", "2.2.3", "2.2.3.1", "2.2.3", ["public_html/example.php"])
(fixture / "invalid-php-metadata.json").write_text(json.dumps(invalid))
print(f"TOTAL: {passed}/{total} builder cases passed")
sys.exit(0 if passed == total else 1)
