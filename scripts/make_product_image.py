#!/usr/bin/env python3
"""kappstore 用の製品画像を作る。 python3 scripts/make_product_image.py"""
import os, urllib.request
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "outputs", "kdbagent-product.png")
os.makedirs(os.path.dirname(OUT), exist_ok=True)

W, H = 1200, 675
img = Image.new("RGB", (W, H), "#0c2b2a"); d = ImageDraw.Draw(img)
for y in range(H):
    t = y / H
    d.line([(0, y), (W, y)], fill=(12 + int(14*t), 43 + int(24*t), 42 + int(22*t)))
d.rectangle([18, 18, W-18, H-18], outline="#0e8a80", width=3)

def font(sz, w="Bold"):
    return ImageFont.truetype(f"/usr/share/fonts/opentype/noto/NotoSansCJK-{w}.ttc", sz)

# Kurage アバター（丸く抜く）
try:
    p = os.path.join(ROOT, "outputs", "_avatar.png")
    if not os.path.exists(p):
        urllib.request.urlretrieve("https://kurage.exbridge.jp/images/kurage-ecosystem-avatar.png", p)
    av = Image.open(p).convert("RGBA").resize((92, 92))
    m = Image.new("L", (92, 92), 0); ImageDraw.Draw(m).ellipse([0, 0, 92, 92], fill=255)
    img.paste(av, (64, 74), m)
    tx = 176
except Exception:
    tx = 64

d.text((tx, 78), "Kurage DB Agent", font=font(72, "Black"), fill="#ffffff")
d.text((64, 190), "1ファイルで、DBを安全に参照・検索・編集", font=font(34), fill="#bfe3df")

bx = 64
for label, col in [("ブラウザ", "#0e8a80"), ("コマンド (AI)", "#c98a1e"), ("HTTP API", "#3a6ea5")]:
    fs = font(25); w = int(d.textlength(label, font=fs)) + 40
    d.rounded_rectangle([bx, 258, bx+w, 308], radius=25, fill=col)
    d.text((bx+20, 266), label, font=fs, fill="#ffffff"); bx += w + 16

fc = font(22, "Regular")
for i, ln in enumerate([
        "設定で宣言した「表・列・操作」だけを触らせる。",
        "任意SQLなし・PDOプリペアド・宣言外は全入口で不可。",
        "だからClaude Codeに任せても、範囲より外は壊せない。"]):
    d.text((64, 358 + i*40), ln, font=fc, fill="#9fc4bf")

d.rounded_rectangle([64, 506, W-64, 606], radius=12, fill="#08201f", outline="#1f4a47")
mono = font(22, "Regular")
d.text((88, 522), "$ php kdbagent.php select customers --search 田中", font=mono, fill="#7fd6cd")
d.text((88, 558), "MySQL / SQLite 両対応 ・ 買い切り ・ レンタルサーバーで動く", font=mono, fill="#5f8f8a")
d.text((64, 632), "kappstore.exbridge.jp ／ 株式会社エクスブリッジ", font=fc, fill="#6f958f")

img.save(OUT, optimize=True)
print("saved:", OUT, img.size)
