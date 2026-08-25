const fs = require('fs');
const path = require('path');
const archiver = require('archiver');

const pluginDir = path.join(__dirname, 'msh-seo');

// The FIRST output is the one customers actually download: the dashboard serves
// /msh-seo-plugin.zip straight from its public folder. This script used to write
// only into this directory, so the served file was updated by hand — and on
// 2026-08-25 it was found three releases behind the code running on every live
// site, missing the health beacon entirely. A build that writes somewhere other
// than the place the file is served from will always drift eventually.
const configs = [
  { out: path.join('..', 'dashboard', 'public', 'msh-seo-plugin.zip'), prefix: 'msh-seo' },
  { out: 'msh-seo-flat.zip', prefix: '' },
];

const skipDirs = ['node_modules', 'src', 'tests'];
const skipFiles = [
  'package-lock.json',
  'package.json',
  'webpack.config.js',
  '.gitignore',
];

function addDir(archive, dirPath, zipPath) {
  const entries = fs.readdirSync(dirPath, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = path.join(dirPath, entry.name);
    const entryZipPath = zipPath ? `${zipPath}/${entry.name}` : entry.name;
    if (entry.isDirectory()) {
      if (skipDirs.includes(entry.name)) continue;
      addDir(archive, fullPath, entryZipPath);
    } else {
      if (skipFiles.includes(entry.name)) continue;
      archive.file(fullPath, { name: entryZipPath });
    }
  }
}

// Version markers must agree before anything is packaged.
//
// WordPress reads the HEADER, the code reads the CONSTANT, and the updater reads
// readme.txt's Stable tag. On 2026-08-25 the constant said 1.1.0 while the header
// and readme still said 1.0.5, so WordPress would have shown — and offered
// updates against — a version the code had already left behind.
{
  const main = fs.readFileSync(path.join(pluginDir, 'msh-seo.php'), 'utf8');
  const readme = fs.readFileSync(path.join(pluginDir, 'readme.txt'), 'utf8');
  const header = (main.match(/^\s*\*\s*Version:\s*(.+)$/m) || [])[1];
  const constant = (main.match(/MSH_SEO_VERSION',\s*'([^']+)'/) || [])[1];
  const stable = (readme.match(/Stable tag:\s*(.+)/) || [])[1];
  const seen = [header, constant, stable].map((v) => (v || '').trim());
  if (new Set(seen).size !== 1) {
    console.error(`REFUSING TO BUILD — version markers disagree: header=${seen[0]} constant=${seen[1]} readme=${seen[2]}`);
    process.exit(1);
  }
  console.log(`Version ${seen[0]} — header, constant and readme agree.`);
}

let completed = 0;
for (const cfg of configs) {
  const outPath = path.join(__dirname, cfg.out);
  if (fs.existsSync(outPath)) fs.unlinkSync(outPath);

  const output = fs.createWriteStream(outPath);
  const archive = archiver('zip', { zlib: { level: 9 } });

  output.on('close', () => {
    console.log(`Created: ${cfg.out} (${Math.round(archive.pointer() / 1024)}KB)`);
    completed++;
    if (completed === configs.length) {
      console.log('Done. dashboard/public/msh-seo-plugin.zip is the file the app serves.');
    }
  });

  archive.on('error', (err) => { throw err; });
  archive.pipe(output);
  addDir(archive, pluginDir, cfg.prefix);
  archive.finalize();
}
