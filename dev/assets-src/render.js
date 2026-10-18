const { chromium } = require('playwright-core');
const dir = process.argv[2];
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
  for (const [w, scale, name] of [[1544, 1, 'banner-1544x500.png'], [1544, 0.5, 'banner-772x250.png']]) {
    const page = await browser.newPage({ viewport: { width: 1544, height: 500 }, deviceScaleFactor: scale });
    await page.goto('file://' + dir + '/banner.html');
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);
    await page.locator('#banner').screenshot({ path: dir + '/' + name });
    await page.close();
  }
  for (const size of [128, 256]) {
    const page = await browser.newPage({ viewport: { width: size, height: size } });
    await page.goto('file://' + dir + '/icon.svg');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: `${dir}/icon-${size}x${size}.png`, omitBackground: true });
    await page.close();
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
