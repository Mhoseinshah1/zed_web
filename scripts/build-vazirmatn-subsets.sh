#!/usr/bin/env bash
# Reproducible generation of the vendored Vazirmatn subsets.
#
# Source: the OFFICIAL `vazirmatn` npm package, version 33.0.3
#         (upstream project: https://github.com/rastikerdar/vazirmatn),
#         files package/fonts/webfonts/Vazirmatn-<Weight>.woff2.
# Tool:   fonttools (pyftsubset) — generated with fonttools 4.63.0.
#         pyftsubset preserves the source head.modified timestamp, so the
#         output is deterministic for a given source file + tool version.
#
# Usage:
#   npm pack vazirmatn@33.0.3            # produces vazirmatn-33.0.3.tgz
#   tar xzf vazirmatn-33.0.3.tgz
#   bash scripts/build-vazirmatn-subsets.sh package/fonts/webfonts resources/fonts/vazirmatn
#
# The two unicode-range sets are NON-OVERLAPPING by construction:
#   Arabic/Persian: Persian+Arabic letters, combining marks, Persian/Arabic
#     digits and punctuation (U+0600-06FF), Arabic Supplement, Arabic Extended-A,
#     ZWNJ/ZWJ + LRM/RLM (U+200C-200F — required for correct Persian shaping),
#     and the Arabic presentation forms.
#   Latin: Basic Latin + Latin-1, common Latin extras, general punctuation
#     EXCLUDING U+2000-200F (so it can never overlap the Arabic set), Euro,
#     trademark, replacement character.
set -euo pipefail

SRC_DIR="${1:?source dir with Vazirmatn-<Weight>.woff2 files}"
OUT_DIR="${2:?output dir}"
VER="33.0.3"

ARABIC_RANGES='U+0600-06FF,U+0750-077F,U+08A0-08FF,U+200C-200F,U+FB50-FDFF,U+FE70-FEFF'
LATIN_RANGES='U+0000-00FF,U+0131,U+0152-0153,U+2010-2027,U+2030-205F,U+20AC,U+2122,U+FFFD'

mkdir -p "$OUT_DIR"
for w in Regular Medium SemiBold Bold ExtraBold Black; do
    src="${SRC_DIR}/Vazirmatn-${w}.woff2"
    [ -f "$src" ] || { echo "missing source: ${src}" >&2; exit 1; }
    # --layout-features='*' keeps ALL shaping features — Arabic init/medi/fina
    # joining breaks without them.
    pyftsubset "$src" \
        --output-file="${OUT_DIR}/Vazirmatn-${VER}-${w}-arabic.woff2" \
        --flavor=woff2 \
        --layout-features='*' \
        --unicodes="$ARABIC_RANGES"
    pyftsubset "$src" \
        --output-file="${OUT_DIR}/Vazirmatn-${VER}-${w}-latin.woff2" \
        --flavor=woff2 \
        --layout-features='*' \
        --unicodes="$LATIN_RANGES"
done

echo "Generated subsets in ${OUT_DIR}:"
sha256sum "${OUT_DIR}"/Vazirmatn-"${VER}"-*-arabic.woff2 "${OUT_DIR}"/Vazirmatn-"${VER}"-*-latin.woff2
