import { bindOperations } from './operation-controller.js';

const preview = document.querySelector('#preview-text');
if (preview) {
  const text = document.querySelector('#watermark-text'), size = document.querySelector('#watermark-size'), opacity = document.querySelector('#watermark-opacity'), position = document.querySelector('#watermark-position');
  const render = () => {
    preview.textContent = text.value; preview.style.fontSize = `${Number(size.value)}px`; preview.style.opacity = String(Number(opacity.value) / 100);
    document.querySelector('#preview-full-text').textContent = text.value === '' ? '(teks kosong)' : text.value;
    document.querySelector('#watermark-preview').dataset.position = position.value;
    document.querySelector('#size-output').textContent = `${size.value}px`; document.querySelector('#opacity-output').textContent = `${opacity.value}%`;
  };
  for (const field of [text, size, opacity, position]) field.addEventListener('input', render);
  render();
}

const fullscreen = document.querySelector('#fullscreen'), frame = document.querySelector('#video-frame'), player = document.querySelector('#player'), playerStatus = document.querySelector('#player-status');
if (fullscreen && frame?.requestFullscreen) {
  fullscreen.hidden = false;
  fullscreen.addEventListener('click', async () => {
    try { await frame.requestFullscreen(); }
    catch { playerStatus.textContent = 'Fullscreen wadah tidak tersedia saat ini. Video tetap dapat diputar di halaman.'; }
  });
}
player?.addEventListener('error', () => { playerStatus.textContent = 'Browser belum dapat membaca video. Codec mungkin tidak didukung, atau sesi/token sudah berakhir. Buka ulang player; binary tidak dikonversi otomatis.'; });

bindOperations({ root: document, storage: () => sessionStorage, fetcher: fetch, XHR: XMLHttpRequest, events: window, Form: FormData });
