import { useState } from "react";
import { toast } from "sonner";
import { ChartNoAxesColumn, ExternalLink, Trash2, Upload as UploadIcon } from "lucide-react";
import { Button } from "./components/ui/button";
import { Field, FieldDescription, FieldGroup, FieldLabel } from "./components/ui/field";
import { Input } from "./components/ui/input";
import { Switch } from "./components/ui/switch";
import { useWorkspace } from "./context";
import { dateLabel, url } from "./api";
import { ActionBar, Choice, ErrorBox, Loading, LocationSelect, PageHeader, Panel, TextField } from "./shared";
import { Confirm, usePage } from "./settings";

type Settings = {
  sivitas: number;
  designed: boolean;
  building_area: number;
  bandwidth_mbps: number;
  bandwidth_users: number;
  bandwidth_coverage: string;
  bandwidth_date: string;
  evidence: { name: string; mime: string; uploaded_at: string } | null;
};
type Location = { code: string; name: string; rooms: number };
type Data = {
  settings: Settings;
  coverage: Record<string, string>;
  /** The library locations these figures are kept for, and the one shown; none while the library is one unit. */
  locations: Location[];
  location: Location | null;
  write: boolean;
  csrf: string;
};
type Post = (values: Record<string, unknown>) => Promise<{ message?: string }>;

function FacilityForm({ data, page, post, reload }: { data: Data; page: string; post: Post; reload: () => void }) {
  const w = useWorkspace();
  const s = data.settings;
  const library = data.location?.code ?? "";
  const num = (v: number) => (v ? String(v) : "");
  const [values, setValues] = useState({
    sivitas: num(s.sivitas),
    building_area: num(s.building_area),
    designed: s.designed,
    bandwidth_mbps: num(s.bandwidth_mbps),
    bandwidth_users: num(s.bandwidth_users),
    bandwidth_coverage: s.bandwidth_coverage,
    bandwidth_date: s.bandwidth_date,
  });
  const [file, setFile] = useState<File>();
  const [picker, setPicker] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [removeEvidence, setRemoveEvidence] = useState(false);
  const set = (key: keyof typeof values, value: string | boolean) => {
    setValues((v) => ({ ...v, [key]: value }));
    w.dirty(true);
  };
  const perUser =
    Number(values.bandwidth_mbps) > 0 && Number(values.bandwidth_users) > 0
      ? Number(values.bandwidth_mbps) / Number(values.bandwidth_users)
      : null;

  async function run(body: Record<string, unknown>, done?: () => void) {
    setBusy(true);
    setError("");
    try {
      const reply = await post({ ...body, library, csrf: data.csrf });
      toast.success(reply.message);
      done?.();
      reload();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <ErrorBox message={error} />
      <fieldset disabled={busy || !data.write} className="grid min-w-0 gap-6 lg:grid-cols-2">
        <Panel title="Gedung dan sivitas" description="Untuk menghitung luas per sivitas akademika.">
          <FieldGroup>
            <TextField
              label="Jumlah sivitas akademika"
              type="number"
              value={values.sivitas}
              onChange={(v) => set("sivitas", v)}
              description="Mahasiswa, dosen, dan tenaga kependidikan."
            />
            <TextField
              label="Luas gedung (m²)"
              type="number"
              value={values.building_area}
              onChange={(v) => set("building_area", v)}
              description="Kosongkan untuk memakai jumlah luas ruangan dari Ruangan & Barang."
            />
            <Field orientation="horizontal">
              <Switch id="designed" checked={values.designed} onCheckedChange={(v) => set("designed", v)} />
              <FieldLabel htmlFor="designed" className="font-normal">
                Gedung atau ruang didesain khusus untuk perpustakaan
              </FieldLabel>
            </Field>
          </FieldGroup>
        </Panel>
        <Panel title="Jaringan internet" description="Ukur saat jam sibuk layanan, lalu unggah buktinya.">
          <FieldGroup>
            <FieldGroup className="grid sm:grid-cols-2">
              <TextField label="Bandwidth total (Mbps)" type="number" value={values.bandwidth_mbps} onChange={(v) => set("bandwidth_mbps", v)} />
              <TextField
                label="Pengguna serentak"
                type="number"
                value={values.bandwidth_users}
                onChange={(v) => set("bandwidth_users", v)}
              />
            </FieldGroup>
            <FieldGroup className="grid sm:grid-cols-2">
              <Choice
                label="Jangkauan"
                value={values.bandwidth_coverage}
                onChange={(v) => set("bandwidth_coverage", v)}
                items={Object.entries(data.coverage).map(([value, label]) => ({ value, label }))}
              />
              <TextField label="Tanggal pengukuran" type="date" value={values.bandwidth_date} onChange={(v) => set("bandwidth_date", v)} />
            </FieldGroup>
            <p className="text-sm text-muted-foreground">
              {perUser === null ? "Isi bandwidth dan jumlah pengguna untuk melihat Mbps per orang." : `≈ ${perUser.toLocaleString("id-ID", { maximumFractionDigits: 2 })} Mbps per orang.`}
            </p>
            <Field>
              <FieldLabel>Bukti pengukuran</FieldLabel>
              {s.evidence ? (
                <div className="flex flex-wrap items-center gap-2 rounded-lg border p-2.5 text-sm">
                  <span className="min-w-0 flex-1 truncate">{s.evidence.name}</span>
                  <span className="text-xs text-muted-foreground">{dateLabel(s.evidence.uploaded_at)}</span>
                  <Button size="sm" variant="outline" asChild>
                    <a href={url(page, { evidence: 1, library: library || undefined })} target="_blank" rel="noopener" className="notAJAX">
                      <ExternalLink data-icon="inline-start" />
                      Lihat
                    </a>
                  </Button>
                  {data.write && (
                    <Button size="sm" variant="ghost" className="text-destructive" onClick={() => setRemoveEvidence(true)}>
                      <Trash2 data-icon="inline-start" />
                      Hapus
                    </Button>
                  )}
                </div>
              ) : null}
              <div className="flex flex-wrap gap-2">
                <Input
                  key={picker}
                  type="file"
                  accept="application/pdf,image/jpeg,image/png,image/webp"
                  className="min-w-0 flex-1"
                  onChange={(e) => setFile(e.target.files?.[0])}
                />
                <Button
                  type="button"
                  variant="outline"
                  disabled={!file}
                  onClick={() =>
                    run({ action: "evidence", evidence: file }, () => {
                      setFile(undefined);
                      setPicker((n) => n + 1);
                    })
                  }
                >
                  <UploadIcon data-icon="inline-start" />
                  {s.evidence ? "Ganti" : "Unggah"}
                </Button>
              </div>
              <FieldDescription>Tangkapan layar speedtest atau kontrak layanan ISP. PDF, JPEG, PNG, atau WebP, maksimal 5 MB.</FieldDescription>
            </Field>
          </FieldGroup>
        </Panel>
      </fieldset>
      {data.write && (
        <ActionBar status={busy ? "Menyimpan…" : undefined}>
          <Button
            disabled={busy}
            onClick={() =>
              run({ action: "settings", ...values, designed: values.designed ? "1" : "" }, () => w.dirty(false))
            }
          >
            Simpan
          </Button>
        </ActionBar>
      )}
      <Confirm
        open={removeEvidence}
        title="Hapus bukti pengukuran?"
        description="Berkas bukti dihapus permanen. Angka bandwidth tetap tersimpan."
        action="Hapus bukti"
        busy={busy}
        onCancel={() => setRemoveEvidence(false)}
        onConfirm={() => run({ action: "evidence_delete" }, () => setRemoveEvidence(false))}
      />
    </>
  );
}

export function FacilityPage() {
  const w = useWorkspace();
  const page = w.config.pages!.facility;
  const library = String(w.route.library || "");
  const { data, error, reload, post } = usePage<Data>(page, library ? { library } : {});
  const several = !!data && data.locations.length > 1;
  const code = data?.location?.code ?? "";
  return (
    <>
      <PageHeader
        title="Gedung & Jaringan"
        description={
          "Angka yang tidak tercatat di inventaris: jumlah sivitas, luas gedung, dan bandwidth internet beserta bukti pengukurannya. Dipakai untuk menghitung Rekap Sarpras." +
          (several ? " Isi untuk tiap lokasi perpustakaan." : "")
        }
        actions={
          <>
            {data && several && (
              <LocationSelect locations={data.locations} value={code} onChange={(next) => w.go({ view: "facility", library: next }, true)} />
            )}
            <Button variant="outline" onClick={() => w.go(code ? { view: "sarpras", library: code } : { view: "sarpras" })}>
              <ChartNoAxesColumn data-icon="inline-start" />
              Lihat Rekap Sarpras
            </Button>
          </>
        }
      />
      <ErrorBox message={error} />
      {!data ? !error && <Loading /> : <FacilityForm key={code} data={data} page={page} post={post} reload={reload} />}
    </>
  );
}
