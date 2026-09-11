// Pre-flight for the WordPress.org /assets/ commit.
//
// This folder is a byte-for-byte mirror of the `assets/` directory in the
// plugin's SVN repo — a TOP-LEVEL sibling of trunk/ and tags/, never inside
// them. Run this before `svn ci`; it refuses the things wordpress.org accepts
// silently and then fails to render.
//
// The live defect it exists to catch: readme.txt declares 6 numbered captions
// and, as of 2026-09-11, zero screenshot files exist. Pushed as-is the plugin
// page publishes six captions with no images, and nothing warns you.
//
//   node wporg-assets/verify.mjs
//
// Exit 1 on any error. Warnings do not block.
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const README = path.join(HERE, "..", "msh-seo", "readme.txt");
const PLUGIN_PHP = path.join(HERE, "..", "msh-seo", "msh-seo.php");

const errors = [];
const warnings = [];
const ok = [];

/** PNG IHDR: width and height are big-endian uint32 at byte offsets 16 and 20. */
function pngSize(buf) {
  if (buf.length < 24) return null;
  if (buf.slice(1, 4).toString("latin1") !== "PNG") return null;
  return { w: buf.readUInt32BE(16), h: buf.readUInt32BE(20) };
}

const MB = 1024 * 1024;

// Max sizes are wordpress.org's own: banners 4MB, icons 1MB, screenshots 10MB.
const REQUIRED = [
  { name: "banner-772x250.png", w: 772, h: 250, max: 4 * MB, kind: "banner" },
  { name: "banner-1544x500.png", w: 1544, h: 500, max: 4 * MB, kind: "banner (retina)" },
  { name: "icon-128x128.png", w: 128, h: 128, max: 1 * MB, kind: "icon" },
  { name: "icon-256x256.png", w: 256, h: 256, max: 1 * MB, kind: "icon (retina)" },
];

const present = fs.readdirSync(HERE).filter((f) => fs.statSync(path.join(HERE, f)).isFile());

// ---------------------------------------------------------------------------
// 1. Banners and icons — the filename MUST equal the real pixel size.
//    "Image sizes should be the same as implied by the names." A 772x250.png
//    that is actually 771px wide simply does not show, with no error.
// ---------------------------------------------------------------------------
for (const r of REQUIRED) {
  const p = path.join(HERE, r.name);
  if (!fs.existsSync(p)) {
    errors.push(`MISSING ${r.name} (${r.kind})`);
    continue;
  }
  const buf = fs.readFileSync(p);
  const size = pngSize(buf);
  if (!size) {
    errors.push(`${r.name} is not a real PNG (extension lies)`);
    continue;
  }
  if (size.w !== r.w || size.h !== r.h) {
    errors.push(
      `${r.name} is ${size.w}x${size.h} but its name claims ${r.w}x${r.h} — wordpress.org will not display it`,
    );
    continue;
  }
  if (buf.length > r.max) {
    errors.push(`${r.name} is ${(buf.length / MB).toFixed(1)}MB, over the ${r.max / MB}MB cap`);
    continue;
  }
  ok.push(`${r.name.padEnd(22)} ${size.w}x${size.h}  ${(buf.length / 1024).toFixed(0)}KB`);
}

// ---------------------------------------------------------------------------
// 2. Screenshots must match the readme captions one-for-one, in order.
// ---------------------------------------------------------------------------
const readme = fs.readFileSync(README, "utf8");
const secMatch = readme.match(/^==\s*Screenshots\s*==\s*$([\s\S]*?)(?=^==\s)/m);
if (!secMatch) {
  errors.push("readme.txt has no `== Screenshots ==` section");
}
const captions = secMatch
  ? [...secMatch[1].matchAll(/^\s*(\d+)\.\s+(.+?)\s*$/gm)].map((m) => ({
      n: Number(m[1]),
      text: m[2],
    }))
  : [];

const shots = present
  .filter((f) => /^screenshot-/i.test(f))
  .map((f) => ({ file: f, n: Number((f.match(/^screenshot-(\d+)/i) || [])[1]) }));

if (captions.length && shots.length === 0) {
  errors.push(
    `readme.txt declares ${captions.length} screenshot caption(s) but this folder has NONE. ` +
      `Pushing now publishes ${captions.length} captions with no images.`,
  );
}

if (shots.length !== captions.length && shots.length > 0) {
  errors.push(`${shots.length} screenshot file(s) vs ${captions.length} caption(s) in readme.txt — must match`);
}

for (const c of captions) {
  const hit = shots.find((s) => s.n === c.n);
  if (!hit) {
    if (shots.length > 0) errors.push(`caption ${c.n} ("${c.text.slice(0, 45)}…") has no screenshot-${c.n}.png`);
    continue;
  }
  const p = path.join(HERE, hit.file);
  const buf = fs.readFileSync(p);
  // PNG only: the assets handbook allows png|jpg, the sample readme also
  // claims jpeg|gif. PNG is the intersection both agree on.
  if (!/\.png$/.test(hit.file)) {
    errors.push(`${hit.file} — use .png (the two official sources disagree on jpeg/gif)`);
    continue;
  }
  if (!pngSize(buf)) {
    errors.push(`${hit.file} is not a real PNG`);
    continue;
  }
  if (buf.length > 10 * MB) {
    errors.push(`${hit.file} is ${(buf.length / MB).toFixed(1)}MB, over the 10MB cap`);
    continue;
  }
  const s = pngSize(buf);
  ok.push(`${hit.file.padEnd(22)} ${s.w}x${s.h}  ${(buf.length / 1024).toFixed(0)}KB  "${c.text.slice(0, 40)}"`);
}

// ---------------------------------------------------------------------------
// 3. Filenames must be lowercase. Uppercase "won't work", silently.
//
//    Only for files that actually travel to SVN. README.md and verify.mjs live
//    here to explain and guard the folder and are never committed to
//    wordpress.org — the first run of this check failed on its own README.
// ---------------------------------------------------------------------------
const LOCAL_ONLY = new Set(["README.md", "verify.mjs"]);
for (const f of present) {
  if (LOCAL_ONLY.has(f)) continue;
  if (f !== f.toLowerCase()) errors.push(`${f} has uppercase characters — wordpress.org requires lowercase`);
}

// ---------------------------------------------------------------------------
// 4. The captions come from the TAGGED readme, not trunk.
//    If Stable Tag is 1.4.0 and /tags/1.4.0/ exists, nothing in trunk is read.
// ---------------------------------------------------------------------------
const stable = (readme.match(/^Stable tag:\s*(.+?)\s*$/mi) || [])[1];
const version = (fs.readFileSync(PLUGIN_PHP, "utf8").match(/^\s*\*?\s*Version:\s*(.+?)\s*$/mi) || [])[1];
if (!stable) {
  errors.push("readme.txt has no `Stable tag:` line");
} else if (stable !== version) {
  errors.push(
    `Stable tag is ${stable} but msh-seo.php Version is ${version}. ` +
      `Captions are read from /tags/${stable}/readme.txt — mismatch means the wrong readme wins.`,
  );
} else {
  ok.push(`stable tag ${stable} matches plugin version — captions read from /tags/${stable}/readme.txt`);
}

// ---------------------------------------------------------------------------
// 5. Anything unexpected in here gets committed too. Say so.
//    (blueprints/ is legitimate — it carries the Playground preview config.)
// ---------------------------------------------------------------------------
const expected = new Set([
  ...REQUIRED.map((r) => r.name),
  ...shots.map((s) => s.file),
  "README.md",
  "verify.mjs",
]);
for (const f of present) {
  if (!expected.has(f)) warnings.push(`${f} is not a recognised asset — it WILL be committed if you svn add *`);
}

// ---------------------------------------------------------------------------
console.log("WordPress.org /assets/ pre-flight\n");
for (const line of ok) console.log(`  ok    ${line}`);
for (const w of warnings) console.log(`  warn  ${w}`);
for (const e of errors) console.log(`  FAIL  ${e}`);

console.log(
  `\n${ok.length} ok, ${warnings.length} warning(s), ${errors.length} error(s)` +
    (errors.length ? "\n\nNOT ready to commit." : "\n\nReady to commit."),
);
process.exit(errors.length ? 1 : 0);
