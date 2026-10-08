import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "./components/ui/button";
import { useWorkspace } from "./context";
import { read } from "./api";
import { ActionBar, Choice, ErrorBox, Loading, Panel } from "./shared";

type Defaults = { style: string; kir: string };
type Data = {
  settings: Defaults;
  styles: Record<string, string>;
  kir: Record<string, string>;
  letterheads: { id: string; name: string }[];
};

/** The style a PDF gets when it is asked for without one: from the InvenSync app or an AI app. */
export function PrintDefaults() {
  const w = useWorkspace();
  const [data, setData] = useState<Data>();
  const [values, setValues] = useState<Defaults>({ style: "latex", kir: "classic" });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    read<Data>(w.config, "pdf_defaults", {}, controller.signal)
      .then((d) => {
        setData(d);
        setValues(d.settings);
      })
      .catch((e) => e.name !== "AbortError" && setError(e.message));
    return () => controller.abort();
  }, []);

  const update = (patch: Partial<Defaults>) => {
    setValues((v) => ({ ...v, ...patch }));
    w.dirty(true);
  };

  async function save() {
    setBusy(true);
    setError("");
    try {
      await w.mutate({ watch_action: "pdf_defaults", defaults: values });
      w.dirty(false);
      toast.success("Gaya bawaan tersimpan.");
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
  const styles = [
    ...Object.entries(data.styles).map(([value, label]) => ({ value, label })),
    ...data.letterheads.map((t) => ({ value: `kop:${t.id}`, label: `Kop: ${t.name}` })),
  ];
  return (
    <>
      <ErrorBox message={error} />
      <p className="text-sm text-muted-foreground">
        Gaya bawaan dipakai saat PDF diminta tanpa memilih gaya, yaitu dari aplikasi Klaras InvenSync dan dari aplikasi AI.
        Saat mencetak dari SLiMS, Anda tetap memilih gaya di menu Cetak PDF.
      </p>
      <fieldset disabled={busy || !w.config.write} className="grid min-w-0 gap-6 lg:grid-cols-2">
        <Panel title="Laporan dan dokumen pemeriksaan">
          <Choice
            label="Gaya"
            value={values.style}
            onChange={(style) => update({ style })}
            items={styles}
            description="Untuk memakai kop institusi, unggah dulu di tab Template kop."
          />
        </Panel>
        <Panel title="Kartu Inventaris Ruangan">
          <Choice
            label="Tata letak"
            value={values.kir}
            onChange={(kir) => update({ kir })}
            items={Object.entries(data.kir).map(([value, label]) => ({ value, label }))}
          />
        </Panel>
      </fieldset>
      {w.config.write && (
        <ActionBar status={busy ? "Menyimpan…" : undefined}>
          <Button disabled={busy} onClick={save}>
            Simpan gaya bawaan
          </Button>
        </ActionBar>
      )}
    </>
  );
}
