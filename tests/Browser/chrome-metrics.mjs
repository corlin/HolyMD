import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const [chrome, fixturePath, widthText] = process.argv.slice(2);
const width = Number(widthText);
const phaseTimeout = Number(process.env.HOLYMD_BROWSER_TIMEOUT_MS ?? 15000);
if (!chrome || !fixturePath || !Number.isInteger(width) || !Number.isSafeInteger(phaseTimeout) || phaseTimeout < 100 || phaseTimeout > 2147483647) process.exit(2);
if (Number(process.versions.node.split('.')[0]) < 22 || typeof fetch !== 'function' || typeof WebSocket !== 'function') throw new Error('Node.js 22 or newer with global fetch and WebSocket is required.');

const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const withTimeout = (promise, label, milliseconds = phaseTimeout) => new Promise((resolve, reject) => {
  const timer = setTimeout(() => reject(new Error(`${label} timed out after ${milliseconds}ms.`)), milliseconds);
  promise.then((value) => { clearTimeout(timer); resolve(value); }, (error) => { clearTimeout(timer); reject(error); });
});

const connect = (url) => withTimeout(new Promise((resolve, reject) => {
  const socket = new WebSocket(url);
  socket.addEventListener('open', () => resolve(socket), { once: true });
  socket.addEventListener('error', () => reject(new Error(`WebSocket connection failed: ${url}`)), { once: true });
}), 'WebSocket connection');

const client = (socket) => {
  let nextId = 1;
  const pending = new Map();
  const events = new Map();
  socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
      const request = pending.get(message.id);
      pending.delete(message.id);
      message.error ? request.reject(new Error(message.error.message)) : request.resolve(message.result);
      return;
    }
    const listeners = events.get(message.method) ?? [];
    events.delete(message.method);
    listeners.forEach((listener) => listener(message.params));
  });
  socket.addEventListener('close', () => {
    pending.forEach(({ reject }) => reject(new Error('CDP connection closed.')));
    pending.clear();
  });
  return {
    send(method, params = {}, label = method) {
      const id = nextId++;
      const response = new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
      socket.send(JSON.stringify({ id, method, params }));
      return withTimeout(response, label);
    },
    once(method, label) {
      return withTimeout(new Promise((resolve) => events.set(method, [...(events.get(method) ?? []), resolve])), label);
    },
  };
};

const fetchJson = async (url, milliseconds = phaseTimeout, method = 'GET') => {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), milliseconds);
  try {
    const response = await withTimeout(fetch(url, { method, signal: controller.signal }), 'DevTools target request', milliseconds);
    if (!response.ok) throw new Error(`DevTools target request returned HTTP ${response.status}.`);
    return await withTimeout(response.json(), 'DevTools target response');
  } finally {
    clearTimeout(timer);
  }
};

const waitForExit = (browser, milliseconds) => {
  if (browser.exitCode !== null || browser.signalCode !== null) return Promise.resolve();
  return withTimeout(new Promise((resolve) => browser.once('exit', resolve)), 'Chrome process exit', milliseconds);
};

const profile = mkdtempSync(join(tmpdir(), 'holymd-chrome-'));
const chromeArguments = ['--headless=new', '--disable-gpu', '--disable-dev-shm-usage', '--disable-background-networking', '--remote-debugging-port=0', `--user-data-dir=${profile}`];
if (process.env.HOLYMD_CHROME_NO_SANDBOX === '1') chromeArguments.push('--no-sandbox');
const child = spawn(chrome, chromeArguments, { stdio: ['ignore', 'ignore', 'pipe'] });
let chromeStderr = '';
child.stderr.setEncoding('utf8');
child.stderr.on('data', (chunk) => { chromeStderr = (chromeStderr + chunk).slice(-12000); });
let socket;
let cdp;

try {
  const activePortPath = join(profile, 'DevToolsActivePort');
  const startupDeadline = Date.now() + phaseTimeout;
  while (!existsSync(activePortPath) && Date.now() < startupDeadline && child.exitCode === null) await delay(25);
  if (!existsSync(activePortPath)) throw new Error('Chrome startup timed out or exited before creating the DevTools endpoint.');
  const [port] = readFileSync(activePortPath, 'utf8').trim().split('\n');
  const endpointDeadline = Date.now() + phaseTimeout;
  let target;
  let endpointError;
  while (!target && Date.now() < endpointDeadline && child.exitCode === null) {
    try {
      const targets = await fetchJson(`http://127.0.0.1:${port}/json/list`, endpointDeadline - Date.now());
      target = targets.find((candidate) => candidate.type === 'page' && candidate.webSocketDebuggerUrl);
      if (!target) throw new Error('Chrome did not expose a page target.');
    } catch (error) {
      endpointError = error;
      await delay(25);
    }
  }
  if (!target) throw new Error(`DevTools endpoint readiness timed out: ${endpointError instanceof Error ? endpointError.message : 'Chrome exited'}`);
  socket = await connect(target.webSocketDebuggerUrl);
  cdp = client(socket);
  await cdp.send('Page.enable');
  await cdp.send('Emulation.setDeviceMetricsOverride', { width, height: 812, deviceScaleFactor: 1, mobile: false });
  const loaded = cdp.once('Page.loadEventFired', 'page load');
  await cdp.send('Page.navigate', { url: pathToFileURL(fixturePath).href });
  await loaded;
  const result = await cdp.send('Runtime.evaluate', {
    expression: 'new Promise((resolve) => { const poll = () => { const result = document.querySelector("#browser-result")?.textContent; result ? resolve(result) : setTimeout(poll, 10); }; poll(); })',
    awaitPromise: true,
    returnByValue: true,
  }, 'Runtime.evaluate');
  if (typeof result.result.value !== 'string') throw new Error('Browser fixture did not emit metrics.');
  process.stdout.write(result.result.value);
} catch (error) {
  const diagnostic = chromeStderr.trim();
  throw new Error(`${error instanceof Error ? error.message : String(error)}${diagnostic ? `\nChrome stderr:\n${diagnostic}` : ''}`);
} finally {
  if (cdp && socket?.readyState === WebSocket.OPEN) {
    try { await cdp.send('Browser.close', {}, 'Browser.close'); } catch {}
  }
  if (socket && socket.readyState < WebSocket.CLOSING) socket.close();
  try {
    await waitForExit(child, 2000);
  } catch {
    child.kill('SIGTERM');
    try {
      await waitForExit(child, 1000);
    } catch {
      child.kill('SIGKILL');
      try { await waitForExit(child, 1000); } catch {}
    }
  }
  rmSync(profile, { recursive: true, force: true });
}
