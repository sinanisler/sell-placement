# Certificate layout format

Version 1. Read by `includes/layout.php`, with fonts from `includes/fonts.php`. A fixed layout is chosen with the "Layout" setting (Placements > Settings > Certificate), and the Zeppelin 75 preset selects one.

## Page

- A4 portrait, 595.28 × 841.89 pt.
- Positions and sizes in points (1 pt = 1/72 inch). The origin is the top left, and y grows downward.

## Layout

```json
{
  "version": 1,
  "background": { "color": "#fff2bb", "image": "file:zeppelin-background.jpg" },
  "elements": [ ... ]
}
```

- `background` is optional. `color` fills the page. `image` is stretched over the whole page and drawn first, so it sits under everything else. It must be `file:NAME.jpg` (from `assets/certificate/`) or `media:ID`.
- `elements` are drawn in list order. An optional `z` number changes that order; higher is on top.
- `visible` (default `true`) hides an element without deleting it.

## Elements

Every element has an `id`, which must be unique, and a `type`. A `role` is optional (see Roles).

| type    | fields | notes |
| ------- | ------ | ----- |
| `text`  | `box`, `value`, `family`, `size` (default 12), `bold`, `italic`, `color` (default `#000000`), `align` (`left`, `center`, `right`; default `left`), `valign` (`top`, `middle`, `bottom`; default `top`), `leading` (default 1.3), `fit`: `{ "min": 14 }` | One style per element. For mixed styles, use two elements side by side. |
| `image` | `box`, `source` (`approved`, `media:ID` or `file:NAME.jpg`), `frame`: `{ stroke, fill, width, pad }` (optional) | JPEG only. Fitted inside the box, proportions kept, centred. |
| `rect`  | `box`, `fill`, `stroke`, `width` (default 1), `radius` (default 0) | Needs a fill or a stroke. |
| `line`  | `from` `[x, y]`, `to` `[x, y]`, `color`, `width` (default 0.8) | |

A `box` is `{ "x", "y", "w", "h" }`. Width and height must be above 0.

### Text rules

- `family` is `sans` (Helvetica), `serif` (Times), or the id of a family in `spl_font_registry()` (fonts.php). Currently `aptos-zeppelin`.
- `bold` and `italic` choose the face. A family with no such face falls back to its regular face, with a warning. Bold and italic together give bold only.
- Text breaks at spaces. A word longer than the box is broken between characters.
- A line break in `value` (`\n`) is kept. An empty line keeps its height.
- Line height = `size × leading`. The block is as many lines tall as the text needs.
- The first baseline is `0.8 × size` below the top of the block. Later lines follow at the line height.
- `valign` moves the block inside the box.
- With `fit.min`, the text shrinks in 0.5 pt steps until it fits the box height, and never goes below `min`. If it still does not fit, the renderer warns.
- Without `fit`, text can run past its box. No warning.

## Tags and roles

Tags are the same placeholders as in the certificate texts: `{certificate_name}`, `{name}`, `{product}`, `{code}`, `{date}`, `{buyer_name}`, `{order_number}`, `{site_name}`, `{site_url}`. Any other tag is a warning.

Roles tie an element to a certificate field. Each role is used once.

| role    | required | must be |
| ------- | -------- | ------- |
| `name`  | yes      | text with `{certificate_name}` or `{name}` |
| `code`  | yes      | text with `{code}` |
| `date`  | yes      | text with `{date}` |
| `photo` | no       | image with `"source": "approved"` (without it, a warning) |

Other elements have no role. Roles are for checks and the editor. They do not change how an element is drawn.

## Errors and warnings

- **Errors** stop a template from being saved: a missing required role, a role used twice, a role with the wrong tag or type, a colour that is not `#rrggbb`, a number out of range, an unknown family, type or value, an image source outside the allowed forms, a duplicate id, a box without size. When a layout has errors, the certificate falls back to the standard design.
- **Warnings** do not stop saving, and the editor shows them: an unknown tag, no photo element, a rectangle with nothing to draw, bold and italic together, text that does not fit at its smallest size, a missing face or font, an image that is missing or not a JPEG when the certificate is made.

## Images

- JPEG only. The PDF writer embeds JPEG data directly and has no transparency.
- Artwork that sits on a colour, such as the blimp across the card edge, goes into one flattened background JPEG. The Zeppelin background is A4 at 300 dpi (2480 × 3508 px) in `assets/certificate/zeppelin-background.jpg`.
- `approved` is the placement's approved image. `media:ID` is a JPEG from the Media Library. `file:NAME.jpg` is a file in `assets/certificate/`; only the file name is used.

## Fonts

- Standard fonts: Helvetica (`sans`) and Times (`serif`), in regular, bold and italic. They are not embedded.
- Registered families (`spl_font_registry()` in `includes/fonts.php`) are embedded in the PDF as Type 0 CID fonts with the whole font file, and a ToUnicode map so the text can be copied and searched.
- Accepted files: TrueType outlines (`.ttf`) and WOFF 1.0 (`.woff`), which is unpacked with zlib. Not accepted: WOFF 2.0 (`.woff2`), since PHP has no Brotli decoder. Convert those to TTF first. CFF-based OpenType (`.otf`) is not supported yet.
- A file whose `OS/2` table forbids embedding (restricted licence) is refused.
- Kerning is not applied. Glyph widths come from the font file.
- Font files live in `fonts/`, which has an access guard. On Apache the guard is `.htaccess`. On nginx, deny that folder in the server configuration.
- Licence: the Aptos files are Microsoft's, and their EULA does not grant a right to distribute them. Check that before a copy of the plugin leaves the project.
- Not yet: subsetting (embedding only the used glyphs), so each embedded face adds its full size to the PDF.

## Certificate integration

- "Layout" (`cert_layout`): empty for the standard design, or the key of a layout in `includes/layouts/`.
- The Zeppelin 75 preset sets `cert_layout` to `zeppelin` and the colours. Every other preset resets it to the standard design.
- With a layout, the PDF uses the layout's own texts, font and artwork. The colour, border and text settings do not apply to the PDF.
- The approved email keeps its own HTML certificate. It is not affected by the layout.

## Not in version 1

Rotation, opacity, bold-italic faces, rich text (mixed styles in one element), several pages, transparent images, circles and the seal (the seal stays in the standard design until it is ported), QR codes, font subsetting, kerning, and a font upload screen (the families are listed in fonts.php for now).

## Examples

- `includes/layouts/zeppelin.json`: the Zeppelin 75 certificate.
- `tests/fixtures/certificate-hintergrund.json`: the first version of the same design, with shapes instead of a background image and the sans font.

## Tests

```
php tests/layout-test.php <folder for the sample PDFs>
```

The checks cover the format, the renderer, the fonts (TrueType and WOFF), the embedding, the fallbacks and the text helpers. They write sample PDFs to the folder.
