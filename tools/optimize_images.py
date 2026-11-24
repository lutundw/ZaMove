#!/usr/bin/env python3
"""LAB 9, Task 2: convert the heavy PNG/JPEG sources in img/ to WebP and AVIF at several widths.

    python tools/optimize_images.py            (needs:  pip install pillow   - AVIF needs Pillow >= 11.3 or pillow-avif-plugin)

Output: img/opt/<name>-<width>.webp and .avif  (widths smaller than the source only; the source width is always included).
The widths match the srcset attributes in index.html.
"""
import sys
from pathlib import Path
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
SRC, OUT = ROOT / "img", ROOT / "img" / "opt"
WIDTHS = {"hero": [480, 960, 1600, 2400], "dest": [480, 960, 1200]}
QUALITY = {"webp": 78, "avif": 52}

def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    total_in = total_out = 0
    for src in sorted(list(SRC.glob("*.png")) + list(SRC.glob("*.jpg"))):
        kind = "hero" if src.stem.startswith("hero") else "dest"
        im = Image.open(src).convert("RGB")
        total_in += src.stat().st_size
        for w in WIDTHS[kind]:
            if w > im.width:
                continue
            h = round(im.height * w / im.width)
            small = im.resize((w, h), Image.LANCZOS)
            for ext in ("webp", "avif"):
                dst = OUT / f"{src.stem}-{w}.{ext}"
                small.save(dst, ext.upper(), quality=QUALITY[ext])
                total_out += dst.stat().st_size
                print(f"{dst.relative_to(ROOT)!s:40} {dst.stat().st_size/1024:8.1f} KiB")
    print(f"sources {total_in/1024:.0f} KiB -> {total_out/1024:.0f} KiB for all generated variants")
    return 0

if __name__ == "__main__":
    sys.exit(main())
