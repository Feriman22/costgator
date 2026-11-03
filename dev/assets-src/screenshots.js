const { chromium } = require('playwright-core');
const fs = require('fs');
const out = process.argv[2];
const pw = fs.readFileSync(__dirname + '/../../.env', 'utf8').match(/WP_ADMIN_PASSWORD=(\S+)/)[1];
const base = 'http://' + (fs.readFileSync(__dirname + '/../../.env', 'utf8').match(/WP_BIND=(\S+)/) || [, '127.0.0.1'])[1] + ':8088';
// Clip the union of the given elements' boxes, with padding, from a full page screenshot.
async function shoot(page, file, first, last) {
  await page.addStyleTag({ content: '#wpadminbar{display:none!important} html.wp-toolbar{padding-top:0!important}' });
  const a = await page.locator(first).first().boundingBox();
  const b = await page.locator(last).first().boundingBox();
  const wrap = await page.locator('.costgator-wrap').boundingBox();
  const pad = 16;
  const top = 10;
  const clip = { x: wrap.x - pad, y: a.y - top, width: wrap.width + 2 * pad, height: b.y + b.height - a.y + top + pad };
  await page.screenshot({ path: out + '/' + file, clip, fullPage: true });
}
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(base + '/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', pw);
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
  await page.goto(base + '/wp-admin/admin.php?page=costgator');
  await page.click('#costgator-scan');
  await page.waitForNavigation({ timeout: 60000 });
  await page.waitForLoadState('networkidle');
  // The finding categories are <details> disclosures, open the ones each screenshot shows.
  const open = ids => page.evaluate(list => list.forEach(id => { const e = document.getElementById(id); if (e) e.open = true; }), ids);
  await open(['costgator-future', 'costgator-low']);
  await shoot(page, 'screenshot-2.png', '.costgator-wrap h1', '#costgator-low');
  await open(['costgator-coupons', 'costgator-missing']);
  await shoot(page, 'screenshot-3.png', '#costgator-coupons', '#costgator-missing');
  await page.goto(base + '/wp-admin/admin.php?page=costgator&preflight=25&basis=regular');
  await page.waitForLoadState('networkidle');
  await shoot(page, 'screenshot-1.png', '#costgator-preflight', '#costgator-preflight');
  console.log('js errors:', JSON.stringify(errors));
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
