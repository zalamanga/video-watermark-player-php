export const STORAGE_KEY = 'video-library-pending-v1';
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);
const id = value => typeof value === 'string' && /^[a-f0-9]{32}$/.test(value);
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);

export function pending(value) {
  if (!object(value) || Object.keys(value).sort().join(',') !== 'action,key,operatorId,version,videoId' || value.version !== 1 || !id(value.operatorId) || !uuid(value.key)
    || !['upload', 'delete'].includes(value.action) || (value.action === 'upload' ? value.videoId !== null : !id(value.videoId))) throw new Error('Referensi lokal rusak. Data aslinya dipertahankan; tindakan baru ditahan.');
  return Object.freeze({ version: 1, operatorId: value.operatorId, key: value.key, action: value.action, videoId: value.videoId });
}

export function readPending(storage) {
  const raw = storage.getItem(STORAGE_KEY);
  if (raw === null) return null;
  if (raw.length > 512) throw new Error('Referensi lokal melampaui batas. Data tidak diganti otomatis.');
  try { return pending(JSON.parse(raw)); }
  catch { throw new Error('Referensi lokal rusak. Data aslinya dipertahankan; tindakan baru ditahan.'); }
}

export function remember(storage, value) {
  const record = pending(value), encoded = JSON.stringify(record), old = storage.getItem(STORAGE_KEY);
  if (old !== null && old !== encoded) throw new Error('Ada referensi lain yang belum selesai. Tidak ada operasi baru dikirim.');
  storage.setItem(STORAGE_KEY, encoded);
  if (storage.getItem(STORAGE_KEY) !== encoded) throw new Error('Penyimpanan referensi belum terkonfirmasi. Upload belum dikirim.');
  return record;
}

export function forget(storage, value) {
  const expected = JSON.stringify(pending(value));
  const current = storage.getItem(STORAGE_KEY);
  if (current === null) return;
  if (current !== expected) throw new Error('Referensi lokal berubah; data lain tidak dibersihkan.');
  storage.removeItem(STORAGE_KEY);
  if (storage.getItem(STORAGE_KEY) !== null) throw new Error('Pembersihan referensi lokal belum terkonfirmasi.');
}

export function confirmed(value, expected, fromReceipt = false) {
  const keys = ['saved', 'operationKey', 'action', 'videoId', 'committedAt', 'replayed', 'publicationWarning', 'cleanupPending', 'sessionWarning'];
  if (fromReceipt) keys.push('found');
  if (!object(value) || Object.keys(value).sort().join(',') !== keys.sort().join(',') || value.saved !== true || (fromReceipt && value.found !== true)
    || value.operationKey !== expected.key || value.action !== expected.action || !id(value.videoId) || (expected.videoId !== null && value.videoId !== expected.videoId)
    || !Number.isSafeInteger(value.committedAt) || value.committedAt < 1 || !['replayed', 'publicationWarning', 'cleanupPending', 'sessionWarning'].every(key => typeof value[key] === 'boolean')) throw new Error('Konfirmasi server belum cocok. Referensi yang sama tetap dipertahankan.');
  return value;
}

export function definiteRefusal(status, value, expected) {
  return Number.isInteger(status) && status >= 400 && status < 500 && status !== 408
    && object(value) && Object.keys(value).sort().join(',') === 'code,error,operationKey,outcomeUnknown'
    && typeof value.error === 'string' && value.error.length > 0 && value.error.length <= 1000
    && typeof value.code === 'string' && /^[a-z_]{1,64}$/.test(value.code)
    && value.outcomeUnknown === false && (value.operationKey === null || value.operationKey === expected.key);
}

function recovery(value) {
  if (object(value) && value.version === 2) {
    if (Object.keys(value).sort().join(',') !== 'record,refused,version' || value.refused !== true) throw new Error('Status referensi lokal rusak. Data tidak diganti otomatis.');
    return Object.freeze({ record: pending(value.record), refused: true });
  }
  return Object.freeze({ record: pending(value), refused: false });
}

export function readRecovery(storage) {
  const raw = storage.getItem(STORAGE_KEY);
  if (raw === null) return null;
  if (raw.length > 768) throw new Error('Referensi lokal melampaui batas. Data tidak diganti otomatis.');
  try { return recovery(JSON.parse(raw)); }
  catch { throw new Error('Status referensi lokal rusak. Data aslinya dipertahankan; tindakan baru ditahan.'); }
}

export function rememberRefusal(storage, value) {
  const record = pending(value), raw = storage.getItem(STORAGE_KEY);
  if (raw === null) return;
  const previous = raw.length <= 768 ? recovery(JSON.parse(raw)) : null;
  if (!previous || JSON.stringify(previous.record) !== JSON.stringify(record)) throw new Error('Referensi lokal berubah; status data lain tidak diganti.');
  const encoded = JSON.stringify({ version: 2, record, refused: true });
  storage.setItem(STORAGE_KEY, encoded);
  if (storage.getItem(STORAGE_KEY) !== encoded) throw new Error('Status penolakan di browser belum terkonfirmasi.');
}

export function forgetRecovery(storage, value) {
  const expected = JSON.stringify(pending(value)), raw = storage.getItem(STORAGE_KEY);
  if (raw === null) return;
  const current = raw.length <= 768 ? recovery(JSON.parse(raw)) : null;
  if (!current || JSON.stringify(current.record) !== expected) throw new Error('Referensi lokal berubah; data lain tidak dibersihkan.');
  storage.removeItem(STORAGE_KEY);
  if (storage.getItem(STORAGE_KEY) !== null) throw new Error('Pembersihan referensi lokal belum terkonfirmasi.');
}
