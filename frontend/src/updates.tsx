import { useEffect, useState } from "react";
import { ArrowUpCircle, Download, ExternalLink, RefreshCw } from "lucide-react";
import { Button } from "./components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "./components/ui/dialog";
import { useWorkspace } from "./context";
import { read, dateLabel } from "./api";

type Update = {
  current: string;
  latest: string | null;
  available: boolean;
  url: string | null;
  download: string | null;
  notes: string;
  published_at: string | null;
  checked_at: string | null;
};

/** GitHub release notes are Markdown; show them as readable text without a Markdown renderer. */
function plain(notes: string) {
  return notes
    .replace(/\r/g, "")
    .replace(/<!--[\s\S]*?-->/g, "")
    .replace(/^#{1,6}\s*/gm, "")
    .replace(/\*\*(.+?)\*\*/g, "$1")
    .replace(/\[([^\]]+)\]\((https?:[^)]+)\)/g, "$1")
    .replace(/^\s*[-*]\s+/gm, "• ")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
}

/**
 * Installed version in the header, and, for staff who can write, a notice when GitHub has a newer
 * release. The server caches the lookup, so this call is cheap and never waits on GitHub for long.
 */
export function UpdateNotice() {
  const w = useWorkspace();
  const [update, setUpdate] = useState<Update>();
  const [open, setOpen] = useState(false);
  const [checking, setChecking] = useState(false);

  const load = (refresh = false) => {
    setChecking(refresh);
    return read<Update>(w.config, "update", refresh ? { refresh: 1 } : {})
      .then(setUpdate)
      .catch(() => {})
      .finally(() => setChecking(false));
  };
  useEffect(() => {
    load();
  }, []);

  if (!update) return null;
  if (!update.available || !w.config.write)
    return (
      <span className="text-xs text-muted-foreground tabular-nums" title="Versi plugin terpasang">
        v{update.current}
      </span>
    );
  const notes = plain(update.notes);
  return (
    <>
      <Button size="xs" variant="outline" className="border-info/40 text-info" onClick={() => setOpen(true)}>
        <ArrowUpCircle data-icon="inline-start" />
        Versi {update.latest} tersedia
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Pembaruan plugin tersedia</DialogTitle>
            <DialogDescription>
              Versi terpasang <b>v{update.current}</b>, versi terbaru <b>v{update.latest}</b>
              {update.published_at && ` (dirilis ${dateLabel(update.published_at)})`}.
            </DialogDescription>
          </DialogHeader>
          {notes && (
            <div className="max-h-64 overflow-y-auto rounded-lg border bg-muted/30 p-3 text-sm whitespace-pre-wrap">{notes}</div>
          )}
          <div className="rounded-lg border p-3 text-sm">
            <p className="mb-1 font-medium">Cara memperbarui</p>
            <ol className="list-decimal space-y-0.5 pl-5 text-muted-foreground">
              <li>Unduh berkas zip rilis, lalu cadangkan database.</li>
              <li>
                Ekstrak dan timpa folder <code>plugins/inventaris-barang</code>.
              </li>
              <li>Buka System → Plugins untuk menjalankan migrasi bila ada.</li>
            </ol>
          </div>
          <DialogFooter className="flex-wrap gap-2 sm:justify-between">
            <Button variant="ghost" size="sm" disabled={checking} onClick={() => load(true)}>
              <RefreshCw data-icon="inline-start" className={checking ? "animate-spin" : undefined} />
              Periksa lagi
            </Button>
            <div className="flex flex-wrap gap-2">
              {update.url && (
                <Button variant="outline" asChild>
                  <a href={update.url} target="_blank" rel="noopener">
                    <ExternalLink data-icon="inline-start" />
                    Catatan rilis
                  </a>
                </Button>
              )}
              {update.download && (
                <Button asChild>
                  <a href={update.download} target="_blank" rel="noopener">
                    <Download data-icon="inline-start" />
                    Unduh v{update.latest}
                  </a>
                </Button>
              )}
            </div>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
