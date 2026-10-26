import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const encode = source => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const operationURL = encode(await readFile(new URL('../public/assets/operation.js', import.meta.url), 'utf8'));
const controllerURL = encode((await readFile(new URL('../public/assets/operation-controller.js', import.meta.url), 'utf8')).replace("'./operation.js'", JSON.stringify(operationURL)));
const { bindOperations } = await import(controllerURL);
const { STORAGE_KEY, readRecovery } = await import(operationURL);
const key = '11111111-1111-4111-8111-111111111111', otherKey = '22222222-2222-4222-8222-222222222222';
const operatorId = 'a'.repeat(32), videoId = 'b'.repeat(32);
const metadata = (action = 'upload') => ({ version: 1, operatorId, key, action, videoId: action === 'delete' ? videoId : null });
const refusal = (operationKey = key) => ({ error: 'Permintaan ditolak.', code: 'invalid_request', outcomeUnknown: false, operationKey });
const success = () => ({ saved: true, operationKey: key, action: 'upload', videoId, committedAt: 123, replayed: false, publicationWarning: false, cleanupPending: false, sessionWarning: false });

class Element {
  constructor() { this.listeners = new Map(); this.hidden = true; this.disabled = false; this.textContent = ''; this.attributes = new Map(); }
  addEventListener(name, callback) { const callbacks = this.listeners.get(name) ?? []; callbacks.push(callback); this.listeners.set(name, callbacks); }
  removeEventListener(name, callback) { this.listeners.set(name, (this.listeners.get(name) ?? []).filter(value => value !== callback)); }
  async emit(name, values = {}) { for (const callback of [...(this.listeners.get(name) ?? [])]) await callback({ preventDefault() {}, ...values }); }
  setAttribute(name, value) { this.attributes.set(name, value); }
  removeAttribute(name) { this.attributes.delete(name); }
}
function fixture({ action = 'upload', initial = null, fresh = true, noForms = false, removeNoop = false, fetcher = async () => new Response(JSON.stringify({ found: false, operationKey: key })) } = {}) {
  let raw = initial, removals = 0, sends = 0, writes = 0, captures = 0;
  const storage = { getItem: () => raw, setItem: (_name, value) => { writes++; raw = value; }, removeItem: () => { removals++; if (!removeNoop) raw = null; } };
  const message = new Element(), check = new Element(), retry = new Element(), cleanup = new Element(), reenter = new Element(), events = new Element(), progress = new Element(), fieldset = new Element();
  const selector = { '[data-operation-message]': message, '[data-check-receipt]': check, '[data-retry]': retry, '[data-local-cleanup]': cleanup, '[data-reenter]': reenter };
  const region = { dataset: { operator: operatorId }, querySelector: name => selector[name] ?? null };
  const form = new Element(); form.dataset = { operation: action, freshOperation: String(fresh) }; form.action = action === 'upload' ? '/upload.php' : '/list.php';
  form.elements = { operation_key: { value: key }, video_id: { value: videoId } }; form.querySelector = name => name === 'fieldset' ? fieldset : null;
  const root = { querySelector: name => name === '#operation-state' ? region : name === '#upload-progress' ? progress : null, querySelectorAll: () => noForms ? [] : [form] };
  const requests = [];
  class XHR extends Element {
    constructor() { super(); this.upload = new Element(); requests.push(this); }
    open(method, url) { if (XHR.failOpen) throw new Error('injected setup failure'); this.method = method; this.url = url; }
    setRequestHeader(name, value) { this.requestHeader = [name, value]; }
    send(body) { sends++; this.sentBody = body; if (XHR.failSend) throw new Error('injected send failure'); }
    abort() { void this.emit('abort'); }
    async reply(status, body) { this.status = status; this.responseText = typeof body === 'string' ? body : JSON.stringify(body); await this.emit('load'); }
  }
  class Form { constructor(source) { captures++; this.entries = Object.fromEntries(Object.entries(source.elements).map(([name, input]) => [name, input.value])); } }
  let controller;
  const start = () => { controller = bindOperations({ root, storage: () => storage, fetcher, XHR, events, Form }); return controller; };
  return { root, region, storage, start, XHR, Form, form, fieldset, message, check, retry, cleanup, reenter, events, requests, progress, get raw() { return raw; }, get sends() { return sends; }, get removals() { return removals; }, get writes() { return writes; }, get captures() { return captures; }, close() { controller?.destroy(); } };
}

test('actual app entry binds submit and clears a typed refusal on a first fresh upload', async () => {
  const own = fixture(), originals = new Map();
  for (const [name, value] of Object.entries({ document: own.root, sessionStorage: own.storage, fetch: async () => { throw new Error('not requested'); }, XMLHttpRequest: own.XHR, window: own.events, FormData: own.Form })) {
    originals.set(name, Object.getOwnPropertyDescriptor(globalThis, name)); Object.defineProperty(globalThis, name, { configurable: true, writable: true, value });
  }
  try {
    const app = (await readFile(new URL('../public/assets/app.js', import.meta.url), 'utf8')).replace("'./operation-controller.js'", JSON.stringify(controllerURL));
    await import(encode(app)); await own.form.emit('submit');
    assert.equal(own.sends, 1); assert.equal(JSON.parse(own.raw).key, key);
    await own.requests[0].reply(415, refusal());
    assert.equal(own.raw, null); assert.equal(own.fieldset.disabled, true); assert.equal(own.retry.hidden, true);
    await own.form.emit('submit'); assert.equal(own.sends, 1);
  } finally { await own.events.emit('pagehide'); for (const [name, descriptor] of originals) { if (descriptor) Object.defineProperty(globalThis, name, descriptor); else delete globalThis[name]; } }
});

test('deleted-video first refusal survives remove denial and reload without a video form', async () => {
  const own = fixture({ action: 'delete', removeNoop: true }); own.start();
  try {
    await own.form.emit('submit'); await own.requests[0].reply(409, refusal());
    assert.equal(readRecovery(own.storage).refused, true); assert.equal(own.cleanup.hidden, false); assert.equal(own.sends, 1);
    const reloaded = fixture({ initial: own.raw, noForms: true }); reloaded.start();
    try { assert.equal(reloaded.cleanup.hidden, false); await reloaded.cleanup.emit('click'); assert.equal(reloaded.raw, null); assert.equal(reloaded.sends, 0); }
    finally { reloaded.close(); }
  } finally { own.close(); }
});

test('same-page retry after lost ACK never clears its key on a later auth refusal', async () => {
  const own = fixture(); own.start();
  try {
    await own.form.emit('submit'); const sent = own.requests[0].sentBody; await own.requests[0].emit('error'); const original = own.raw;
    await own.retry.emit('click'); assert.equal(own.requests[1].sentBody, sent); await own.requests[1].reply(403, refusal(null));
    assert.equal(own.raw, original); assert.equal(own.removals, 0); assert.equal(own.cleanup.hidden, true); assert.equal(own.retry.hidden, false);
  } finally { own.close(); }
});

test('cold upload recovery never captures replacement file or settings after missing receipt', async () => {
  const initial = JSON.stringify(metadata()), own = fixture({ initial }); own.start();
  try {
    own.form.elements.video = { value: Object.freeze({ name: 'replacement-b.mp4', synthetic: true }) };
    own.form.elements.watermark_text = { value: 'Replacement B' };
    assert.equal(own.fieldset.disabled, true); await own.check.emit('click'); assert.equal(own.fieldset.disabled, true);
    assert.equal(own.retry.hidden, true); assert.equal(own.reenter.hidden, false); assert.equal(own.reenter.href, `/upload.php?operation=${key}`);
    await own.form.emit('submit'); await own.retry.emit('click'); await own.cleanup.emit('click');
    assert.equal(own.captures, 0); assert.equal(own.requests.length, 0); assert.equal(own.sends, 0);
    assert.equal(own.raw, initial); assert.equal(own.removals, 0); assert.equal(own.writes, 0);
    assert.match(own.message.textContent, /hanya memeriksa receipt/);
  } finally { own.close(); }
});

test('a non-fresh reference form cannot capture or send an upload when storage is empty', async () => {
  const own = fixture({ fresh: false }); own.start();
  try {
    own.form.elements.video = { value: Object.freeze({ name: 'replacement.mp4', synthetic: true }) };
    await own.form.emit('submit'); await own.form.emit('submit'); await own.retry.emit('click');
    assert.equal(own.captures, 0); assert.equal(own.requests.length, 0); assert.equal(own.sends, 0);
    assert.equal(own.raw, null); assert.equal(own.removals, 0); assert.equal(own.writes, 0); assert.equal(own.fieldset.disabled, true);
  }
  finally { own.close(); }
});

test('a first auth refusal with exact null-key envelope can clear its fresh reference', async () => {
  const own = fixture(); own.start();
  try { await own.form.emit('submit'); await own.requests[0].reply(401, refusal(null)); assert.equal(own.raw, null); assert.equal(own.sends, 1); }
  finally { own.close(); }
});

test('bfcache return cannot reuse a form spent on a definite first refusal', async () => {
  const own = fixture(); own.start();
  try {
    await own.form.emit('submit'); await own.requests[0].reply(422, refusal()); assert.equal(own.raw, null);
    await own.events.emit('pagehide'); await own.events.emit('pageshow', { persisted: true }); await own.form.emit('submit');
    assert.equal(own.fieldset.disabled, true); assert.equal(own.sends, 1); assert.equal(own.raw, null);
  } finally { own.close(); }
});

test('ambiguous, oversized, mismatched and malformed responses keep the original key', async t => {
  const cases = [
    [503, refusal()], [408, refusal()], [422, { ...refusal(), outcomeUnknown: true }], [409, refusal(otherKey)],
    [415, { ...refusal(), extra: true }], [422, '{'], [200, 'x'.repeat(65537)], [200, { ...success(), operationKey: otherKey }],
  ];
  for (const [index, [status, body]] of cases.entries()) await t.test(`response boundary ${index + 1}`, async () => {
    const own = fixture(); own.start();
    try { await own.form.emit('submit'); const original = own.raw; await own.requests[0].reply(status, body); assert.equal(own.raw, original); assert.equal(own.removals, 0); assert.equal(own.cleanup.hidden, true); }
    finally { own.close(); }
  });
});

test('failure before XHR send begins is known unsent, a thrown send remains uncertain', async () => {
  const setup = fixture(); setup.XHR.failOpen = true; setup.start();
  try { await setup.form.emit('submit'); assert.equal(setup.sends, 0); assert.equal(setup.raw, null); }
  finally { setup.close(); }
  const send = fixture(); send.XHR.failSend = true; send.start();
  try { await send.form.emit('submit'); assert.equal(send.sends, 1); assert.equal(JSON.parse(send.raw).key, key); assert.equal(send.removals, 0); }
  finally { send.close(); }
});

test('foreign or corrupt metadata cannot be sent, queried, cleaned or overwritten', async t => {
  for (const initial of ['{', JSON.stringify({ ...metadata(), operatorId: 'c'.repeat(32) }), JSON.stringify({ version: 2, record: metadata(), refused: 'true' })]) await t.test('preserve opaque original', async () => {
    let reads = 0; const own = fixture({ initial, fetcher: async () => { reads++; throw new Error('must not read'); } }); own.start();
    try { await own.form.emit('submit'); await own.check.emit('click'); await own.cleanup.emit('click'); assert.equal(own.raw, initial); assert.equal(own.sends, 0); assert.equal(reads, 0); assert.equal(own.removals, 0); }
    finally { own.close(); }
  });
});

test('late replacement metadata is retained after the original fresh request is refused', async () => {
  const own = fixture(); own.start();
  try {
    await own.form.emit('submit'); const replacement = JSON.stringify({ ...metadata(), key: otherKey }); own.storage.setItem(STORAGE_KEY, replacement);
    await own.requests[0].reply(422, refusal()); await own.cleanup.emit('click');
    assert.equal(own.raw, replacement); assert.equal(own.removals, 0); assert.equal(own.sends, 1);
  } finally { own.close(); }
});

test('storage denial blocks sending and preserves the record that was actually written', async () => {
  const own = fixture(); let reads = 0; own.storage.getItem = () => { reads++; if (reads >= 3) throw new Error('injected readback denial'); return own.raw; }; own.start();
  try { await own.form.emit('submit'); assert.equal(own.sends, 0); assert.equal(JSON.parse(own.raw).key, key); }
  finally { own.close(); }
});

test('known saved cleanup failure keeps saved semantics and does not send replacement requests', async () => {
  const own = fixture({ removeNoop: true }); own.start();
  try {
    await own.form.emit('submit'); await own.requests[0].reply(201, success()); assert.equal(own.cleanup.hidden, false);
    await own.retry.emit('click'); await own.form.emit('submit'); await own.cleanup.emit('click'); assert.equal(own.sends, 1); assert.match(own.message.textContent, /tetap terkonfirmasi/);
  } finally { own.close(); }
});

test('pagehide aborts the owned XHR and late refusal cannot clear its retained key', async () => {
  const own = fixture(); own.start();
  try {
    await own.form.emit('submit'); const initial = own.raw; await own.events.emit('pagehide'); await own.requests[0].reply(409, refusal());
    assert.equal(own.raw, initial); assert.equal(own.removals, 0); await own.events.emit('pageshow', { persisted: true }); assert.equal(own.fieldset.disabled, true); assert.equal(own.sends, 1);
  } finally { own.close(); }
});

test('an aborted late receipt cannot unlock or mark a departed page saved', async () => {
  let deliver; const own = fixture({ initial: JSON.stringify(metadata()), fetcher: () => new Promise(resolve => { deliver = resolve; }) }); own.start();
  try {
    const request = own.check.emit('click'); await own.events.emit('pagehide'); deliver(new Response(JSON.stringify({ found: true, ...success() }))); await request;
    assert.equal(own.fieldset.disabled, true); assert.equal(own.removals, 0); assert.equal(own.sends, 0);
  } finally { own.close(); }
});

test('unknown missing-video recovery still exposes receipt checking without a replacement form', async () => {
  const initial = JSON.stringify(metadata('delete')), own = fixture({ initial, noForms: true }); own.start();
  try { await own.check.emit('click'); assert.equal(own.check.hidden, false); assert.equal(own.cleanup.hidden, true); assert.equal(own.raw, initial); assert.match(own.message.textContent, /tetap dapat diperiksa/); }
  finally { own.close(); }
});

test('same-lifecycle BFCache recovery retries the original body and form after edits and missing receipt', async () => {
  const own = fixture();
  const originalFile = Object.freeze({ name: 'original-a.mp4', synthetic: true });
  own.form.elements.video = { value: originalFile }; own.form.elements.watermark_text = { value: 'Watermark A' };
  own.start();
  try {
    await own.form.emit('submit');
    const payload = own.requests[0].sentBody, originalRaw = own.raw, writes = own.writes;
    await own.requests[0].emit('error');
    own.form.elements.video.value = Object.freeze({ name: 'edited-b.mp4', synthetic: true });
    own.form.elements.watermark_text.value = 'Watermark B';
    await own.events.emit('pagehide'); await own.events.emit('pageshow', { persisted: true });
    assert.equal(own.fieldset.disabled, true); assert.equal(own.retry.hidden, false); assert.equal(own.check.hidden, false); assert.equal(own.reenter.hidden, true);
    await own.check.emit('click');
    assert.equal(own.fieldset.disabled, true); await own.form.emit('submit');
    assert.equal(own.sends, 1); assert.equal(own.captures, 1);
    await own.retry.emit('click');
    assert.equal(own.sends, 2); assert.equal(own.requests[1].sentBody, payload);
    assert.equal(own.requests[1].url, '/upload.php');
    assert.equal(payload.entries.video, originalFile); assert.equal(payload.entries.watermark_text, 'Watermark A');
    assert.equal(payload.entries.operation_key, key); assert.equal(own.raw, originalRaw);
    assert.equal(own.writes, writes); assert.equal(own.removals, 0); assert.equal(own.captures, 1);
    await own.requests[1].reply(403, refusal(null)); assert.equal(own.raw, originalRaw); assert.equal(own.retry.hidden, false);
  } finally { own.close(); }
});

test('BFCache storage read denial retains the known body and reachable receipt/retry without rewriting storage', async () => {
  let receiptReads = 0;
  const own = fixture({ fetcher: async url => { receiptReads++; assert.equal(url, `/receipt.php?key=${key}`); return new Response(JSON.stringify({ found: false, operationKey: key })); } });
  own.start();
  try {
    await own.form.emit('submit'); const payload = own.requests[0].sentBody, originalRaw = own.raw, writes = own.writes;
    await own.requests[0].emit('error'); own.storage.getItem = () => { throw new Error('injected storage read denial'); };
    await own.events.emit('pagehide'); await own.events.emit('pageshow', { persisted: true });
    assert.equal(own.check.hidden, false); assert.equal(own.retry.hidden, false); assert.equal(own.fieldset.disabled, true);
    await own.check.emit('click'); assert.equal(receiptReads, 1); assert.equal(own.fieldset.disabled, true);
    await own.retry.emit('click'); assert.equal(own.requests[1].sentBody, payload);
    assert.equal(own.raw, originalRaw); assert.equal(own.writes, writes); assert.equal(own.removals, 0); assert.equal(own.captures, 1);
  } finally { own.close(); }
});

test('known RAM recovery never adopts or overwrites a replacement/absent/corrupt durable slot', async t => {
  const replacements = [null, '{', JSON.stringify({ ...metadata(), key: otherKey }), JSON.stringify({ ...metadata(), operatorId: 'c'.repeat(32) }), JSON.stringify({ version: 2, record: metadata(), refused: true })];
  for (const [index, replacement] of replacements.entries()) await t.test(`durable slot boundary ${index + 1}`, async () => {
    let receiptReads = 0;
    const own = fixture({ fetcher: async url => { receiptReads++; assert.equal(url, `/receipt.php?key=${key}`); return new Response(JSON.stringify({ found: false, operationKey: key })); } });
    own.start();
    try {
      await own.form.emit('submit'); const payload = own.requests[0].sentBody; await own.requests[0].emit('error');
      if (replacement === null) own.storage.removeItem(STORAGE_KEY); else own.storage.setItem(STORAGE_KEY, replacement);
      const writes = own.writes, removals = own.removals;
      await own.events.emit('pagehide'); await own.events.emit('pageshow', { persisted: true });
      assert.equal(own.check.hidden, false); assert.equal(own.retry.hidden, false); assert.equal(own.fieldset.disabled, true);
      await own.check.emit('click'); assert.equal(receiptReads, 1); assert.equal(own.fieldset.disabled, true);
      await own.form.emit('submit'); assert.equal(own.sends, 1);
      await own.retry.emit('click'); assert.equal(own.requests[1].sentBody, payload);
      assert.equal(own.raw, replacement); assert.equal(own.writes, writes); assert.equal(own.removals, removals); assert.equal(own.captures, 1);
      if (index === 2 || index === 3) {
        await own.requests[1].reply(201, success()); await own.cleanup.emit('click');
        assert.equal(own.raw, replacement); assert.equal(own.writes, writes); assert.equal(own.removals, removals);
      }
    } finally { own.close(); }
  });
});

test('a genuine displayed actor change releases old RAM but preserves durable identity and blocks foreign actions', async () => {
  let receiptReads = 0;
  const own = fixture({ fetcher: async () => { receiptReads++; assert.equal(own.region.dataset.operator, operatorId); return new Response(JSON.stringify({ found: false, operationKey: key })); } }); own.start();
  try {
    await own.form.emit('submit'); await own.requests[0].emit('error');
    const originalRaw = own.raw, writes = own.writes;
    own.region.dataset.operator = 'c'.repeat(32);
    await own.events.emit('pagehide'); await own.events.emit('pageshow', { persisted: true });
    assert.equal(own.retry.hidden, true); assert.equal(own.check.hidden, true); assert.equal(own.cleanup.hidden, true); assert.equal(own.fieldset.disabled, true);
    await own.retry.emit('click'); await own.check.emit('click'); await own.cleanup.emit('click'); await own.form.emit('submit');
    assert.equal(own.sends, 1); assert.equal(receiptReads, 0); assert.equal(own.raw, originalRaw); assert.equal(own.writes, writes); assert.equal(own.removals, 0);
    own.region.dataset.operator = operatorId; await own.events.emit('pageshow', { persisted: true });
    assert.equal(own.retry.hidden, true); assert.equal(own.check.hidden, false); assert.equal(own.fieldset.disabled, true); assert.equal(own.captures, 1);
    await own.check.emit('click'); await own.form.emit('submit'); await own.retry.emit('click');
    assert.equal(receiptReads, 1); assert.equal(own.fieldset.disabled, true); assert.equal(own.retry.hidden, true);
    assert.equal(own.captures, 1); assert.equal(own.sends, 1); assert.equal(own.raw, originalRaw); assert.equal(own.writes, writes); assert.equal(own.removals, 0);
  } finally { own.close(); }
});

test('retry also checks displayed actor provenance before any XHR construction', async () => {
  const own = fixture(); own.start();
  try {
    await own.form.emit('submit'); await own.requests[0].emit('error'); const originalRaw = own.raw;
    own.region.dataset.operator = 'c'.repeat(32); await own.retry.emit('click');
    assert.equal(own.sends, 1); assert.equal(own.requests.length, 1); assert.equal(own.retry.hidden, true); assert.equal(own.raw, originalRaw);
    own.region.dataset.operator = operatorId; await own.events.emit('pageshow', { persisted: true }); assert.equal(own.retry.hidden, true);
  } finally { own.close(); }
});

test('body-less upload without any form stays receipt-only while missing and cannot be discarded', async () => {
  const initial = JSON.stringify(metadata()), own = fixture({ initial, noForms: true }); own.start();
  try {
    await own.check.emit('click'); await own.retry.emit('click'); await own.cleanup.emit('click');
    assert.equal(own.check.hidden, false); assert.equal(own.retry.hidden, true); assert.equal(own.cleanup.hidden, true);
    assert.equal(own.reenter.href, `/upload.php?operation=${key}`); assert.equal(own.reenter.hidden, false);
    assert.equal(own.raw, initial); assert.equal(own.captures, 0); assert.equal(own.requests.length, 0); assert.equal(own.sends, 0); assert.equal(own.writes, 0); assert.equal(own.removals, 0);
    assert.match(own.message.textContent, /hanya memeriksa receipt/);
  } finally { own.close(); }
});

test('cold upload can confirm its existing key receipt and clean the exact slot without an upload form', async () => {
  const initial = JSON.stringify(metadata());
  const own = fixture({ initial, noForms: true, fetcher: async () => new Response(JSON.stringify({ found: true, ...success() })) }); own.start();
  try {
    await own.check.emit('click');
    assert.equal(own.raw, null); assert.equal(own.removals, 1); assert.equal(own.writes, 0);
    assert.equal(own.captures, 0); assert.equal(own.requests.length, 0); assert.equal(own.sends, 0);
    assert.equal(own.check.hidden, true); assert.equal(own.retry.hidden, true); assert.equal(own.reenter.hidden, true);
    assert.match(own.message.textContent, /terkonfirmasi/);
  } finally { own.close(); }
});

test('cold delete recovery still captures the same target and a later refusal stays unknown', async () => {
  const initial = JSON.stringify(metadata('delete')), own = fixture({ initial, action: 'delete' }); own.start();
  try {
    assert.equal(own.fieldset.disabled, true); await own.check.emit('click'); assert.equal(own.fieldset.disabled, false);
    await own.form.emit('submit'); assert.equal(own.sends, 1); assert.equal(own.captures, 1);
    assert.equal(own.requests[0].sentBody.entries.operation_key, key); assert.equal(own.requests[0].sentBody.entries.video_id, videoId);
    await own.requests[0].reply(409, refusal());
    assert.equal(own.raw, initial); assert.equal(own.removals, 0); assert.equal(own.cleanup.hidden, true); assert.equal(own.retry.hidden, false);
  } finally { own.close(); }
});
