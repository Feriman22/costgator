// Records the README demo: scan the store, browse the results, check a 25% promotion.
// Writes lossless frames to <out>/frames and their timing to <out>/frames.txt. Convert with demo-gif.sh.
// Seed the demo store first (wp eval-file /dev-scripts/demo-seed.php), then run with playwright-core on
// NODE_PATH and CHROME_PATH set: node dev/assets-src/demo-gif.js <out> && bash dev/assets-src/demo-gif.sh <out>
const { chromium } = require('playwright-core');
const fs = require('fs');
const out = process.argv[2];
const env = fs.readFileSync(__dirname + '/../../.env', 'utf8');
const pw = env.match(/WP_ADMIN_PASSWORD=(\S+)/)[1];
const base = 'http://' + (env.match(/WP_BIND=(\S+)/) || [, '127.0.0.1'])[1] + ':8088';
const size = { width: 1100, height: 700 };

// Hide the admin chrome before the first paint and draw a cursor, since the screencast does not show the real one.
const STYLE = `
#wpadminbar, #adminmenumain, #wpfooter, .notice { display: none !important; }
html.wp-toolbar { padding-top: 0 !important; }
html { scrollbar-width: none; }
#costgator-preflight { scroll-margin-top: 20px; }
#wpcontent, #wpfooter { margin-left: 0 !important; }
#demo-cursor { position: fixed; z-index: 99999; width: 22px; height: 22px; pointer-events: none;
  background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 22 22'%3E%3Cpath d='M3 2l15 9-7 1.5L7.5 19z' fill='%23111' stroke='%23fff' stroke-width='1.5'/%3E%3C/svg%3E"); }
`;
const CURSOR = `
addEventListener('DOMContentLoaded', () => {
  const c = document.createElement('div'); c.id = 'demo-cursor';
  const p = JSON.parse(sessionStorage.getItem('demo-cursor') || '[-50,-50]');
  c.style.left = p[0] + 'px'; c.style.top = p[1] + 'px';
  document.body.appendChild(c);
  addEventListener('mousemove', e => { c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px';
    sessionStorage.setItem('demo-cursor', JSON.stringify([e.clientX, e.clientY])); });
});
`;

let mouse = { x: 500, y: 60 };
async function moveTo(page, locator) {
  const box = await locator.boundingBox();
  const to = { x: box.x + box.width / 2, y: box.y + box.height / 2 };
  await page.mouse.move(to.x, to.y, { steps: 25 });
  mouse = to;
}
async function scrollTo(page, y, ms) {
  await page.evaluate(([y, ms]) => new Promise(done => {
    const from = scrollY, t0 = performance.now();
    const step = t => { const k = Math.min(1, (t - t0) / ms); scrollTo(0, from + (y - from) * (1 - Math.cos(k * Math.PI)) / 2); k < 1 ? requestAnimationFrame(step) : done(); };
    requestAnimationFrame(step);
  }), [y, ms]);
}
async function prepare(page) {
  await page.mouse.move(mouse.x, mouse.y);
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
  const login = await browser.newPage();
  await login.goto(base + '/wp-login.php');
  await login.fill('#user_login', 'admin');
  await login.fill('#user_pass', pw);
  await login.click('#wp-submit');
  await login.waitForLoadState('networkidle');
  const state = await login.context().storageState();
  await login.close();

  const context = await browser.newContext({ viewport: size, storageState: state });
  await context.addInitScript(css => {
    const style = document.createElement('style');
    style.textContent = css;
    (function add() { document.documentElement ? document.documentElement.appendChild(style) : requestAnimationFrame(add); })();
  }, STYLE);
  await context.addInitScript(CURSOR);
  const page = await context.newPage();

  // The screencast sends a PNG only when the screen changes, so static moments cost nothing in the GIF.
  fs.mkdirSync(out + '/frames', { recursive: true });
  const frames = [];
  const cdp = await context.newCDPSession(page);
  cdp.on('Page.screencastFrame', f => {
    const file = 'frames/' + String(frames.length).padStart(5, '0') + '.png';
    fs.writeFileSync(out + '/' + file, Buffer.from(f.data, 'base64'));
    frames.push({ file, t: f.metadata.timestamp });
    cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }).catch(() => {});
  });
  // Start after the first load, otherwise the GIF opens with a blank white page.
  await page.goto(base + '/wp-admin/admin.php?page=costgator');
  await page.waitForLoadState('networkidle');
  await prepare(page);
  await cdp.send('Page.startScreencast', { format: 'png', maxWidth: size.width, maxHeight: size.height });
  const started = Date.now();
  await page.waitForTimeout(1200);

  await moveTo(page, page.locator('#costgator-scan'));
  await page.waitForTimeout(300);
  await Promise.all([page.waitForNavigation({ timeout: 60000 }), page.click('#costgator-scan')]);
  await page.waitForLoadState('networkidle');
  await prepare(page);
  await page.evaluate(() => scrollTo(0, 0));
  await page.waitForTimeout(2500);

  const y = async sel => page.locator(sel).first().evaluate(e => e.getBoundingClientRect().top + scrollY - 20);
  await scrollTo(page, await y('.costgator-section'), 1200);
  await page.waitForTimeout(2200);
  await scrollTo(page, await y('#costgator-preflight'), 1800);
  await page.waitForTimeout(800);

  const input = page.locator('#costgator-preflight input[name="preflight"]');
  await moveTo(page, input);
  await input.click({ clickCount: 3 });
  await page.keyboard.type('25', { delay: 180 });
  await page.waitForTimeout(400);
  await page.locator('#costgator-preflight select').selectOption('regular');
  await page.waitForTimeout(600);
  await moveTo(page, page.locator('#costgator-preflight [type="submit"]'));
  await page.waitForTimeout(300);
  await Promise.all([page.waitForNavigation(), page.click('#costgator-preflight [type="submit"]')]);
  await page.waitForLoadState('networkidle');
  await prepare(page);
  await page.waitForTimeout(4000);

  await cdp.send('Page.stopScreencast');
  console.log('seconds:', ((Date.now() - started) / 1000).toFixed(1), 'frames:', frames.length);
  // ffconcat list: each frame lasts until the next one, the last one is held for 2 seconds.
  const list = ['ffconcat version 1.0'];
  frames.forEach((f, i) => list.push('file ' + f.file, 'duration ' + ((i + 1 < frames.length ? frames[i + 1].t : f.t + 2) - f.t).toFixed(3)));
  list.push('file ' + frames[frames.length - 1].file);
  fs.writeFileSync(out + '/frames.txt', list.join('\n') + '\n');
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
