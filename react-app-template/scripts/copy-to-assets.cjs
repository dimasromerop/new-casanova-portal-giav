const fs = require('fs');
const path = require('path');

const here = __dirname; // react-app-template/scripts
const root = path.resolve(here, '..');
const dist = path.join(root, 'dist');
const pluginRoot = path.resolve(root, '..');
const assets = path.join(pluginRoot, 'assets');

function copyFile(src, dst) {
  if (!fs.existsSync(src)) return false;
  fs.mkdirSync(path.dirname(dst), { recursive: true });
  fs.copyFileSync(src, dst);
  return true;
}

const files = [
  ['portal-app.js', path.join(assets, 'portal-app.js')],
  ['portal-app.css', path.join(assets, 'portal-app.css')],
  ['index.html', path.join(assets, 'portal-app.index.html')],
];

let copied = 0;
for (const [name, dst] of files) {
  const src = path.join(dist, name);
  if (copyFile(src, dst)) copied++;
}

if (copied === 0) {
  console.error('[copy-to-assets] No files copied. Did you run `vite build`?');
  process.exit(1);
} else {
  console.log(`[copy-to-assets] Copied ${copied} file(s) to plugin assets/`);
}
