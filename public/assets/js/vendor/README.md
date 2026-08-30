# Vendored third-party JavaScript

Files here are **not** written by this project. They are committed
deliberately rather than fetched at runtime, and the reason matters: hybrid
mode grades the player against country-specific address layouts, so a kiosk
on a restricted network that could not reach a CDN would silently fall back
to one hardcoded layout for every country and mark correct answers wrong.
Bundling keeps gameplay correct offline. (Styling and confetti still come
from a CDN — those only affect appearance, not scoring.)

## address-formatter.js

| | |
|---|---|
| Package | [`@fragaria/address-formatter`](https://github.com/fragaria/address-formatter) |
| Version | 7.0.0 |
| License | MIT |
| Build | `dist/umd/address-formatter.js`, copied verbatim from the npm tarball |

The UMD build assigns `globalThis.addressFormatter`, which is what
`public/assets/js/lib/address.js` reads. It is loaded as a plain `<script>`
in `app/Views/layout.php`, before `app.js` (a module, and therefore
deferred), so the global is always set by the time anything uses it.

`lib/address.js` still keeps a fallback for when the global is absent, so a
failed or missing load degrades to simple concatenation instead of throwing.

### Updating

```bash
npm pack @fragaria/address-formatter          # produces a .tgz
tar -xzf fragaria-address-formatter-*.tgz
cp package/dist/umd/address-formatter.js public/assets/js/vendor/
```

Check the API before bumping a major version: v7 requires `countryCode`
inside the address object rather than as a format option, and
`lib/address.js` depends on that shape.

> **Note:** `.gitignore` anchors the Composer rule as `/vendor/`. Unanchored,
> `vendor/` matches a directory of that name at any depth and silently
> excluded this directory — which is exactly how this file went missing from
> every clone. Keep the leading slash.
