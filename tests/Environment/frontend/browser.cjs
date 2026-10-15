const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const net = require('node:net');

async function blocked(host, port) {
  return new Promise(resolve => {
    const socket = net.connect({ host, port });
    socket.setTimeout(1000);
    socket.on('connect', () => { socket.destroy(); resolve(false); });
    socket.on('error', () => resolve(true));
    socket.on('timeout', () => { socket.destroy(); resolve(true); });
  });
}

(async () => {
  assert(!process.env.HMS_HOST_SENTINEL, 'host environment leaked');
  for (const host of ['192.0.2.1', '203.0.113.1']) {
    assert(await blocked(host, 443), 'unexpected TCP egress');
    assert(await blocked(host, 1883), 'unexpected MQTT egress');
  }
  console.log(JSON.stringify({ check: 'frontend-tooling', node: process.version,
    playwright: require('@playwright/test/package.json').version,
    vue: require('vue/package.json').version, tcpEgress: 'blocked' }));
  const browser = await chromium.launch({ headless: true, chromiumSandbox: true });
  try {
    const context = await browser.newContext({ serviceWorkers: 'block', viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    await page.setContent('<button onclick="this.textContent=\'clicked\'">smoke</button>');
    await page.getByRole('button').click();
    assert.equal(await page.getByRole('button').textContent(), 'clicked');
    // No request interceptor here: exercise the actual network boundary, not a fake.
    const httpBlocked = await page.evaluate(async () => {
      try { await fetch('http://192.0.2.1/', { mode: 'no-cors', signal: AbortSignal.timeout(1500) }); return false; }
      catch { return true; }
    });
    assert(httpBlocked, 'browser HTTP egress succeeded');
    const websocketBlocked = await page.evaluate(() => new Promise(resolve => {
      const ws = new WebSocket('ws://192.0.2.1:1883/');
      const timer = setTimeout(() => { ws.close(); resolve(true); }, 1500);
      ws.onopen = () => { clearTimeout(timer); ws.close(); resolve(false); };
      ws.onerror = () => { clearTimeout(timer); resolve(true); };
    }));
    assert(websocketBlocked, 'browser websocket egress succeeded');
    console.log(JSON.stringify({ status: 'pass', browser: browser.version(), checks: ['mobile DOM', 'TCP/MQTT denial', 'browser HTTP/websocket denial'], scope: 'tooling fixture, not HMS browser journeys' }));
  } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
