# Vazirmatn (self-hosted)

Official Vazirmatn WOFF2 webfonts, vendored so no production page requests
Google Fonts (`fonts.googleapis.com` / `fonts.gstatic.com` are render-blocking
and frequently slow or blocked from Iran, the primary audience).

- **Upstream:** the official `vazirmatn` npm package (project:
  <https://github.com/rastikerdar/vazirmatn>)
- **Version:** 33.0.3 (files taken from `package/fonts/webfonts/`)
- **License:** SIL Open Font License 1.1 — see `OFL.txt` beside the fonts
  (shipped unmodified).
- **Weights shipped:** 400 Regular, 500 Medium, 600 SemiBold, 700 Bold,
  800 ExtraBold, 900 Black — the weights actually used by Tailwind classes
  across all views. Weight 300 is never used and is deliberately NOT shipped.
- **Subsetting:** the official distribution provides full-range files (and an
  Arabic-script-only `Non-Latin` variant) but no Latin-only companion subset,
  so `unicode-range` splitting is not possible without re-subsetting the fonts
  ourselves. We ship the full-range WOFF2 per weight (~50 KB each) without
  `unicode-range`; the tradeoff is documented in the PR that introduced this.

## SHA-256 checksums (v33.0.3)

```
e382101336c6eb32cfb31381c027d02d2e0354bad08f6a395d4088beb3db3d91  Vazirmatn-33.0.3-Regular.woff2
3333e31188a2b628db8780ca22fd5aad85bc083ccee9beb8d4d52db18cb98d48  Vazirmatn-33.0.3-Medium.woff2
6a39a3c25eb18503cad590527b95bb5d4062b889a7ebbd3f01b0488d239e0499  Vazirmatn-33.0.3-SemiBold.woff2
836fae7d42d83faa249bc00e0099592be98a1fa260d22d82f269b6091e585627  Vazirmatn-33.0.3-Bold.woff2
cd67558bbca0ad319b89e3b2edb8a914f87f864951d7a9d24e1404cbf3b45b02  Vazirmatn-33.0.3-ExtraBold.woff2
e65a05523e6c0a434265913805746ebe6ed48af843e6126a936d06f69d7d47ad  Vazirmatn-33.0.3-Black.woff2
```

`@font-face` declarations live centrally in `resources/css/fonts.css`
(imported by `resources/css/app.css`, compiled by Vite for BOTH the public
layout and the user panel). Filament admin assets are untouched.
