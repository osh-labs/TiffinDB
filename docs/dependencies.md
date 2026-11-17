# Dependencies

This file records every third-party component used by TiffinDB and the licence it is used under. TiffinDB itself is `AGPL-3.0-or-later`.

## Rules

- A dependency is added to this file in the same change that adds it to the project.
- The licence is confirmed from the dependency's own licence file, never from memory or from a third-party summary. If it could not be confirmed, the row says so and the dependency is not merged.
- Allowed licences are those listed in `CLAUDE.md`; `OFL-1.1` is allowed for font assets only.
- No personal names or email addresses are written in this file. Copyright notices stay in the shipped licence files, which are reproduced verbatim as their licences require.
- Transitive development packages are not listed one by one. They are covered by `tests/repo/DependencyLicenceTest.php`, which reads the licence each installed package declares and fails on anything outside the allowlist. The file states the count and the licences seen at the last verification.

## Runtime dependencies (shipped)

None.

## Bundled font assets (shipped)

Four families, upright only, weights 400 and 700, WOFF2. Inter, Atkinson Hyperlegible, and Lexend are Latin subsets obtained through an npm package channel used only as a download channel (it is not a project dependency). Source Serif 4 is the complete, unmodified upstream file from the copyright holder's repository. The font files are never modified by the project.

| Family | Files (directory) | Source | Licence | Verified from | Licence file shipped |
|---|---|---|---|---|---|
| Inter | `public/assets/fonts/inter/` | `@fontsource/inter` 5.3.0 (font v20) | OFL-1.1 | `rsms/inter/master/LICENSE.txt`, 2026-11-11; SHA-256 `262481e844521b326f5ecd053e59b98c8b2da78c8ee1bdbb6e8174305e54935a` | `public/assets/fonts/inter/LICENSE.txt` |
| Atkinson Hyperlegible | `public/assets/fonts/atkinson-hyperlegible/` | `@fontsource/atkinson-hyperlegible` 5.3.0 (font v12) | OFL-1.1 | `googlefonts/atkinson-hyperlegible/main/OFL.txt`, 2026-11-11; SHA-256 `f32d22b3908fcad2c86a74000614ec22e6a7f66ea7e867e616026a27aebdc143` | `public/assets/fonts/atkinson-hyperlegible/LICENSE.txt` |
| Lexend | `public/assets/fonts/lexend/` | `@fontsource/lexend` 5.3.0 (font v26) | OFL-1.1 | `googlefonts/lexend/main/OFL.txt`, 2026-11-11; SHA-256 `5da8505887d0fa7fe963445fd58852707fda34adfeb65af25c99d152bab285bd` | `public/assets/fonts/lexend/LICENSE.txt` |
| Source Serif 4 | `public/assets/fonts/source-serif-4/` | Upstream repository `adobe-fonts/source-serif`, tag `4.005R`, directory `WOFF2/TTF/` (font 4.005) | OFL-1.1 | `adobe-fonts/source-serif/4.005R/LICENSE.md`, 2026-11-11; SHA-256 `75784a295293a8992f5a8d99210566e0064a012e6dab6731305e3787f15896c7` | `public/assets/fonts/source-serif-4/LICENSE.txt` |

Reserved Font Names, and why the shipped form is compliant (see `docs/decisions/0009-provisional-defaults.md`):

- Inter: no Reserved Font Name is declared, in the licence file or in the font files. The Latin subset may keep the family name.
- Atkinson Hyperlegible: no Reserved Font Name is declared, in the licence file or in the font files. The Latin subset may keep the family name.
- Lexend: the licence file declares the Reserved Font Name "RevReading Lexend"; the font files declare none. The files' family name is "Lexend", which is the name the upstream project uses for itself in that licence file and is not the reserved name. The Latin subset is bundled. Whether a name sharing one word with a reserved name could be held to use it was not determined; this is recorded as a low residual risk for the maintainer.
- Source Serif 4: the licence file and every font file declare the Reserved Font Name "Source". The family name contains it, so a subset keeping the name would need written permission, and none was found. The channel's Latin subset is therefore not bundled. The project ships the copyright holder's own WOFF2 files, unmodified and under their published names, with the licence file from the same tag. That is distribution of the Original Version, which condition 2 of the licence permits.

Not verified: that the channel's files for Inter, Atkinson Hyperlegible, and Lexend are built from the upstream sources without changes other than subsetting and compression; and whether any permission to use the reserved name "Source" has been granted to anyone else. The bundle does not depend on either.

## Development dependencies (not shipped)

| Name | Version | Purpose | Licence | Verified from | Shipped |
|---|---|---|---|---|---|
| `phpunit/phpunit` | `12.5.43` (pinned exactly); installed 12.5.43 | Test runner | BSD-3-Clause | `vendor/phpunit/phpunit/LICENSE`, 2026-11-11 | No |

Transitive development packages: 24, declaring BSD-3-Clause or MIT in their package metadata (checked automatically).

## Checksums

Font files (SHA-256, as committed):

| File (under `public/assets/fonts/`) | Bytes | SHA-256 |
|---|---|---|
| `inter/inter-latin-400-normal.woff2` | 23,664 | `8909904ab6c872eb994093482a88a28eca2cd95912d7b6fecd72103b0dc07edc` |
| `inter/inter-latin-700-normal.woff2` | 24,356 | `6f56409fd3d64bb85f7d070bce20749db2d66b6d63cec586cc22d1c761be2491` |
| `atkinson-hyperlegible/atkinson-hyperlegible-latin-400-normal.woff2` | 17,208 | `d64ba838ef5472bba248620ec4fd8b5aa7cf0db2908e0bb230600caf279ba7bc` |
| `atkinson-hyperlegible/atkinson-hyperlegible-latin-700-normal.woff2` | 17,524 | `140e2bd25a7315c8a062508391426b0d8c3297400c947b8d847be28f73a199f0` |
| `lexend/lexend-latin-400-normal.woff2` | 14,476 | `0601e0a909219a542cdd581e3f8f1ff8fb208978cbc9bca1c90df02fd8062bb1` |
| `lexend/lexend-latin-700-normal.woff2` | 14,808 | `52dcdaa127bac4a5ea4c39ee17a3f454ceacc18d9f2a45cc453eeab68d378588` |
| `source-serif-4/SourceSerif4-Regular.ttf.woff2` | 76,260 | `6b053e98f0838afe81f3e784727be4583a7c13bb42f198dc5202ecffee0aaee0` |
| `source-serif-4/SourceSerif4-Bold.ttf.woff2` | 81,540 | `6d4fd4c0f08798a478fe34aafea9bf5cfa1cbc6ae4b834193febaee74254d10c` |

Total font payload: 269,836 bytes. With the four licence files: 287,496 bytes.
