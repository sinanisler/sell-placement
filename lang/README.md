# Translations

English is the source language. German ships with the plugin. A WordPress site set to German
picks it up automatically; every other locale falls back to the English source strings, so nothing
has to be configured for the plugin to be readable.

## What is in here

| File | Purpose |
| --- | --- |
| `woo-ad-image-upload.pot` | The template: every translatable string with empty translations. Start here for a new language. |
| `woo-ad-image-upload-en_US.po` / `.mo` | English. The source strings mirrored back, so English wording can be adjusted without editing PHP. |
| `woo-ad-image-upload-de_DE.po` / `.mo` | German. Formal address (*Sie*) throughout — the usual register for a German webshop. |
| `woo-ad-image-upload-de_DE_formal.po` / `.mo` | Same catalogue, for sites set to *Deutsch (Sie)*. |
| `woo-ad-image-upload-de_AT.po` / `.mo` | Same catalogue, for Austrian German. |
| `woo-ad-image-upload-de_CH.po` / `.mo` | Same catalogue, for Swiss German. |

The four German files are duplicates on purpose. WordPress looks for one exact filename per locale
and does **not** fall back from `de_AT` to `de_DE`, so a site set to Austrian German would otherwise
see English.

## The `.mo` is what actually loads

WordPress reads the compiled `.mo`, never the `.po`. Editing a `.po` on its own changes nothing —
recompile it afterwards:

```
msgfmt woo-ad-image-upload-de_DE.po -o woo-ad-image-upload-de_DE.mo
```

Poedit does this for you every time you hit save, and is the easier route if you don't have the
gettext tools installed.

## Adding a language

1. Copy `woo-ad-image-upload.pot` to `woo-ad-image-upload-<locale>.po`, using WordPress's own locale
   code — `fr_FR`, `nl_NL`, `tr_TR`, and so on. The name has to match exactly or the file is ignored.
2. Fill in the `msgstr` lines.
3. Compile it to `.mo` next to the `.po`.

Keep every `{placeholder}` and every `%s` / `%1$d` exactly as it appears in the source string. They
are substituted at runtime — a missing `{link}` in an email is a customer with no way to upload, and
a mangled `%1$d` is a PHP warning rather than a number.

## Why translating the defaults is enough

Every customer-facing message is a setting, and each setting's *default* is declared through `__()`
in the plugin. Translating the catalogue therefore translates what customers read, not just the
wp-admin labels — a German shop is German out of the box, with nothing to fill in.

One consequence worth knowing: once a message has been edited and saved on
*WooCommerce → Ad Image Upload*, that stored wording wins over the translation, in every language.
Clear the field and save to hand it back to the catalogue.

## Regenerating the catalogue after changing the plugin

New or reworded `__()` strings need to reach the `.pot` before they can be translated:

```
wp i18n make-pot . lang/woo-ad-image-upload.pot
msgmerge --update lang/woo-ad-image-upload-de_DE.po lang/woo-ad-image-upload.pot
```

Then translate whatever `msgmerge` marked `#, fuzzy` or left empty, and recompile the `.mo`.
