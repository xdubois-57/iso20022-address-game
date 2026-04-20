# Fonts

This directory contains bundled fonts used for share image generation.

## Liberation Sans

- **Version**: 2.1.5
- **License**: SIL Open Font License 1.1
- **Source**: https://github.com/liberationfonts/liberation-fonts
- **Files**:
  - `LiberationSans-Regular.ttf` (401 KB)
  - `LiberationSans-Bold.ttf` (405 KB)

## Purpose

These fonts are bundled with the project to ensure consistent rendering of share images across all environments (local, test, production). Without bundled fonts, the application would rely on system fonts which vary between servers and can cause rendering inconsistencies.

The fonts are stored in `public/assets/fonts/` because this location is always accessible via `$_SERVER['DOCUMENT_ROOT']`, making it reliable across different server configurations.

## License Information

Liberation Fonts are released under the SIL Open Font License 1.1. This is a free, libre, and open source license that allows:
- Free use in commercial and non-commercial applications
- Modification and redistribution
- Bundling with software

Full license: https://scripts.sil.org/OFL
