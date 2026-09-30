import React from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { App } from './App'
import { PortalContext } from './context'
import type { Config } from './types'
import './index.css'

interface Runtime { scan: () => void; dispose: () => void }
declare global { interface Window { inventoryRuntimes?: Record<string, Runtime> } }

// SLiMS admin swaps pages over AJAX without reloading, so every visit re-runs this bundle.
// Runtimes are keyed by build version (the ?v= of this script): a new build takes over its own hosts
// and retires older runtimes instead of deferring to whichever build ran first in the tab.
const version = (() => {
  try { return new URL((document.currentScript as HTMLScriptElement).src).searchParams.get('v') || 'dev' } catch { return 'dev' }
})()
const runtimes = (window.inventoryRuntimes ??= {})

if (runtimes[version]) runtimes[version].scan()
else {
  for (const [key, old] of Object.entries(runtimes)) { old.dispose(); delete runtimes[key] }
  const roots = new Map<HTMLElement, Root>()
  const scan = () => {
    for (const [host, root] of roots) if (!host.isConnected) { root.unmount(); roots.delete(host) }
    document.querySelectorAll<HTMLElement>(`[data-inventory-app][data-version="${CSS.escape(version)}"]`).forEach(host => {
      if (roots.has(host) || host.shadowRoot) return
      try {
        const config = JSON.parse(host.dataset.config!) as Config
        const shadow = host.attachShadow({ mode: 'open' }); host.textContent = ''
        const stylesheet = document.createElement('link'); stylesheet.rel = 'stylesheet'; stylesheet.href = host.dataset.css!
        shadow.append(stylesheet)
        const mount = document.createElement('div'); const portal = document.createElement('div'); portal.dataset.inventoryPortals = ''; shadow.append(mount, portal)
        const root = createRoot(mount); roots.set(host, root)
        root.render(<PortalContext.Provider value={portal}><App config={config} host={host} /></PortalContext.Provider>)
      } catch (e) { host.textContent = 'Aplikasi inventaris gagal dimuat. Muat ulang halaman.'; console.error(e) }
    })
  }
  const observer = new MutationObserver(scan)
  runtimes[version] = {
    scan,
    dispose: () => { observer.disconnect(); for (const root of roots.values()) root.unmount(); roots.clear() },
  }
  observer.observe(document.body, { childList: true, subtree: true })
  scan()
}
