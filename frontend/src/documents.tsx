import { useEffect, useState } from "react";
import { toast } from "sonner";
import { RotateCcw } from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { useWorkspace } from "./context";
import { read } from "./api";
import { ActionBar, ErrorBox, Loading, Panel, TextField } from "./shared";

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
  sarpras: { number: "REKAP-SARPRAS/{romawi}/{tahun}", revision: "00", issued: "" },
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

/** Document number, revision and issue date printed on each kind of PDF. */
export function DocumentNumbering() {
  const w = useWorkspace();
  const [data, setData] = useState<Data>();
  const [values, setValues] = useState<Record<string, Identity>>({});
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    read<Data>(w.config, "pdf_documents", {}, controller.signal)
      .then((d) => {
        setData(d);
        setValues(d.settings);
      })
      .catch((e) => e.name !== "AbortError" && setError(e.message));
    return () => controller.abort();
  }, []);

  const update = (type: string, patch: Partial<Identity>) => {
    setValues((v) => ({ ...v, [type]: { ...v[type], ...patch } }));
    w.dirty(true);
  };

  async function save() {
    setBusy(true);
    setError("");
    try {
      await w.mutate({ watch_action: "pdf_documents", documents: values });
      w.dirty(false);
      toast.success("Nomor dokumen tersimpan.");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (!data)
    return (
      <>
        <ErrorBox message={error} />
        {!error && <Loading />}
      </>
    );
  return (
    <>
      <ErrorBox message={error} />
      <p className="text-sm text-muted-foreground">
        Nomor dokumen, revisi, dan tanggal terbit tercetak di kepala dokumen ISO dan di bawah judul gaya LaTeX. Klik penanda
        untuk menambahkannya ke format; arahkan kursor ke penanda untuk melihat artinya.
      </p>
      <fieldset disabled={busy || !w.config.write} className="grid min-w-0 gap-6 lg:grid-cols-2">
        {Object.entries(data.types).map(([type, label]) => {
          const v = values[type] || DEFAULTS[type];
          return (
            <Panel
              key={type}
              title={label}
              action={
                w.config.write && (
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
                )
              }
            >
              <div className="flex flex-col gap-3">
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
                        className="cursor-pointer disabled:cursor-default"
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
              </div>
            </Panel>
          );
        })}
      </fieldset>
      {w.config.write && (
        <ActionBar status={busy ? "Menyimpan…" : undefined}>
          <Button disabled={busy} onClick={save}>
            Simpan nomor dokumen
          </Button>
        </ActionBar>
      )}
    </>
  );
}
