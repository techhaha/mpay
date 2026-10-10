#!/usr/bin/env python3
"""Validate the public Wiki source and export it without touching Git history.

Only files below docs/wiki are publishable. Deployment credentials, maintenance
reports and application files are deliberately outside this tool's source root.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path
from urllib.parse import quote, unquote, urlsplit

SOURCE = Path(__file__).resolve().parents[1]
PLATFORMS = {
    'github': 'https://github.com/techhaha/mpay/wiki/',
    'gitee': 'https://gitee.com/technical-laohu/mpay/wikis/',
}
# Wiki pages use each host. Binary assets and downloadable examples use the
# public GitHub Wiki raw endpoint; both Git repositories also carry the files.
RAW = 'https://raw.githubusercontent.com/wiki/techhaha/mpay/'
LINK = re.compile(r'(!?\[[^\]\n]*\]\()([^\s)]+)([^)\n]*\))')
HTML_LINK = re.compile(r'((?:src|href)=["\'])([^"\']+)(["\'])')
FENCE = re.compile(r'^```[^\n]*\n.*?^```\s*$', re.M | re.S)
RASTER_SUFFIXES = {'.png', '.jpg', '.jpeg'}
ALLOWED_SUFFIXES = {'.md', '.svg', '.php', '.example', '.py', '.html'} | RASTER_SUFFIXES


def files() -> list[Path]:
    """An allowlist keeps accidental caches or local secrets out of export."""
    result = []
    for path in sorted(SOURCE.rglob('*')):
        relative = path.relative_to(SOURCE)
        if any(part.startswith('.') or part == '__pycache__' for part in relative.parts):
            continue
        if path.is_symlink():
            raise ValueError(f'Symlinks are not publishable: {relative}')
        if not path.is_file():
            continue
        if path.suffix not in ALLOWED_SUFFIXES:
            raise ValueError(f'Unexpected public file: {relative}')
        if len(relative.parts) > 1 and relative.parts[0] not in {'assets', 'examples', 'tools'}:
            raise ValueError(f'Unexpected public directory: {relative}')
        result.append(path)
    return result


def output_name(path: Path) -> Path:
    relative = path.relative_to(SOURCE)
    # Wiki renderers identify pages by basename. Keep the tool guide distinct
    # from repository README files while retaining tools/README locally.
    return Path('Wiki-发布工具.md') if relative.as_posix() == 'tools/README.md' else relative


def resolve_link(origin: Path, link: str) -> tuple[Path, str] | None:
    parts = urlsplit(link)
    if parts.scheme or parts.netloc:
        if parts.scheme not in {'http', 'https', 'mailto'}:
            raise ValueError(f'Unsupported link scheme: {link}')
        return None
    target = (origin.parent / unquote(parts.path)).resolve() if parts.path else origin
    if not target.is_relative_to(SOURCE):
        raise ValueError(f'Link escapes public Wiki: {link}')
    if not target.is_file():
        raise ValueError(f'Missing link target: {link}')
    return target, parts.fragment


def anchors(text: str) -> set[str]:
    found = set(re.findall(r'<a\s+(?:id|name)=["\']([^"\']+)', text))
    counts: dict[str, int] = {}
    for heading in re.findall(r'^#{1,6}\s+(.+)$', FENCE.sub('', text), re.M):
        base = re.sub(r'[^\w\s\-]', '', heading.strip().lower()).replace(' ', '-')
        count = counts.get(base, 0)
        found.add(base if count == 0 else f'{base}-{count}')
        counts[base] = count + 1
    return found


def check() -> list[Path]:
    public = files()
    errors = []
    for path in public:
        # A browser capture may return JPEG regardless of its chosen filename.
        # Reject mismatched formats before export; visual correctness still
        # requires opening the saved file and reviewing the rendered tutorial.
        if path.suffix in RASTER_SUFFIXES:
            data = path.read_bytes()
            valid = (data.startswith(b'\x89PNG\r\n\x1a\n') if path.suffix == '.png'
                     else data.startswith(b'\xff\xd8\xff') and data.endswith(b'\xff\xd9'))
            if not valid:
                errors.append(f'{path.relative_to(SOURCE)}: image bytes do not match filename')
        if path.suffix != '.md':
            continue
        body = path.read_text(encoding='utf-8')
        if '\ufffd' in body:
            errors.append(f'{path.name}: invalid replacement character')
        # Legacy material is intentionally preserved verbatim and clearly labeled.
        if not path.name.startswith('旧版-'):
            if re.search(r'(?i)((?<![\w])[A-Z]:[\\/]|ubuntu22_dev|192\.168\.|BEGIN (RSA )?PRIVATE KEY)', body):
                errors.append(f'{path.name}: environment-specific or private material')
        text = FENCE.sub('', body)
        for match in list(LINK.finditer(text)) + list(HTML_LINK.finditer(text)):
            try:
                resolved = resolve_link(path, match.group(2))
                if resolved:
                    target, fragment = resolved
                    if target not in public:
                        raise ValueError(f'Target not publishable: {match.group(2)}')
                    if fragment and target.suffix == '.md':
                        if unquote(fragment) not in anchors(target.read_text(encoding='utf-8')):
                            raise ValueError(f'Missing heading anchor: {match.group(2)}')
            except ValueError as error:
                errors.append(f'{path.name}: {error}')
    if errors:
        raise ValueError('\n'.join(errors))
    # In the source repository, validate its two public entry points as well.
    # A standalone Wiki clone has no docs/wiki layout and skips this block.
    if SOURCE.name == 'wiki' and SOURCE.parent.name == 'docs':
        project = SOURCE.parent.parent
        entrypoints = [project / 'README.md', SOURCE.parent / 'README.md']
        allowed = set(public + entrypoints + [project / 'LICENSE.txt'])
        for path in entrypoints:
            text = FENCE.sub('', path.read_text(encoding='utf-8'))
            for match in list(LINK.finditer(text)) + list(HTML_LINK.finditer(text)):
                parts = urlsplit(match.group(2))
                if parts.scheme or parts.netloc:
                    continue
                target = (path.parent / unquote(parts.path)).resolve()
                if target not in allowed or not target.is_file():
                    raise ValueError(f'{path}: missing or non-public target {match.group(2)}')
    return public


def render(path: Path, platform: str) -> bytes:
    if path.suffix != '.md':
        # Git normalizes text to LF; hash the actual committed representation,
        # not Windows checkout line endings. Raster images remain byte-exact.
        return path.read_bytes() if path.suffix in RASTER_SUFFIXES else path.read_text(encoding='utf-8').encode('utf-8')

    def replace(match: re.Match[str]) -> str:
        resolved = resolve_link(path, match.group(2))
        if not resolved:
            return match.group(0)
        target, fragment = resolved
        name = output_name(target)
        url = PLATFORMS[platform] + quote(name.stem) if target.suffix == '.md' else RAW + quote(name.as_posix(), safe='/')
        if fragment:
            url += '#' + fragment
        return match.group(1) + url + match.group(3)

    # Only prose links are rewritten, never code samples inside fenced blocks.
    text = path.read_text(encoding='utf-8')
    result, start = [], 0
    for fence in FENCE.finditer(text):
        result.append(HTML_LINK.sub(replace, LINK.sub(replace, text[start:fence.start()])))
        result.append(fence.group(0))
        start = fence.end()
    result.append(HTML_LINK.sub(replace, LINK.sub(replace, text[start:])))
    return ''.join(result).encode('utf-8')


def export(public: list[Path], platform: str, destination: Path) -> None:
    destination = destination.resolve()
    if destination == SOURCE or destination.is_relative_to(SOURCE) or SOURCE.is_relative_to(destination):
        raise ValueError('Export destination must be separate from the source and its ancestors')
    # Work only in a clone of the intended Wiki. Never replace an arbitrary
    # directory, and never remove unknown files or invoke Git mutations.
    if not (destination / '.git').is_dir():
        raise ValueError('Destination must be a cloned Wiki Git repository')
    import subprocess
    remote = subprocess.check_output(['git', '-C', str(destination), 'remote', 'get-url', 'origin'], text=True).strip()
    expected = {'github': 'https://github.com/techhaha/mpay.wiki.git', 'gitee': 'https://gitee.com/technical-laohu/mpay.wiki.git'}
    if remote != expected[platform]:
        raise ValueError('Destination origin does not match the selected MPAY Wiki')
    manifest = {'platform': platform, 'files': {}}
    for path in public:
        name = output_name(path)
        target = destination / name
        if not target.resolve().is_relative_to(destination) or target.is_symlink():
            raise ValueError(f'Unsafe destination: {name}')
        data = render(path, platform)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        manifest['files'][name.as_posix()] = {
            'source': hashlib.sha256(path.read_bytes()).hexdigest(),
            'export': hashlib.sha256(data).hexdigest(),
        }
    (destination / 'wiki-manifest.json').write_bytes((json.dumps(manifest, ensure_ascii=False, indent=2) + '\n').encode('utf-8'))
    print(f'Exported {len(public)} files to {destination}; review git diff before committing.')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command')
    sub.add_parser('check')
    out = sub.add_parser('export')
    out.add_argument('--platform', required=True, choices=PLATFORMS)
    out.add_argument('--destination', required=True, type=Path)
    args = parser.parse_args()
    try:
        public = check()
        if args.command == 'export':
            export(public, args.platform, args.destination)
        else:
            print(f'Checked {len(public)} public files; Markdown targets and assets are valid.')
        return 0
    except (ValueError, OSError) as error:
        print(str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
