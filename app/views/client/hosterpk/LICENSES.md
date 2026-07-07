# HosterPK template — third-party license ledger

All versions, SHA-256, and SRI for the JS libraries are pinned in
[`vendor/versions.json`](vendor/versions.json) (the authoritative source).

## Vendored JavaScript — MIT

| Library | Version | File | License |
|---|---|---|---|
| Alpine.js | 3.15.12 | `js/vendor/alpine.3.15.12.min.js` | MIT — © Caleb Porzio & contributors |
| HTMX | 2.0.10 | `js/vendor/htmx.2.0.10.min.js` | MIT — © Big Sky Software |

## Compatibility-target / source pins (not vendored as files here) — MIT

| Library | Version | Role | License |
|---|---|---|---|
| Bootstrap | 5.3.8 | own-bundle / compatibility target (Story 2.2) | MIT — © The Bootstrap Authors |
| Bootstrap Icons | 1.13.1 | icon source → `icons/hpk-sprite.svg` | MIT — © The Bootstrap Authors |

## Fonts (copied from `brand/`) — SIL OFL 1.1

| Family | Files | License |
|---|---|---|
| Urbanist | `fonts/urbanist-latin-{500,600,700}-normal.woff2` | SIL OFL 1.1 |
| Hind Siliguri | `fonts/hind-siliguri-latin-{400,500,600,700}-normal.woff2` | SIL OFL 1.1 |

Fonts and the icon sprite are copies of `brand/` (the cross-app source of
truth); their full license text lives in [`brand/LICENSES`](../../../../brand/LICENSES).

## Stock Blesta assets

`css/` and `javascript/` carry stock Blesta/Bootstrap-4.6 assets retained from
the forked `bootstrap` template; they remain under Blesta's own license.
