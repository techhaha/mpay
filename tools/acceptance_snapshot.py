"""Create/verify a recoverable MPAY source snapshot; exclude runtime, credentials and uploaded assets."""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import re
import secrets
import tarfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
# Share the exact source boundary with the native PHP test report; no environment/config discovery.
POLICY = json.loads((ROOT / "tools/source-policy.json").read_text(encoding="utf-8"))
if POLICY.get("format") != 1:
    raise RuntimeError("Unsupported source policy")
for field in ("directories", "root_files", "suffixes", "special_names"):
    if not isinstance(POLICY.get(field), list) or any(not isinstance(item, str) for item in POLICY[field]):
        raise RuntimeError("Invalid source policy fields")
DIRECTORIES = POLICY["directories"]
ROOT_FILES = POLICY["root_files"]
SUFFIXES = set(POLICY["suffixes"])


def source_files() -> list[Path]:
    """Reject symlink sources instead of silently copying files outside the workspace."""
    def declared_path(name: str) -> Path:
        if not name or name.startswith("/") or "\\" in name or ":" in name or any(part in ("", ".", "..") for part in name.split("/")):
            raise RuntimeError("Source paths must stay inside the project")
        path = ROOT / name
        if path.is_symlink() or path.resolve() != path:
            raise RuntimeError("Source symlinks and aliases are not allowed")
        return path

    files = set()
    for name in ROOT_FILES:
        path = declared_path(name)
        if path.is_file():
            files.add(path)
    for directory in DIRECTORIES:
        base = declared_path(directory)
        if not base.exists():
            continue
        for path in base.rglob("*"):
            declared_path(path.relative_to(ROOT).as_posix())
            if path.is_file() and (path.suffix.lower() in SUFFIXES or path.name in POLICY["special_names"]):
                files.add(path)
    return sorted(files, key=lambda item: item.relative_to(ROOT).as_posix())


def manifest_files() -> dict[str, str]:
    return {path.relative_to(ROOT).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest() for path in source_files()}


def verify_archive(archive: Path, saved: dict) -> None:
    """核对可恢复的真实归档，不提取文件；摘要匹配仍须逐项核对内容并拒绝链接、重复及额外条目。"""
    expected = saved["files"]
    digest = saved.get("archive_sha256")
    if (not isinstance(digest, str) or not re.fullmatch(r"[a-f0-9]{64}", digest)
            or archive.is_symlink() or not archive.is_file()):
        raise RuntimeError("Snapshot archive or digest is missing or invalid")
    with archive.open("rb") as stream:
        actual = hashlib.sha256()
        for block in iter(lambda: stream.read(1048576), b""):
            actual.update(block)
    if actual.hexdigest() != digest:
        raise RuntimeError("Snapshot archive digest differs from its manifest")
    seen = set()
    with tarfile.open(archive, "r:gz") as bundle:
        for member in bundle:
            if not member.isfile() or member.name not in expected or member.name in seen:
                raise RuntimeError("Snapshot contains an unexpected, duplicate or non-file entry")
            # current 已与清单一致，尺寸取本地源码作为上限，避免读取伪造的超大 tar 条目。
            if member.size != (ROOT / member.name).stat().st_size:
                raise RuntimeError("Snapshot member size differs from source")
            stream = bundle.extractfile(member)
            if stream is None:
                raise RuntimeError("Snapshot member cannot be read")
            with stream:
                content = hashlib.sha256()
                for block in iter(lambda: stream.read(1048576), b""):
                    content.update(block)
            if content.hexdigest() != expected[member.name]:
                raise RuntimeError("Snapshot member content differs from source")
            seen.add(member.name)
    if seen != set(expected):
        raise RuntimeError("Snapshot is missing declared source files")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--label", default="source")
    parser.add_argument("--verify", type=Path)
    args = parser.parse_args()
    current = manifest_files()
    if args.verify:
        saved = json.loads(args.verify.read_text(encoding="utf-8"))
        if saved.get("format") != 1 or saved.get("files") != current:
            raise RuntimeError("Source set or content differs from the selected snapshot")
        verify_archive(args.verify.parent / "source.tar.gz", saved)
        print(json.dumps({"verified": True, "archive_verified": True, "files": len(current)}))
        return
    if not re.fullmatch(r"[a-z0-9][a-z0-9-]{0,47}", args.label):
        raise ValueError("Snapshot label must be a short lowercase identifier")
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    destination = ROOT / "runtime" / "acceptance" / (stamp + "-" + args.label + "-" + secrets.token_hex(4))
    destination.mkdir(parents=True, mode=0o700)
    archive = destination / "source.tar.gz"
    with tarfile.open(archive, "w:gz") as bundle:
        for relative, digest in current.items():
            path = ROOT / relative
            contents = path.read_bytes()
            if hashlib.sha256(contents).hexdigest() != digest:
                raise RuntimeError("Source changed during snapshot creation: " + relative)
            info = bundle.gettarinfo(str(path), arcname=relative)
            info.size = len(contents)
            bundle.addfile(info, io.BytesIO(contents))
    # Recheck after packaging; a live edit must not be labelled as a stable snapshot.
    if manifest_files() != current:
        raise RuntimeError("Source changed during snapshot creation; archive is not an acceptance baseline")
    manifest = {"format": 1, "label": args.label, "created_utc": stamp, "validation": "not_attested",
                "archive_sha256": hashlib.sha256(archive.read_bytes()).hexdigest(), "files": current}
    verify_archive(archive, manifest)
    (destination / "manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"directory": str(destination), "files": len(current), "archive_sha256": manifest["archive_sha256"]}))


if __name__ == "__main__":
    main()
