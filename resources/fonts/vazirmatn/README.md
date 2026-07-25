# Vazirmatn (self-hosted, subset)

Official Vazirmatn WOFF2 webfonts, subset and vendored so no production page
requests Google Fonts (`fonts.googleapis.com` / `fonts.gstatic.com` are
render-blocking and frequently slow or blocked from Iran, the primary
audience) and Latin-only pages never download the Arabic glyphs.

- **Upstream source:** the official `vazirmatn` npm package, version
  **33.0.3** (project: <https://github.com/rastikerdar/vazirmatn>), files
  `package/fonts/webfonts/Vazirmatn-<Weight>.woff2`.
- **License:** SIL Open Font License 1.1 — see `OFL.txt` beside the fonts
  (shipped unmodified). Subsetting is permitted by the OFL.
- **Weights shipped:** 400 Regular, 500 Medium, 600 SemiBold, 700 Bold,
  800 ExtraBold, 900 Black — the weights actually used by Tailwind classes
  across all views. Weight 300 is never used and deliberately not shipped.
- **Subsetting tool:** fonttools **4.63.0** (`pyftsubset`), via the
  reproducible script `scripts/build-vazirmatn-subsets.sh`:

  ```
  npm pack vazirmatn@33.0.3 && tar xzf vazirmatn-33.0.3.tgz
  bash scripts/build-vazirmatn-subsets.sh package/fonts/webfonts resources/fonts/vazirmatn
  ```

  `pyftsubset` preserves the source `head.modified` timestamp, so re-running
  with the same source files and fonttools version is byte-identical
  (verified).
- **Unicode ranges (NON-overlapping):**
  - Arabic/Persian: `U+0600-06FF, U+0750-077F, U+08A0-08FF, U+200C-200F,
    U+FB50-FDFF, U+FE70-FEFF` — Persian/Arabic letters, combining marks,
    Persian and Arabic digits and punctuation, ZWNJ/ZWJ + LRM/RLM (required
    for Persian shaping), Arabic presentation forms. `--layout-features='*'`
    keeps all GSUB shaping features (init/medi/fina/rlig/…).
  - Latin: `U+0000-00FF, U+0131, U+0152-0153, U+2010-2027, U+2030-205F,
    U+20AC, U+2122, U+FFFD` — ASCII + Latin-1, common Latin extras, general
    punctuation excluding U+2000-200F so the two sets can never overlap.

## SHA-256 — SOURCE files (npm vazirmatn@33.0.3, full-range)

```
e382101336c6eb32cfb31381c027d02d2e0354bad08f6a395d4088beb3db3d91  Vazirmatn-Regular.woff2
3333e31188a2b628db8780ca22fd5aad85bc083ccee9beb8d4d52db18cb98d48  Vazirmatn-Medium.woff2
6a39a3c25eb18503cad590527b95bb5d4062b889a7ebbd3f01b0488d239e0499  Vazirmatn-SemiBold.woff2
836fae7d42d83faa249bc00e0099592be98a1fa260d22d82f269b6091e585627  Vazirmatn-Bold.woff2
cd67558bbca0ad319b89e3b2edb8a914f87f864951d7a9d24e1404cbf3b45b02  Vazirmatn-ExtraBold.woff2
e65a05523e6c0a434265913805746ebe6ed48af843e6126a936d06f69d7d47ad  Vazirmatn-Black.woff2
```

## SHA-256 — GENERATED subsets (fonttools 4.63.0)

```
7ed361f8fba72143631cc2ff07c45c5b5ef2196b783379e6015fb22a27e9d2a5  Vazirmatn-33.0.3-Regular-arabic.woff2
c9a7d677035896200f04052a466a87186296c7e75834d00a76697180c5e93e77  Vazirmatn-33.0.3-Regular-latin.woff2
0e9a25b03104e2b65634c22362f38d4ad32907b5723a08317f4d3519e542b00b  Vazirmatn-33.0.3-Medium-arabic.woff2
dc89a65b92cca0f6df0454aceec1fc87edb146b4a969b09260a2a1d20d5dbd2d  Vazirmatn-33.0.3-Medium-latin.woff2
2a16234da1752aef323077faf6cbe6c4364ba85f414342c1fc9dd9da207f2aa5  Vazirmatn-33.0.3-SemiBold-arabic.woff2
69d9673c08042c6f4b8e8cffaa60f7102d100ce47261d2b18dbae9b7a836c754  Vazirmatn-33.0.3-SemiBold-latin.woff2
ed6bf5928c55f99ff6646ff05843596af6faa09713ce540d6be88c3119239827  Vazirmatn-33.0.3-Bold-arabic.woff2
acad241d19a3cdeeeee1ff57297162b4a7c88f033e7686f6f3127d99af9de485  Vazirmatn-33.0.3-Bold-latin.woff2
b66b46e50a72134fb90a05112bc03f1f7b3017effdf21ed554ef88e9deb2fb35  Vazirmatn-33.0.3-ExtraBold-arabic.woff2
b48c37f7876c4896292c7da6fee14e09c2e34f8ba280fdbd3c52579bb708ed10  Vazirmatn-33.0.3-ExtraBold-latin.woff2
cc233f9ee42fc21af495e9e4f6d3adac09dd48be12315fc085011ed78cf20001  Vazirmatn-33.0.3-Black-arabic.woff2
2b0dff0575f70aebb34d77e611ab935b8881b3bf44a0bb2a3c7699f128aedbdf  Vazirmatn-33.0.3-Black-latin.woff2
```

`@font-face` declarations live centrally in `resources/css/fonts.css`
(imported by `resources/css/app.css`, compiled by Vite for BOTH the public
layout and the user panel). Only the Arabic/Persian 400 + 700 faces are
preloaded. Filament admin assets are untouched.
