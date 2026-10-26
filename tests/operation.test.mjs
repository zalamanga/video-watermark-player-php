import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
const source = await readFile(new URL('../public/assets/operation.js', import.meta.url), 'utf8');
const { pending, readPending, remember, forget, confirmed, STORAGE_KEY } = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const record = () => pending({ version: 1, operatorId: 'a'.repeat(32), key: '11111111-1111-4111-8111-111111111111', action: 'upload', videoId: null });
function storage() { let raw = null; return { getItem: () => raw, setItem: (_key, value) => { raw = value; }, removeItem: () => { raw = null; } }; }
test('corrupt and unsupported pending records are preserved rather than replaced', () => {
  const adapter = storage(); adapter.setItem(STORAGE_KEY, '{'); assert.throws(() => readPending(adapter)); assert.equal(adapter.getItem(STORAGE_KEY), '{'); assert.throws(() => remember(adapter, record())); assert.equal(adapter.getItem(STORAGE_KEY), '{');
});
test('metadata must survive exact immediate readback before a caller may send', () => {
  assert.throws(() => remember({ getItem: () => null, setItem() {} }, record()));
  const adapter = storage(); assert.deepEqual(remember(adapter, record()), record()); assert.deepEqual(readPending(adapter), record());
});
test('readback denial after set retains its actual written reference and reports failure', () => {
  let written, reads = 0; const adapter = { getItem() { if (++reads > 1) throw new Error('denied'); return null; }, setItem(_key, raw) { written = raw; } };
  assert.throws(() => remember(adapter, record())); assert.equal(JSON.parse(written).key, record().key);
});
test('cleanup never removes another record and no-op removal remains a cleanup failure', () => {
  const adapter = storage(); remember(adapter, record()); assert.throws(() => forget({ ...adapter, removeItem() {} }, record()));
  adapter.setItem(STORAGE_KEY, 'changed'); assert.throws(() => forget(adapter, record())); assert.equal(adapter.getItem(STORAGE_KEY), 'changed');
});
test('only matching typed committed receipt metadata can confirm the operation', () => {
  const value = { saved: true, operationKey: record().key, action: 'upload', videoId: 'b'.repeat(32), committedAt: 123, replayed: false, publicationWarning: false, cleanupPending: false, sessionWarning: false };
  assert.equal(confirmed(value, record()), value); assert.throws(() => confirmed({ ...value, operationKey: 'wrong' }, record())); assert.throws(() => confirmed({ ...value, saved: 'true' }, record())); assert.throws(() => confirmed({ ...value, committedAt: null }, record()));
  assert.equal(confirmed({ ...value, sessionWarning: true }, record()).saved, true);
  assert.throws(() => confirmed({ ...value, sessionWarning: 'true' }, record()));
});
