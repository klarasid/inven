// Copies PDF.js into the static PDF viewer. Files get a .js extension so any server
// sends a JavaScript MIME type (module scripts and module workers require one).
import { copyFileSync, mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const from = resolve(here, '../node_modules/pdfjs-dist')
const to = resolve(here, '../../assets/viewer')
mkdirSync(to, { recursive: true })
for (const [source, target] of [
  ['build/pdf.min.mjs', 'pdf.min.js'],
  ['build/pdf.worker.min.mjs', 'pdf.worker.min.js'],
  ['LICENSE', 'PDFJS-LICENSE.txt'],
]) copyFileSync(resolve(from, source), resolve(to, target))
console.log('PDF.js copied to assets/viewer')
