import { pending, remember, confirmed, definiteRefusal, readRecovery, rememberRefusal, forgetRecovery } from './operation.js';

export function bindOperations({ root, storage, fetcher, XHR, events, Form }) {
  const region = root.querySelector('#operation-state');
  if (!region) return { destroy() {} };
  const forms = [...root.querySelectorAll('form[data-operation]')], message = region.querySelector('[data-operation-message]');
  const check = region.querySelector('[data-check-receipt]'), retry = region.querySelector('[data-retry]'), cleanup = region.querySelector('[data-local-cleanup]'), reenter = region.querySelector('[data-reenter]');
  const progress = root.querySelector('#upload-progress'), usedKeys = new Set(), listeners = [];
  let record = null, body = null, activeForm = null, xhr = null, saved = false, refused = false, reading = false, blocked = false, readController = null, ticket = 0, priorUnknown = true, destroyed = false;
  const on = (target, name, callback) => { target.addEventListener(name, callback); listeners.push(() => target.removeEventListener(name, callback)); };
  const fields = disable => { for (const form of forms) form.querySelector('fieldset').disabled = disable; };
  const announce = text => { message.textContent = text; };
  const recoverable = () => {
    const foreign = record !== null && record.operatorId !== region.dataset.operator;
    check.hidden = record === null || saved || refused || foreign;
    retry.hidden = record === null || saved || refused || foreign || body === null || activeForm === null;
    cleanup.hidden = record === null || (!saved && !refused) || foreign;
    reenter.hidden = record === null || saved || refused || foreign || record.action !== 'upload' || body !== null;
    if (!reenter.hidden) reenter.href = `/upload.php?operation=${encodeURIComponent(record.key)}`;
  };
  function showFailure(error) {
    announce(`${error instanceof Error ? error.message : 'Hasil belum terkonfirmasi.'} ${record ? `Referensi ${record.key}. Periksa hasil sebelum mencoba lagi.` : 'Tidak ada operasi baru yang dikirim.'}`);
    recoverable();
  }
  function restore() {
    priorUnknown = true;
    if (record !== null) {
      blocked = true; fields(true);
      if (record.operatorId !== region.dataset.operator) {
        body = null; activeForm = null; saved = false; refused = false;
        showFailure(new Error('Operator berubah. Isi pengiriman lama dilepas dari halaman; referensinya tidak dihapus atau dikirim dengan sesi ini.'));
        return;
      }
      try {
        const restored = readRecovery(storage());
        if (restored === null) throw new Error('Referensi penyimpanan tidak ditemukan. Referensi halaman dipertahankan; periksa hasil sebelum memuat ulang.');
        if (JSON.stringify(restored.record) !== JSON.stringify(record) || restored.refused) throw new Error('Status penyimpanan berbeda dari pengiriman halaman ini. Data penyimpanan tidak diganti; referensi halaman tetap belum pasti.');
        announce(`Referensi ${record.key} masih belum pasti. ${body !== null ? 'Isi pengiriman asli dipertahankan; periksa receipt atau ulangi isi yang sama tanpa mengubah formulir.' : 'Isi pengiriman tidak tersedia di memori halaman ini. Periksa receipt; tidak ada pengiriman otomatis.'}`);
        recoverable();
      } catch (error) { showFailure(error); }
      return;
    }
    body = null; activeForm = null; saved = false; refused = false;
    try {
      const restored = readRecovery(storage());
      record = restored?.record ?? null; refused = restored?.refused ?? false;
      if (record !== null && record.operatorId !== region.dataset.operator) { refused = false; throw new Error('Referensi milik operator lain dipertahankan dan tidak dikirim dengan sesi ini.'); }
      blocked = record !== null; fields(blocked);
      if (refused) announce(`Browser mencatat penolakan pengiriman pertama referensi ${record.key}. Bersihkan referensi lokal, lalu buka formulir baru; tidak ada pengiriman otomatis.`);
      else if (record) announce(record.action === 'upload'
        ? `Referensi ${record.key} belum dibersihkan. Isi upload asli tidak tersedia di memori halaman ini. Pemulihan hanya memeriksa receipt; tidak ada upload pengganti.`
        : `Referensi ${record.key} belum dibersihkan. Periksa receipt; tidak ada pengiriman otomatis.`);
      recoverable();
    } catch (error) { blocked = true; fields(true); showFailure(error); }
  }
  function cleanLocal() {
    if (!record || record.operatorId !== region.dataset.operator || (!saved && !refused)) return;
    try {
      forgetRecovery(storage(), record); cleanup.hidden = true;
      announce(saved ? 'Hasil server tetap terkonfirmasi. Referensi browser sudah dibersihkan. Buka halaman baru untuk operasi berikutnya.' : 'Penolakan pengiriman pertama telah dicatat. Referensi browser sudah dibersihkan. Buka halaman baru untuk formulir dan key baru.');
    } catch {
      cleanup.hidden = false;
      announce(saved ? 'Hasil server tetap terkonfirmasi; pembersihan referensi browser belum berhasil.' : 'Pengiriman pertama ditolak; pembersihan referensi browser belum berhasil. Tidak ada operasi pengganti yang dikirim.');
    }
  }
  function markRefused(value) {
    refused = true; blocked = true; body = null; fields(true);
    announce(`${value.error} Pengiriman pertama referensi ${record.key} ditolak sebelum perubahan library. Buka formulir baru untuk key baru.`);
    recoverable();
    try { forgetRecovery(storage(), record); cleanup.hidden = true; }
    catch {
      let durable = false;
      try { rememberRefusal(storage(), record); durable = true; } catch { /* A denied storage write cannot prove durable recovery metadata. */ }
      cleanup.hidden = false;
      announce(`${message.textContent} Pembersihan browser belum selesai.${durable ? ' Status penolakan tersimpan untuk pembersihan setelah reload.' : ' Status penolakan belum tersimpan; selesaikan pembersihan di halaman ini. Jika halaman dimuat ulang, referensi diperlakukan belum pasti dan hanya receipt yang dapat mengonfirmasi hasil.'}`);
    }
  }
  function markConfirmed(value) {
    saved = true; refused = false; blocked = true; fields(true);
    announce(`Operasi referensi ${record.key} terkonfirmasi.${value.cleanupPending ? ' Penghapusan dari library sudah disimpan; pembersihan binary di disk masih tertunda.' : ''}${value.publicationWarning ? ' Isi penyimpanan telah dikonfirmasi melalui pembacaan ulang.' : ''}${value.sessionWarning ? ' Penyimpanan session belum terkonfirmasi; masuk ulang sebelum tindakan berikutnya.' : ''} Buka library untuk keadaan terbaru.`);
    recoverable();
    try { forgetRecovery(storage(), record); cleanup.hidden = true; }
    catch { cleanup.hidden = false; announce(`${message.textContent} Pembersihan referensi browser belum selesai; jangan mengirim operasi baru sebagai pengganti.`); }
  }
  async function checkReceipt() {
    if (!record || record.operatorId !== region.dataset.operator || reading || xhr || saved || refused || destroyed) return;
    reading = true; check.disabled = true;
    const captured = record, ownTicket = ++ticket;
    const abort = new AbortController(), timer = setTimeout(() => abort.abort(), 15000); readController = abort;
    try {
      const response = await fetcher(`/receipt.php?key=${encodeURIComponent(record.key)}`, { cache: 'no-store', redirect: 'error', signal: abort.signal });
      if (!response.ok || !response.body) throw new Error('Receipt belum dapat diperiksa. Referensi tidak dihapus.');
      const reader = response.body.getReader(), chunks = []; let total = 0;
      try { while (true) { const part = await reader.read(); if (part.done) break; total += part.value.length; if (total > 65536) throw new Error('Respons receipt melampaui batas.'); chunks.push(part.value); } }
      finally { if (abort.signal.aborted) void reader.cancel().catch(() => {}); try { reader.releaseLock(); } catch { /* The bounded fetch abort owns an unfinished read. */ } }
      const bytes = new Uint8Array(total); let offset = 0; for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length; }
      if (destroyed || ownTicket !== ticket || captured !== record || abort.signal.aborted) return;
      const result = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
      if (result?.found === false && result.operationKey === record.key && Object.keys(result).length === 2) {
        announce(`Referensi ${record.key} belum ditemukan. Ini belum membuktikan operasi tidak pernah tersimpan.`);
        if (record.action === 'upload') {
          blocked = true; fields(true);
          if (body !== null && activeForm !== null) announce(`${message.textContent} Isi pengiriman asli masih tersedia di halaman ini. Gunakan Ulangi isi yang sama.`);
          else { activeForm = null; announce(`${message.textContent} Isi asli tidak tersedia di memori halaman ini. Pemulihan hanya memeriksa receipt; tidak ada upload pengganti dengan referensi ini.`); }
        } else {
          const form = forms.find(form => form.dataset.operation === 'delete' && form.elements.video_id.value === record.videoId);
          if (form) {
            activeForm = form; form.elements.operation_key.value = record.key;
            if (!body) { form.querySelector('fieldset').disabled = false; blocked = false; }
          } else announce(`${message.textContent} Video tidak ada pada formulir aktif. Referensi tetap dapat diperiksa dari halaman ini; jangan menggantinya dengan operasi baru.`);
        }
        recoverable();
      } else markConfirmed(confirmed(result, record, true));
    } catch (error) { if (!destroyed && ownTicket === ticket && captured === record) showFailure(error); }
    finally { clearTimeout(timer); abort.abort(); if (ownTicket === ticket) { readController = null; reading = false; check.disabled = false; } }
  }
  function send() {
    if (record !== null && record.operatorId !== region.dataset.operator) {
      body = null; activeForm = null; blocked = true; fields(true);
      showFailure(new Error('Operator berubah. Isi pengiriman lama dilepas; referensinya tidak dikirim atau diganti.'));
      return;
    }
    if (!record || !body || !activeForm || xhr || reading || saved || refused || destroyed) return;
    const captured = record, form = activeForm, payload = body, firstFresh = !priorUnknown, ownTicket = ++ticket;
    fields(true); blocked = true; recoverable(); retry.hidden = true;
    let sending;
    try {
      sending = new XHR(); xhr = sending;
      sending.open('POST', form.action); sending.timeout = 120000; sending.setRequestHeader('Accept', 'application/json');
    } catch {
      xhr = null;
      if (firstFresh) markRefused({ error: 'Pengiriman belum dimulai karena browser tidak dapat menyiapkan permintaan.' });
      else showFailure(new Error('Pengiriman ulang belum dimulai. Hasil pengiriman terdahulu tetap belum pasti.'));
      return;
    }
    const current = () => !destroyed && ownTicket === ticket && captured === record && xhr === sending;
    const finish = () => { xhr = null; form.removeAttribute('aria-busy'); if (progress) progress.hidden = true; };
    if (progress) { progress.hidden = false; progress.value = 0; }
    sending.upload.addEventListener('progress', event => { if (current() && progress && event.lengthComputable) progress.value = event.loaded / event.total * 100; });
    sending.addEventListener('load', () => {
      if (!current()) return;
      finish();
      try {
        if (sending.responseText.length > 65536) throw new Error('Respons upload melampaui batas.');
        const value = JSON.parse(sending.responseText);
        if (sending.status < 200 || sending.status >= 300) {
          if (firstFresh && definiteRefusal(sending.status, value, captured)) { markRefused(value); return; }
          throw new Error(typeof value?.error === 'string' && value.error.length <= 1000 ? value.error : 'Server belum mengonfirmasi operasi.');
        }
        markConfirmed(confirmed(value, captured));
      } catch (error) { showFailure(error); }
    });
    for (const name of ['error', 'timeout', 'abort']) sending.addEventListener(name, () => {
      if (!current()) return;
      finish(); showFailure(new Error('Koneksi terputus atau batas dua menit tercapai; hasil server belum pasti.'));
    });
    form.setAttribute('aria-busy', 'true'); announce(`Mengirim referensi ${record.key}. Formulir dikunci sampai hasil diketahui.`);
    priorUnknown = true;
    try { sending.send(payload); }
    catch { if (current()) { finish(); showFailure(new Error('Browser belum mengonfirmasi pengiriman; hasil server belum pasti.')); } }
  }
  for (const form of forms) on(form, 'submit', event => {
    event.preventDefault();
    if (xhr || reading || saved || refused || blocked || destroyed) return;
    try {
      if (form.dataset.operation === 'upload' && (record !== null || form.dataset.freshOperation !== 'true' || usedKeys.has(form.elements.operation_key.value))) throw new Error('Upload ini bukan pengiriman pertama dari formulir baru. Referensi lama hanya dapat diperiksa; isi pengiriman tidak ditangkap ulang.');
      const next = pending({ version: 1, operatorId: region.dataset.operator, key: form.elements.operation_key.value, action: form.dataset.operation, videoId: form.dataset.operation === 'delete' ? form.elements.video_id.value : null });
      if (record !== null && JSON.stringify(record) !== JSON.stringify(next)) throw new Error('Referensi lain masih aktif. Tidak ada operasi baru dikirim.');
      const captured = new Form(form), fresh = record === null && form.dataset.freshOperation === 'true' && !usedKeys.has(next.key);
      record = remember(storage(), next); activeForm = form; body = captured; priorUnknown = !fresh;
      usedKeys.add(next.key); send();
    } catch (error) { blocked = true; fields(true); showFailure(error); }
  });
  on(check, 'click', checkReceipt); on(retry, 'click', send); on(cleanup, 'click', cleanLocal);
  const cancel = () => {
    ticket++; readController?.abort(); readController = null; reading = false;
    const previous = xhr; xhr = null; previous?.abort(); activeForm?.removeAttribute('aria-busy');
    if (progress) progress.hidden = true;
  };
  on(events, 'pagehide', cancel);
  on(events, 'pageshow', event => { if (event.persisted && !saved && !refused) { check.disabled = false; restore(); } });
  restore();
  return { destroy() { if (destroyed) return; destroyed = true; cancel(); for (const remove of listeners) remove(); } };
}
