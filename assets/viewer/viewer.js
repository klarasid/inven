// In-page PDF viewer for the plugin's print previews (opened in SLiMS's colorbox popup).
// The PDF is fetched and drawn with PDF.js, so browser PDF settings, Adobe hand-offs and
// download managers cannot turn the preview into a download.
import * as pdfjs from './pdf.min.js';

pdfjs.GlobalWorkerOptions.workerSrc = new URL('./pdf.worker.min.js', import.meta.url).href;

const $ = (id) => document.getElementById(id);
const pages = $('pages');
const params = new URLSearchParams(location.search);
const title = params.get('title') || 'Pratinjau PDF';
$('title').textContent = title;
document.title = title;

let doc = null;
let blob = null;
let filename = 'dokumen.pdf';
let zoom = 1; // multiplier over "fit to width"
let rendering = 0;

function fail(message) {
  pages.innerHTML = '';
  const box = document.createElement('p');
  box.className = 'state error';
  box.textContent = message;
  pages.append(box);
}

function source() {
  const file = params.get('file');
  if (!file) throw new Error('Alamat dokumen tidak ada.');
  const url = new URL(file, location.href);
  // Only documents from this site: the viewer must not be usable to display outside files.
  if (url.origin !== location.origin) throw new Error('Dokumen harus berasal dari situs ini.');
  return url;
}

function fitScale(page) {
  const viewport = page.getViewport({ scale: 1 });
  const available = Math.max(200, pages.clientWidth - 32);
  return Math.min(available / viewport.width, 3) * zoom;
}

async function render() {
  const run = ++rendering;
  const first = await doc.getPage(1);
  const scale = fitScale(first);
  const ratio = window.devicePixelRatio || 1;
  const canvases = [];
  for (let n = 1; n <= doc.numPages; n++) {
    const page = n === 1 ? first : await doc.getPage(n);
    const viewport = page.getViewport({ scale });
    const canvas = document.createElement('canvas');
    canvas.width = Math.floor(viewport.width * ratio);
    canvas.height = Math.floor(viewport.height * ratio);
    canvas.style.width = Math.floor(viewport.width) + 'px';
    canvas.style.height = Math.floor(viewport.height) + 'px';
    canvas.setAttribute('aria-label', `Halaman ${n}`);
    await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : undefined }).promise;
    if (run !== rendering) return; // a newer zoom or resize superseded this pass
    canvases.push(canvas);
    if (n === 1) pages.replaceChildren(canvas);
    else pages.append(canvas);
  }
  $('zoom-fit').textContent = Math.round(zoom * 100) + '%';
}

/** Prints page images at about 300 dpi on paper sized like the document's first page. */
async function print() {
  const button = $('print');
  button.disabled = true;
  const label = button.querySelector('.label');
  const original = label.textContent;
  label.textContent = 'Menyiapkan…';
  try {
    const first = (await doc.getPage(1)).getViewport({ scale: 1 });
    const images = [];
    for (let n = 1; n <= doc.numPages; n++) {
      const page = await doc.getPage(n);
      const base = page.getViewport({ scale: 1 });
      const scale = Math.min(300 / 72, 4200 / Math.max(base.width, base.height));
      const viewport = page.getViewport({ scale });
      const canvas = document.createElement('canvas');
      canvas.width = Math.floor(viewport.width);
      canvas.height = Math.floor(viewport.height);
      await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport }).promise;
      images.push(await new Promise((resolve) => canvas.toBlob((b) => resolve(URL.createObjectURL(b)), 'image/png')));
    }
    const mm = (pt) => (pt * 25.4 / 72).toFixed(2) + 'mm';
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.append(frame);
    const d = frame.contentDocument;
    d.open();
    d.write('<!doctype html><html><head><title>' + title.replace(/</g, '&lt;') + '</title><style>'
      + '@page{size:' + mm(first.width) + ' ' + mm(first.height) + ';margin:0}'
      + 'html,body{margin:0;padding:0}img{display:block;width:' + mm(first.width) + ';height:' + mm(first.height) + ';page-break-after:always;break-after:page}'
      + 'img:last-child{page-break-after:auto;break-after:auto}</style></head><body>'
      + images.map((src) => '<img src="' + src + '">').join('') + '</body></html>');
    d.close();
    await Promise.all(Array.from(d.images, (img) => img.complete ? null : new Promise((r) => { img.onload = img.onerror = r; })));
    const cleanup = () => { images.forEach(URL.revokeObjectURL); frame.remove(); };
    frame.contentWindow.addEventListener('afterprint', () => setTimeout(cleanup, 500));
    frame.contentWindow.focus();
    frame.contentWindow.print();
  } catch (error) {
    $('info').textContent = 'Gagal mencetak: ' + error.message;
  } finally {
    label.textContent = original;
    button.disabled = false;
  }
}

function download() {
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  document.body.append(link);
  link.click();
  setTimeout(() => { URL.revokeObjectURL(link.href); link.remove(); }, 1000);
}

async function main() {
  let response;
  try {
    response = await fetch(source(), { credentials: 'same-origin', headers: { Accept: 'application/pdf' } });
  } catch (error) {
    fail(error.message.startsWith('Dokumen') || error.message.startsWith('Alamat') ? error.message : 'Dokumen tidak dapat dimuat. Periksa koneksi lalu coba lagi.');
    return;
  }
  const type = response.headers.get('content-type') || '';
  if (!response.ok || !type.includes('pdf')) {
    // Server refusals (session expired, limits, missing data) arrive as text or HTML; show their wording.
    const text = (await response.text()).replace(/<script[\s\S]*?<\/script>/gi, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    fail(text && text.length < 400 ? text : 'Dokumen tidak dapat dibuat. Muat ulang halaman atau masuk kembali ke SLiMS.');
    return;
  }
  const match = /filename="?([^";]+)"?/i.exec(response.headers.get('content-disposition') || '');
  if (match) filename = match[1];
  const bytes = await response.arrayBuffer();
  blob = new Blob([bytes], { type: 'application/pdf' });
  try {
    doc = await pdfjs.getDocument({ data: new Uint8Array(bytes) }).promise;
  } catch (error) {
    fail('Berkas PDF tidak dapat dibaca: ' + error.message);
    return;
  }
  $('info').textContent = doc.numPages + ' halaman';
  for (const id of ['zoom-out', 'zoom-fit', 'zoom-in', 'download', 'print']) $(id).disabled = false;
  await render();
}

$('zoom-in').addEventListener('click', () => { zoom = Math.min(4, zoom * 1.25); render(); });
$('zoom-out').addEventListener('click', () => { zoom = Math.max(0.25, zoom / 1.25); render(); });
$('zoom-fit').addEventListener('click', () => { zoom = 1; render(); });
$('print').addEventListener('click', print);
$('download').addEventListener('click', download);
let resizeTimer;
window.addEventListener('resize', () => { if (!doc) return; clearTimeout(resizeTimer); resizeTimer = setTimeout(render, 200); });

main().catch((error) => fail('Pratinjau gagal: ' + error.message));
