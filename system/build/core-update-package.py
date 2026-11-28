#!/usr/bin/env python3
"""Build a deterministic, experimental code-only Geeklog update ZIP."""
import argparse
import hashlib
import json
import pathlib
import re
import zipfile

MAX_FILE = 8 * 1024 * 1024
MAX_TOTAL = 64 * 1024 * 1024
MAX_FILES = 200
VERSION = re.compile(r"(?:0|[1-9][0-9]{0,5})\.(?:0|[1-9][0-9]{0,5})\.(?:0|[1-9][0-9]{0,5})(?:\.[1-9][0-9]{0,5})?")
ID = re.compile(r"[a-z0-9][a-z0-9._-]{0,79}")
ROOTS = {"public_html", "system", "plugins", "language"}


def allowed_path(path):
    parts = path.split("/")
    if len(path) > 240 or len(parts) < 2 or parts[0] not in ROOTS:
        raise ValueError("Invalid root or path length: " + path)
    for part in parts:
        if (not re.fullmatch(r"[A-Za-z0-9_][A-Za-z0-9_.-]*", part)
                or part.endswith(".")
                or re.fullmatch(r"(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?", part, re.I)):
            raise ValueError("Unsafe path: " + path)
    lower = path.lower()
    if (lower in {"public_html/siteconfig.php", "public_html/db-config.php"}
            or any(lower.startswith("public_html/" + item + "/")
                   for item in ("images", "backend", "layout", "data", "logs", "backups"))
            or any(part.lower() in {"db-config.php", "siteconfig.php", "config.php", "data", "logs", "backups"}
                   for part in parts[1:])):
        raise ValueError("Protected path: " + path)
    return path


def read_file(root, path, missing=False):
    current = root
    for part in path.split("/"):
        current = current / part
        if current.is_symlink():
            raise ValueError("Symlink prohibited: " + path)
    if missing and not current.exists():
        return None
    if not current.is_file() or current.stat().st_size > MAX_FILE:
        raise ValueError("Missing or oversized regular file: " + path)
    return current.read_bytes()


def digest(data):
    return hashlib.sha256(data).hexdigest()


def encode(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def version_tuple(value):
    parts = tuple(map(int, value.split(".")))
    return parts + (0,) * (4 - len(parts))


def build(base, target, output, update_id, kind, source, version, schema, paths):
    if not ID.fullmatch(update_id) or kind not in {"release", "patch"}:
        raise ValueError("Invalid package identifier or kind")
    if not all(VERSION.fullmatch(v) for v in (source, version, schema)):
        raise ValueError("Invalid version")
    if (kind == "release" and version_tuple(version) <= version_tuple(source)) or (
            kind == "patch" and version != source):
        raise ValueError("Invalid update transition")
    if not 1 <= len(paths) <= MAX_FILES:
        raise ValueError("Invalid file count")
    files, payload, seen = [], [], set()
    total = 0
    for path in sorted(paths):
        allowed_path(path)
        if path.lower() in seen:
            raise ValueError("Duplicate or case-colliding path: " + path)
        seen.add(path.lower())
        before = read_file(base, path, missing=True)
        after = read_file(target, path)
        if before == after:
            raise ValueError("Unchanged file: " + path)
        total += len(after)
        if total > MAX_TOTAL:
            raise ValueError("Package payload too large")
        files.append({"path": path, "action": "add" if before is None else "replace",
                      "before": None if before is None else digest(before),
                      "after": digest(after), "bytes": len(after)})
        payload.append(("files/" + path, after))
    manifest = {"format": 1, "id": update_id, "kind": kind, "from": source,
                "version": version, "from_schema": schema, "schema": schema, "files": files}
    if output.exists():
        raise ValueError("Output already exists")
    # Exclusive creation; fixed timestamps and regular-file attributes.
    with output.open("xb") as handle:
        with zipfile.ZipFile(handle, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for name, data in [("manifest.json", encode(manifest))] + payload:
                info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                archive.writestr(info, data)
    raw = output.read_bytes()
    return {"bytes": len(raw), "sha256": digest(raw)}


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--base", type=pathlib.Path, required=True)
    p.add_argument("--target", type=pathlib.Path, required=True)
    p.add_argument("--output", type=pathlib.Path, required=True)
    p.add_argument("--id", required=True)
    p.add_argument("--kind", choices=("release", "patch"), required=True)
    p.add_argument("--from-version", required=True)
    p.add_argument("--version", required=True)
    p.add_argument("--schema", required=True)
    p.add_argument("--file", action="append", required=True)
    args = p.parse_args()
    try:
        if not args.base.is_dir() or not args.target.is_dir():
            raise ValueError("Both source trees must exist")
        result = build(args.base, args.target, args.output, args.id, args.kind,
                       args.from_version, args.version, args.schema, args.file)
        print(json.dumps(result, sort_keys=True))
    except (OSError, ValueError, zipfile.BadZipFile) as error:
        p.exit(1, str(error) + "\n")


if __name__ == "__main__":
    main()
