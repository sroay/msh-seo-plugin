# `wporg-assets/` — staging for the WordPress.org `assets/` directory

This folder is a **byte-for-byte mirror** of the `assets/` directory in the
plugin's WordPress.org SVN repository. Copy its contents across verbatim when
the time comes; do not rename anything.

## The one thing to get right

`assets/` is a **top-level sibling of `trunk/` and `tags/`** in the SVN repo —
it is *not* inside trunk. The handbook is explicit:

> "This is a top level directory, just like trunk. You would not place the
> screenshots into trunk/assets or tags/1.0/assets."

⚠️ **`msh-seo/assets/` in this repo is a different thing entirely.** That one
holds the plugin's runtime `css/` and `js/` and ships inside the zip. Putting
`screenshot-1.png` there bloats the download and renders nothing on the .org
page. The two folders share a name and nothing else.

## Status

| | |
|---|---|
| Plugin slug | `msh-seo` — **locked at submission**, cannot be changed |
| Submitted | 2026-09-10 |
| Review SLA | 14 business days |
| SVN repo | does not exist yet — `plugins.svn.wordpress.org/msh-seo` returns 404 until approval |
| Banners + icons | ✅ present and dimension-verified |
| Screenshots | ❌ **6 captions declared in readme.txt, 0 image files** |

## Before every commit

```bash
node wporg-assets/verify.mjs
```

Exit 0 means ready. It fails on: a caption with no matching screenshot,
a screenshot with no caption, a banner or icon whose filename disagrees with its
real pixel size, an uppercase filename, a non-PNG, an oversized file, and a
`Stable tag` that does not match the plugin `Version`.

Each of those was validated by deliberately introducing it and confirming the
check goes red — the checks are not decorative.

## What is still needed

Six PNGs, captured from a live WordPress install with the plugin active. The
number must match the caption order in `msh-seo/readme.txt`:

| File | Caption it must show |
|---|---|
| `screenshot-1.png` | Gutenberg sidebar with SEO score, checks, and social preview |
| `screenshot-2.png` | Meta title and description editor with live SERP preview |
| `screenshot-3.png` | Schema type selector with 14 options |
| `screenshot-4.png` | SEO Analytics dashboard with score distribution |
| `screenshot-5.png` | Redirect manager with 404 logging |
| `screenshot-6.png` | WordPress dashboard SEO overview widget |

Rules:

- **PNG only.** The assets handbook allows `png|jpg`; the sample readme also
  claims `jpeg|gif`. PNG is the intersection both agree on.
- **Lowercase filenames.** Uppercase "won't work" — silently.
- **No retina variant.** `screenshot-1-2x.png` is not a thing. Retina exists for
  banners and icons only.
- No official pixel dimensions; 10MB cap each. Something around 1280×800 reads
  well in the directory.
- Content must be **GPL-compatible** — your own UI is fine; no licensed stock
  imagery or proprietary fonts baked into the image.

## When the approval email arrives

```bash
svn co https://plugins.svn.wordpress.org/msh-seo msh-seo-svn
```

`/assets/`, `/tags/` and `/trunk/` already exist in every new repo — no
`svn mkdir` needed.

```bash
cp wporg-assets/*.png msh-seo-svn/assets/
cd msh-seo-svn
svn add assets/* --force
svn propset svn:mime-type image/png assets/*.png
svn ci -m "Add screenshots, banner and icon"
```

The `propset` is **not optional**. Without it SVN serves the images as
`application/octet-stream` and browsers download them instead of displaying
them. It only needs doing once per file.

CDN propagation is usually minutes; up to 6 hours under load.

## Two things that bite later

- **Captions come from the tagged readme.** If `Stable tag` is `1.4.0` and
  `/tags/1.4.0/` exists, `trunk/readme.txt` is read only to find that line and
  is then ignored entirely. Edit the tagged copy, or your caption changes do
  nothing. `verify.mjs` checks the tag matches the plugin version.
- **`assets/` also carries the Playground preview blueprint**, at
  `assets/blueprints/blueprint.json`, if you ever want the "Live Preview"
  button on the plugin page. Not required; not set up here.
