// Test-owned standard-library guard. Do not load Jest/Playwright before preflight.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const net = require('node:net');
const dns = require('node:dns').promises;
const { spawnSync } = require('node:child_process');

function preflight() {
  assert(['frontend', 'browser'].includes(process.env.HMS_TEST_PROFILE), 'unknown test profile');
  assert.equal(process.env.APP_ENV, 'testing', 'production configuration rejected');
  for (const name of ['HMS_HOST_SENTINEL', 'DB_HOST', 'DB_URL', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY',
    'AWS_SESSION_TOKEN', 'GOOGLE_APPLICATION_CREDENTIALS', 'IDENTITY_ENDPOINT', 'GITHUB_TOKEN',
    'SSH_AUTH_SOCK', 'COMPOSER_AUTH', 'FONTAWESOME_TOKEN', 'STRIPE_SECRET', 'DISCORD_TOKEN',
    'MAILGUN_SECRET', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy']) {
    assert(!process.env[name], 'unexpected credential/destination: ' + name);
  }
  assert(!fs.existsSync('/tmp/hms-cached-config'), 'cached configuration rejected');
  assert(!fs.existsSync('/tools/.env') && !fs.existsSync('/var/run/docker.sock'), 'host configuration exposed');
  let writable = false;
  try { fs.accessSync('/tools/guard.cjs', fs.constants.W_OK); writable = true; } catch {}
  assert(!writable, 'prepared test source is writable');
}

function blocked(host, port) {
  return new Promise(resolve => {
    const socket = net.connect({ host, port });
    socket.setTimeout(600);
    socket.on('connect', () => { socket.destroy(); resolve(false); });
    socket.on('error', () => { socket.destroy(); resolve(true); });
    socket.on('timeout', () => { socket.destroy(); resolve(true); });
  });
}

async function isolation() {
  const routes = fs.readFileSync('/proc/net/route', 'utf8').trim().split('\n').slice(1);
  assert(routes.every(line => line.trim().split(/\s+/)[1] !== '00000000'), 'unexpected default route');
  for (const host of ['192.0.2.1', '203.0.113.1']) {
    for (const port of [443, 1883, 25]) assert(await blocked(host, port), 'unexpected egress');
  }
  assert(net.isIP(process.env.CANARY_IP), 'missing canary target');
  assert(await blocked(process.env.CANARY_IP, 18080), 'escaped to separate canary bridge');
  let resolved = false;
  try { await dns.lookup('example.com'); resolved = true; } catch {}
  assert(!resolved, 'external DNS unexpectedly resolved');
  const code = `const net=require('node:net');if(process.env.HMS_HOST_SENTINEL)process.exit(2);
    const s=net.connect({host:'192.0.2.1',port:1883});s.setTimeout(600);
    s.on('connect',()=>{s.destroy();process.exit(1)});s.on('error',()=>process.exit(0));s.on('timeout',()=>{s.destroy();process.exit(0)});`;
  const child = spawnSync(process.execPath, ['-e', code], { env: process.env, timeout: 3000 });
  assert.equal(child.status, 0, 'child process escaped isolation');
  console.log(JSON.stringify({ status: 'pass', node: process.version, checks: ['no default route',
    'TCP/SMTP/MQTT denial', 'separate canary bridge denied', 'DNS denied', 'child isolation'] }));
}

function emit(name, content) {
  const text = typeof content === 'string' ? content : JSON.stringify(content, null, 2);
  assert(Buffer.byteLength(text) <= 16 * 1024 * 1024, 'oversize report');
  console.log('HMS_ARTIFACT:' + name + ':' + Buffer.from(text).toString('base64'));
}

function escape(value) {
  return String(value).replace(/[<>&"']/g, char => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&apos;'}[char]));
}

function junit(name, cases) {
  const failed = cases.filter(c => c.status !== 'passed').length;
  return `<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="${escape(name)}" tests="${cases.length}" failures="${failed}" skipped="0">` +
    cases.map(c => `<testcase classname="${escape(name)}" name="${escape(c.name)}">` +
      (c.status === 'passed' ? '' : `<failure message="check failed">${escape(c.message || c.status)}</failure>`) + '</testcase>').join('') +
    '</testsuite></testsuites>';
}

function component() {
  const order = Number(process.env.HMS_TEST_CYCLE) % 2 === 0 ? ['--randomize'] : [];
  // A fresh Node process and Jest environment for each repeat; no application package scripts.
  const result = spawnSync(process.execPath, ['/tools/node_modules/jest/bin/jest.js', '--runInBand', '--no-cache',
    '--json', '--outputFile=/tmp/jest-results.json', ...order, '--seed=42'], {
      cwd: '/tools', env: process.env, encoding: 'utf8', timeout: 90000,
    });
  process.stdout.write(result.stdout || '');
  process.stderr.write(result.stderr || '');
  if (!fs.existsSync('/tmp/jest-results.json')) throw new Error('Jest did not emit results');
  const report = JSON.parse(fs.readFileSync('/tmp/jest-results.json', 'utf8'));
  const cases = report.testResults.flatMap(suite => suite.assertionResults.map(test => ({
    name: test.fullName, status: test.status, message: test.failureMessages.join('\n'),
  })));
  emit('jest.json', report);
  emit('junit.xml', junit('frontend-tooling', cases));
  assert.equal(result.status, 0, 'Jest failed');
  assert(cases.length > 0 && cases.every(c => c.status === 'passed'), 'empty/nonpassing Jest suite');
}

async function main(mode = process.argv[2]) {
  try { preflight(); } catch (error) { console.error(error.message); process.exitCode = 78; return; }
  try {
    if (mode === 'preflight') return;
    if (mode === 'isolation') return await isolation();
    if (mode === 'component') return component();
    if (mode === 'browser') {
      const cases = [];
      try {
        const report = await require('./browser.cjs')();
        cases.push({ name: 'Chromium desktop/mobile DOM and actual HTTP/websocket denial', status: 'passed' });
        emit('browser.json', report);
      } catch (error) {
        cases.push({ name: 'Chromium tooling', status: 'failed', message: error.message });
        emit('browser.json', { status: 'failed', error: error.message });
        throw error;
      } finally { emit('junit.xml', junit('browser-tooling', cases)); }
      return;
    }
    throw new Error('unknown guarded mode');
  } catch (error) { console.error(error.message); process.exitCode = 1; }
}

module.exports = { main, preflight };
if (require.main === module) main();
