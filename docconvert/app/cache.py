"""Temporary on-disk cache for conversion results.

Long documents are expensive to parse, so converted text and chunks are cached
by content hash for a configurable TTL. The cache is intentionally temporary:
it lives in a scratch directory, is pruned on every write and can be cleared
through the API.
"""

from __future__ import annotations

import hashlib
import json
import os
import tempfile
import time
from pathlib import Path


class ConversionCache:
    def __init__(self, directory=None, ttl_seconds: int = 3600, max_entries: int = 500) -> None:
        base = Path(directory) if directory else Path(tempfile.gettempdir()) / "docconvert-cache"
        base.mkdir(parents=True, exist_ok=True)
        self.directory = base
        self.ttl_seconds = max(0, int(ttl_seconds))
        self.max_entries = max(1, int(max_entries))
        self.hits = 0
        self.misses = 0

    @staticmethod
    def key(content: bytes, filename: str, max_chars: int, overlap: int) -> str:
        digest = hashlib.sha256()
        digest.update(content)
        digest.update(b"\x00")
        digest.update(Path(filename or "").suffix.lower().encode("utf-8", "ignore"))
        digest.update(f"|{max_chars}|{overlap}".encode("ascii"))
        return digest.hexdigest()

    def _path(self, key: str) -> Path:
        return self.directory / f"{key}.json"

    def get(self, key: str):
        if self.ttl_seconds == 0:
            return None

        path = self._path(key)
        try:
            stat = path.stat()
        except OSError:
            self.misses += 1
            return None

        if time.time() - stat.st_mtime > self.ttl_seconds:
            _unlink(path)
            self.misses += 1
            return None

        try:
            with path.open("r", encoding="utf-8") as handle:
                payload = json.load(handle)
        except (OSError, ValueError):
            _unlink(path)
            self.misses += 1
            return None

        self.hits += 1
        return payload

    def set(self, key: str, payload: dict) -> None:
        if self.ttl_seconds == 0:
            return

        path = self._path(key)
        tmp = path.with_suffix(".tmp")
        try:
            with tmp.open("w", encoding="utf-8") as handle:
                json.dump(payload, handle, ensure_ascii=False)
            os.replace(tmp, path)
        except OSError:
            _unlink(tmp)
            return

        self.prune()

    def prune(self) -> int:
        """Drop expired entries and enforce the maximum entry count."""

        removed = 0
        now = time.time()
        entries = []

        for path in self.directory.glob("*.json"):
            try:
                mtime = path.stat().st_mtime
            except OSError:
                continue
            if now - mtime > self.ttl_seconds:
                _unlink(path)
                removed += 1
                continue
            entries.append((mtime, path))

        if len(entries) > self.max_entries:
            entries.sort(key=lambda item: item[0])
            for _mtime, path in entries[: len(entries) - self.max_entries]:
                _unlink(path)
                removed += 1

        return removed

    def clear(self) -> int:
        removed = 0
        for path in self.directory.glob("*.json"):
            _unlink(path)
            removed += 1
        return removed

    def stats(self) -> dict:
        entries = list(self.directory.glob("*.json"))
        size = 0
        for path in entries:
            try:
                size += path.stat().st_size
            except OSError:
                pass
        return {
            "entries": len(entries),
            "bytes": size,
            "hits": self.hits,
            "misses": self.misses,
            "ttl_seconds": self.ttl_seconds,
            "max_entries": self.max_entries,
            "directory": str(self.directory),
        }


def _unlink(path: Path) -> None:
    try:
        path.unlink()
    except OSError:
        pass
