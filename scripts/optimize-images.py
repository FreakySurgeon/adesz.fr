#!/usr/bin/env python3
"""Resize large JPGs (max 1920px wide) and convert to WebP (quality 80)."""

from pathlib import Path
from PIL import Image

IMAGES_DIR = Path(__file__).resolve().parent.parent / "public" / "images"
MAX_WIDTH = 1920
QUALITY = 80

TARGETS = [
    "community.jpg",
    "education.jpg",
    "hero-children.jpg",
    "hero-africa.jpg",
    "agriculture.jpg",
]

for name in TARGETS:
    src = IMAGES_DIR / name
    dst = IMAGES_DIR / (src.stem + ".webp")
    print(f"Processing {name}...")

    img = Image.open(src)
    orig_size = src.stat().st_size
    w, h = img.size

    if w > MAX_WIDTH:
        ratio = MAX_WIDTH / w
        new_h = int(h * ratio)
        img = img.resize((MAX_WIDTH, new_h), Image.LANCZOS)
        print(f"  Resized: {w}x{h} -> {MAX_WIDTH}x{new_h}")
    else:
        print(f"  No resize needed ({w}x{h})")

    img.save(dst, "WEBP", quality=QUALITY)
    new_size = dst.stat().st_size
    savings = (1 - new_size / orig_size) * 100
    print(f"  {orig_size:,} bytes -> {new_size:,} bytes ({savings:.1f}% smaller)")

print("\nDone!")
