// Draws the images of the WordPress.org listing (icons and banners) from the
// logo of docs/marque, with Playwright. Not shipped in the plugin.
//
//   docker run --rm --ipc=host -v "$PWD/../..:/repo:ro" -v "$PWD/assets:/out" \
//     mcr.microsoft.com/playwright:v1.64.1-noble \
//     bash -c "cd /tmp && npm i playwright@1.64.1 --no-audit --no-fund && node /repo/integrations/wordpress/bin/assets/render.js"
//
// (from integrations/wordpress). Writes icon-128x128.png, icon-256x256.png,
// banner-772x250.png and banner-1544x500.png into /out.

const { chromium } = require('/tmp/node_modules/playwright');
const path = require('path');
const fs = require('fs');

const HERE = '/repo/integrations/wordpress/bin/assets';
const LOGO = '/repo/docs/marque/logo-attackscout-400.png';

(async () => {
  const browser = await chromium.launch();

  // Icons: the logo of docs/marque, scaled down.
  for (const size of [128, 256]) {
    const page = await browser.newPage({ viewport: { width: size, height: size }, deviceScaleFactor: 1 });
    const html = `/tmp/icon-${size}.html`;
    fs.writeFileSync(html, `<style>html,body{margin:0}img{display:block;width:${size}px;height:${size}px}</style><img src="file://${LOGO}">`);
    await page.goto('file://' + html);
    await page.waitForLoadState('load');
    await page.screenshot({ path: `/out/icon-${size}x${size}.png`, omitBackground: false });
    await page.close();
  }

  // Banners: 772x250, and the same at double density (1544x500).
  for (const scale of [1, 2]) {
    const page = await browser.newPage({ viewport: { width: 772, height: 250 }, deviceScaleFactor: scale });
    await page.goto('file://' + path.join(HERE, 'banner.html'));
    await page.waitForLoadState('load');
    await page.screenshot({ path: `/out/banner-${772 * scale}x${250 * scale}.png` });
    await page.close();
  }

  await browser.close();
})();
