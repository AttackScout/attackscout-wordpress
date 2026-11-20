// Takes screenshot-1.png (dashboard widget) and screenshot-2.png (Settings >
// AttackScout) on a throwaway WordPress (container "as-wp-web" on a Docker
// network, plugin installed from build/attackscout.zip, a test key, and an
// example summary stored in the "attackscout_last_scan" option). Not shipped.
// Run with the Playwright image, /work mounted on the output folder:
//   node screenshots.js    (output in /work/out)
const { chromium } = require('/tmp/node_modules/playwright');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
  const base = 'http://as-wp-web';
  await p.goto(base + '/wp-login.php');
  await p.fill('#user_login', 'admin');
  await p.fill('#user_pass', 'jetable-Admin-1');
  await p.click('#wp-submit');
  await p.waitForLoadState('networkidle');
  await p.goto(base + '/wp-admin/index.php');
  await p.waitForLoadState('networkidle');
  const w = p.locator('#attackscout_dashboard');
  await w.scrollIntoViewIfNeeded();
  await w.screenshot({ path: '/work/out/screenshot-1.png' });
  await p.goto(base + '/wp-admin/options-general.php?page=attackscout');
  await p.waitForLoadState('networkidle');
  await p.locator('.wrap.attackscout').screenshot({ path: '/work/out/screenshot-2.png' });
  await b.close();
})();
