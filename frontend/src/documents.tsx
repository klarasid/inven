import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Settings2, RotateCcw } from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Separator } from "./components/ui/separator";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "./components/ui/dialog";
import { useWorkspace } from "./context";
import { read } from "./api";
import { ErrorBox, Loading, TextField } from "./shared";

type Identity = { number: string; revision: string; issued: string };
type Data = {
  settings: Record<string, Identity>;
  types: Record<string, string>;
  placeholders: Record<string, string>;
};
const DEFAULTS: Record<string, Identity> = {
  period: { number: "LAP-SARPRAS/{dari}-{sampai}", revision: "00", issued: "" },
  inspection: { number: "PMR-{id}", revision: "00", issued: "" },
  report: { number: "LK-{id}", revision: "00", issued: "" },
};
const ROMAN = ["I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X", "XI", "XII"];

/** Mirrors PdfDocuments::identity with sample values so the format can be checked before saving. */
function preview(format: string, today: string) {
  const [y, m] = today.split("-");
  return format
    .replaceAll("{id}", "00171")
    .replaceAll("{tahun}", y)
    .replaceAll("{bulan}", m)
    .replaceAll("{romawi}", ROMAN[Number(m) - 1])
    .replaceAll("{dari}", `${y}0101`)
    .replaceAll("{sampai}", today.replaceAll("-", ""));
}

export function DocumentSettings() {
  const w = useWorkspace();
  const [open, setOpen] = useState(false);
  const [data, setData] = useState<Data>();
  const [values, setValues] = useState<Record<string, Identity>>({});
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    setError("");
    read<Data>(w.config, "pdf_documents", {}, controller.signal)
      .then((d) => {
        setData(d);
        setValues(d.settings);
      })
      .catch((e) => e.name !== "AbortError" && setError(e.message));
    return () => controller.abort();
  }, [open]);

  const update = (type: string, patch: Partial<Identity>) =>
    setValues((v) => ({ ...v, [type]: { ...v[type], ...patch } }));

  async function save() {
    setBusy(true);
    setError("");
    try {
      await w.mutate({ watch_action: "pdf_documents", documents: values });
      toast.success("Pengaturan dokumen tersimpan.");
      setOpen(false);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (!w.config.write) return null;
  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        <Settings2 data-icon="inline-start" />
        Pengaturan dokumen
      </Button>
      <Dialog open={open} onOpenChange={(v) => !busy && setOpen(v)}>
        <DialogContent className="max-h-[88vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Pengaturan dokumen PDF</DialogTitle>
            <DialogDescription>
              Nomor dokumen, revisi, dan tanggal terbit yang tercetak di kepala dokumen ISO dan di bawah judul gaya LaTeX.
            </DialogDescription>
          </DialogHeader>
          <ErrorBox message={error} />
          {!data ? (
            !error && <Loading />
          ) : (
            <fieldset disabled={busy} className="flex min-w-0 flex-col gap-5">
              {Object.entries(data.types).map(([type, label], index) => {
                const v = values[type] || DEFAULTS[type];
                return (
                  <section key={type} className="flex flex-col gap-3">
                    {index > 0 && <Separator />}
                    <div className="flex items-center justify-between gap-2">
                      <h3 className="font-medium">{label}</h3>
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => update(type, DEFAULTS[type])}
                        aria-label={`Kembalikan ${label} ke bawaan`}
                      >
                        <RotateCcw data-icon="inline-start" />
                        Bawaan
                      </Button>
                    </div>
                    <TextField
                      label="Format nomor dokumen"
                      required
                      value={v.number}
                      maxLength={80}
                      onChange={(number) => update(type, { number })}
                      description={`Contoh hasil: ${preview(v.number, w.config.today)}`}
                    />
                    <div className="flex flex-wrap gap-1.5">
                      {Object.entries(data.placeholders)
                        .filter(([token]) => type === "period" || !["{dari}", "{sampai}"].includes(token))
                        .map(([token, meaning]) => (
                          <button
                            type="button"
                            key={token}
                            title={meaning}
                            onClick={() => update(type, { number: v.number + token })}
                            className="cursor-pointer"
                          >
                            <Badge variant="outline" className="font-mono hover:bg-muted">
                              {token}
                            </Badge>
                          </button>
                        ))}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                      <TextField
                        label="Revisi"
                        required
                        value={v.revision}
                        maxLength={10}
                        onChange={(revision) => update(type, { revision })}
                      />
                      <TextField
                        label="Tanggal terbit"
                        type="date"
                        value={v.issued}
                        onChange={(issued) => update(type, { issued })}
                        description="Kosongkan agar memakai tanggal dokumen."
                      />
                    </div>
                  </section>
                );
              })}
              <p className="text-xs text-muted-foreground">
                Klik penanda untuk menambahkannya ke format. Arahkan kursor ke penanda untuk melihat artinya.
              </p>
            </fieldset>
          )}
          <DialogFooter>
            <Button variant="outline" disabled={busy} onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button disabled={busy || !data} onClick={save}>
              {busy ? "Menyimpan…" : "Simpan pengaturan"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
