// Captures the README screenshots of a running Forever Tracker with headless Chrome or Edge.
// Node 22+ (built-in WebSocket), no package needed.
//
//   node tools/screenshots.mjs [base url] [output folder]
//
//   FT_SHOT_LOGIN / FT_SHOT_PASSWORD   administrator used for the Forge screenshots (optional)
//   FT_BROWSER                          path of chrome.exe / msedge.exe / chromium
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const base = (process.argv[2] || 'http://127.0.0.1:8090/').replace(/\/?$/, '/');
const out = process.argv[3] || 'docs/screenshots';
const port = 9333;
const candidates = [process.env.FT_BROWSER, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].filter(Boolean);
const browserPath = candidates.find((p) => existsSync(p));
if (!browserPath) { console.error('No Chrome or Edge found: set FT_BROWSER.'); process.exit(1); }
mkdirSync(out, { recursive: true });

const browser = spawn(browserPath, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), 'ft-shot-'))}`,
  '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', '--window-size=1440,900', 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let target;
for (let i = 0; i < 50 && !target; i++) {
  try { target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === 'page'); } catch { await sleep(200); }
}
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r, { once: true }));
let seq = 0;
const pending = new Map();
const waiters = [];
ws.addEventListener('message', (event) => {
  const msg = JSON.parse(event.data);
  if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); }
  else if (msg.method) waiters.filter((w) => w.method === msg.method).forEach((w) => { waiters.splice(waiters.indexOf(w), 1); w.resolve(msg.params); });
});
const send = (method, params = {}) => new Promise((resolve) => { const id = ++seq; pending.set(id, resolve); ws.send(JSON.stringify({ id, method, params })); });
const once = (method) => new Promise((resolve) => waiters.push({ method, resolve }));
const evaluate = async (expression) => (await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result?.result?.value;

await send('Page.enable');
await send('Runtime.enable');
async function viewport(width, height, mobile = false) {
  await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: mobile ? 2 : 1, mobile });
}
async function go(path, settle = 900) {
  const loaded = once('Page.loadEventFired');
  await send('Page.navigate', { url: new URL(path, base).href });
  await loaded;
  await evaluate('document.fonts ? document.fonts.ready.then(() => 1) : 1');
  await sleep(settle);
}
async function shot(name) {
  const { result } = await send('Page.captureScreenshot', { format: 'png' });
  writeFileSync(join(out, name + '.png'), Buffer.from(result.data, 'base64'));
  console.log('saved', name);
}
// Replaces this computer's folders with example paths before a capture (the wizard and the spawn dialog show them).
async function mask() {
  await evaluate(`(() => {
    const rules = [[/[A-Z]:[\\\\/][^\\s·]*?[\\\\/]server(?=[\\s·]|$)/gi, 'C:/WoW/Server'], [/[A-Z]:[\\\\/][^\\s·]*?Client[^\\s·]*?(?=[\\\\/]_|[\\s·]|$)/gi, 'C:/Games/WoW Forever'],
      [/[A-Z]:[\\\\/][^\\s·]*?[\\\\/](data|mysql)(?=[\\\\/])/gi, 'C:/WoW/$1'], [/[A-Z]:[\\\\/]WoWForever[^\\s·]*/gi, 'C:/WoW']];
    const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let node; (node = walk.nextNode());) { let t = node.nodeValue; for (const [re, to] of rules) t = t.replace(re, to); node.nodeValue = t; }
    document.querySelectorAll('.choice-list').forEach((list) => [...list.children].slice(1).forEach((c) => c.remove()));
    const servers = [...document.querySelectorAll('[data-checks] li')].find((li) => /server\\(s\\) found/.test(li.textContent));
    if (servers) { servers.querySelector('strong').textContent = '1 server(s) found'; servers.querySelector('small').textContent = 'C:/WoW/Server'; }
    return 1;
  })()`);
}
async function hover(selector) {
  const box = await evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return null; el.scrollIntoView({block: 'center'}); const r = el.getBoundingClientRect(); return {x: r.left + r.width / 2, y: r.top + r.height / 2}; })()`);
  if (!box) return;
  await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: box.x - 30, y: box.y - 30 });
  await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: box.x, y: box.y });
  await sleep(1200);
}

await viewport(1440, 900);
await go('?lang=en');
await evaluate("localStorage.removeItem('ft-theme'); document.documentElement.dataset.theme = 'dark'");
await go('?lang=en');
await shot('overview');
await go('items?sub=4&lang=en');
await hover('tbody tr:nth-child(2) .name');
await shot('items-tooltip');
await go('spells?sub=class&new=1&lang=en');
await hover('tbody tr:nth-child(3) .name');
await shot('spells-tooltip');
await go('item/19019?lang=en');
await shot('item');
await go('changes?lang=en');
await shot('changelog');
await go('todo?lang=en');
await shot('todo');
await go('?lang=en');
await evaluate("document.querySelector('[data-palette-open]').click(); const i = document.querySelector('#palette input'); i.value = 'thunder'; i.dispatchEvent(new Event('input'));");
await sleep(1200);
await shot('search');

if (process.env.FT_SHOT_LOGIN && process.env.FT_SHOT_PASSWORD) {
  await go('login?lang=en');
  const submitted = once('Page.loadEventFired');
  await evaluate(`document.querySelector('[name=login]').value = ${JSON.stringify(process.env.FT_SHOT_LOGIN)};
    document.querySelector('[name=password]').value = ${JSON.stringify(process.env.FT_SHOT_PASSWORD)}; document.querySelector('.login form').submit();`);
  await submitted;
  await go('admin?tab=trainer&new=1&lang=en');
  await evaluate("[...document.querySelectorAll('[data-pick]')].slice(0, 3).forEach((b) => b.click())");
  await sleep(500);
  await shot('forge');
  await evaluate("document.querySelector('[data-forge-preview-selection]').click()");
  await sleep(1500);
  await shot('forge-sql');
  await go('dungeon/2921?lang=en');
  await shot('dungeon-forge');
  // items nobody can get, and the imports page
  await go('admin?tab=vendor&lang=en');
  await mask();
  await shot('forge-items');
  await go('admin/imports?lang=en');
  await mask();
  await shot('imports');
  // spawns (locator results) and the spawn dialog
  await go('admin/spawns?lang=en');
  await shot('spawns');
  await evaluate("document.querySelector('[data-spawn-all]').click(); document.querySelector('[data-spawn-open]').click()");
  await sleep(5000);
  await mask();
  await shot('spawns-dialog');
  // setup wizard: welcome (detection) and databases
  await go('setup?lang=en', 12000);
  await mask();
  await shot('setup');
  await evaluate("document.querySelector('[data-step=\"0\"] [data-next]').click(); document.querySelector('[data-step=\"1\"] [data-next]').click()");
  await sleep(3500);
  await mask();
  await shot('setup-database');
}

await evaluate("localStorage.setItem('ft-theme', 'light')");
await go('item/19019?lang=fr');
await shot('item-light-fr');
await evaluate("localStorage.removeItem('ft-theme')");
await viewport(390, 844, true);
await go('?lang=en');
await shot('mobile-overview');
await go('quests?status=todo&lang=en');
await shot('mobile-quests');

ws.close();
browser.kill();
process.exit(0);
