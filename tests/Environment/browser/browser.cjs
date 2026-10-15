// Only the guarded browser profile loads the real browser library.
require('./guard.cjs').preflight();
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');

module.exports = async function browserTooling() {
  const browser = await chromium.launch({ headless: true, chromiumSandbox: true });
  const viewports = [{ width: 1280, height: 800 }, { width: 390, height: 844 }];
  if (Number(process.env.HMS_TEST_CYCLE) % 2 === 0) viewports.reverse();
  const completed = [];
  try {
    for (const viewport of viewports) {
      const context = await browser.newContext({ serviceWorkers: 'block', viewport });
      try {
        const page = await context.newPage();
        await page.setContent('<button onclick="this.textContent=\'clicked\'">smoke</button>');
        await page.getByRole('button').click();
        assert.equal(await page.getByRole('button').textContent(), 'clicked');
        // No request interceptor: these requests exercise the actual network boundary.
        assert(await page.evaluate(async () => {
          try { await fetch('http://192.0.2.1/', { mode: 'no-cors', signal: AbortSignal.timeout(1500) }); return false; }
          catch { return true; }
        }), 'browser HTTP egress succeeded');
        assert(await page.evaluate(() => new Promise(resolve => {
          const ws = new WebSocket('ws://192.0.2.1:1883/');
          const timer = setTimeout(() => { ws.close(); resolve(true); }, 1500);
          ws.onopen = () => { clearTimeout(timer); ws.close(); resolve(false); };
          ws.onerror = () => { clearTimeout(timer); resolve(true); };
        })), 'browser websocket egress succeeded');
        completed.push({ viewport, checks: ['DOM click', 'real HTTP denial', 'real websocket denial'] });
      } finally { await context.close(); }
    }
    const report = { status: 'pass', node: process.version, browser: browser.version(),
      playwright: require('@playwright/test/package.json').version,
      scope: 'tooling fixture; actual HMS integration journeys pending 2F', completed };
    console.log(JSON.stringify(report));
    return report;
  } finally { await browser.close(); }
};
