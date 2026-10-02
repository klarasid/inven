import { useEffect, useRef, useState, type ReactNode } from "react";
import { toast } from "sonner";
import {
  ArrowRight,
  Building2,
  CheckCircle2,
  ExternalLink,
  FileText,
  Trash2,
  Upload as UploadIcon,
  Wifi,
  XCircle,
  type LucideIcon,
} from "lucide-react";
import { Button } from "./components/ui/button";
import { Field, FieldContent, FieldDescription, FieldGroup, FieldLabel } from "./components/ui/field";
import { Switch } from "./components/ui/switch";
import { useWorkspace } from "./context";
import { dateLabel, url } from "./api";
import { ActionBar, Choice, ErrorBox, ImagePreview, Loading, LocationSelect, PageHeader, TextField } from "./shared";
import { Confirm, usePage } from "./settings";
import { LevelBadge, type Level } from "./sarpras";

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
type Result = { no: number; name: string; value: string; level: Level | null; checks: { label: string; ok: boolean }[] };
type Data = {
  settings: Settings;
  coverage: Record<string, string>;
  /** What the saved figures amount to in the recap: the aspects computed from them. */
  result: Result[];
  levels: Record<Level, string>;
  /** The library locations these figures are kept for, and the one shown; none while the library is one unit. */
  locations: Location[];
  location: Location | null;
  write: boolean;
  csrf: string;
};
type Post = (values: Record<string, unknown>) => Promise<{ message?: string }>;

const num = (v: number) => (v ? String(v) : "");
const fields = (s: Settings) => ({
  sivitas: num(s.sivitas),
  building_area: num(s.building_area),
  designed: s.designed,
  bandwidth_mbps: num(s.bandwidth_mbps),
  bandwidth_users: num(s.bandwidth_users),
  bandwidth_coverage: s.bandwidth_coverage,
  bandwidth_date: s.bandwidth_date,
});
/** a / b for the hint under a pair of fields, or nothing until both are filled in. */
const ratio = (a: string, b: string) => (Number(a) > 0 && Number(b) > 0 ? (Number(a) / Number(b)).toLocaleString("id-ID", { maximumFractionDigits: 2 }) : null);

function Section({ icon: Icon, title, description, children }: { icon: LucideIcon; title: string; description: string; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-5 rounded-2xl border bg-card p-5 md:p-6">
      <div className="flex items-start gap-4">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <Icon className="size-5" />
        </span>
        <div className="flex min-w-0 flex-col gap-0.5">
          <h2 className="text-lg font-semibold tracking-tight">{title}</h2>
          <p className="text-sm text-muted-foreground">{description}</p>
        </div>
      </div>
      {children}
    </section>
  );
}

/** The aspects of the recap these figures decide, as last saved. */
function Outcome({ data, changed, open }: { data: Data; changed: boolean; open: () => void }) {
  return (
    <aside className="flex flex-col gap-4 rounded-2xl border bg-card p-5 lg:sticky lg:top-4 lg:self-start">
      <div className="flex flex-col gap-0.5">
        <h2 className="font-semibold tracking-tight">Hasil di Rekap Sarpras</h2>
        <p className="text-sm text-muted-foreground">
          {changed ? "Simpan perubahan untuk memperbarui hasil ini." : "Dihitung dari data yang tersimpan."}
        </p>
      </div>
      {data.result.map((x) => (
        <div key={x.no} className="flex flex-col gap-2 border-t pt-4 text-sm">
          <div className="flex items-start justify-between gap-2">
            <span className="font-medium">{x.name}</span>
            <LevelBadge level={x.level} levels={data.levels} />
          </div>
          <p className="text-muted-foreground">{x.value}</p>
          <ul className="flex flex-col gap-1.5">
            {x.checks.map((c) => (
              <li key={c.label} className="flex items-start gap-2">
                {c.ok ? (
                  <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-label="Terpenuhi" />
                ) : (
                  <XCircle className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-label="Belum terpenuhi" />
                )}
                <span className={c.ok ? undefined : "text-muted-foreground"}>{c.label}</span>
              </li>
            ))}
          </ul>
        </div>
      ))}
      <Button variant="outline" onClick={open}>
        Lihat Rekap Sarpras
        <ArrowRight data-icon="inline-end" />
      </Button>
    </aside>
  );
}

function FacilityForm({ data, page, post, reload }: { data: Data; page: string; post: Post; reload: () => void }) {
  const w = useWorkspace();
  const s = data.settings;
  const library = data.location?.code ?? "";
  const [values, setValues] = useState(() => fields(s));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [removeEvidence, setRemoveEvidence] = useState(false);
  const [viewEvidence, setViewEvidence] = useState(false);
  const picker = useRef<HTMLInputElement>(null);
  const changed = JSON.stringify(values) !== JSON.stringify(fields(s));
  // After saving, the form shows the figures as the server keeps them (rounded, emptied of zeros).
  const saved = useRef(false);
  useEffect(() => {
    if (saved.current) {
      saved.current = false;
      setValues(fields(s));
    }
  }, [s]);
  useEffect(() => {
    w.dirty(changed);
    return () => w.dirty(false);
  }, [changed]);
  const set = (key: keyof typeof values, value: string | boolean) => setValues((v) => ({ ...v, [key]: value }));
  const perPerson = ratio(values.building_area, values.sivitas);
  const perUser = ratio(values.bandwidth_mbps, values.bandwidth_users);

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
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <fieldset disabled={busy || !data.write} className="flex min-w-0 flex-col gap-6">
          <Section icon={Building2} title="Gedung dan sivitas" description="Dipakai untuk menghitung luas perpustakaan per orang yang dilayani.">
            <FieldGroup>
              <FieldGroup className="grid sm:grid-cols-2">
                <TextField
                  label="Jumlah sivitas akademika"
                  type="number"
                  min="0"
                  value={values.sivitas}
                  onChange={(v) => set("sivitas", v)}
                  description="Mahasiswa, dosen, dan tenaga kependidikan yang dilayani."
                />
                <TextField
                  label="Luas gedung (m²)"
                  type="number"
                  min="0"
                  value={values.building_area}
                  onChange={(v) => set("building_area", v)}
                  description="Opsional. Jika kosong, luas dihitung dari ruangan di Ruangan & Barang."
                />
              </FieldGroup>
              {perPerson && <p className="text-sm text-muted-foreground">Sekitar {perPerson} m² per orang.</p>}
              <Field orientation="horizontal" className="items-start rounded-xl border p-3">
                <Switch id="designed" checked={values.designed} onCheckedChange={(v) => set("designed", v)} />
                <FieldContent>
                  <FieldLabel htmlFor="designed">Gedung dirancang khusus untuk perpustakaan</FieldLabel>
                  <FieldDescription>Aktifkan jika gedung atau ruangannya sejak awal dibangun sebagai perpustakaan.</FieldDescription>
                </FieldContent>
              </Field>
            </FieldGroup>
          </Section>
          <Section icon={Wifi} title="Jaringan internet" description="Ukur saat jam sibuk layanan agar hasilnya mencerminkan pemakaian sehari-hari.">
            <FieldGroup>
              <FieldGroup className="grid sm:grid-cols-2">
                <TextField
                  label="Bandwidth total (Mbps)"
                  type="number"
                  min="0"
                  value={values.bandwidth_mbps}
                  onChange={(v) => set("bandwidth_mbps", v)}
                />
                <TextField
                  label="Pengguna serentak"
                  type="number"
                  min="0"
                  value={values.bandwidth_users}
                  onChange={(v) => set("bandwidth_users", v)}
                  description="Perkiraan orang yang memakai internet pada saat bersamaan."
                />
              </FieldGroup>
              {perUser && <p className="text-sm text-muted-foreground">Sekitar {perUser} Mbps per orang.</p>}
              <FieldGroup className="grid sm:grid-cols-2">
                <Choice
                  label="Jangkauan"
                  value={values.bandwidth_coverage}
                  onChange={(v) => v && set("bandwidth_coverage", v)}
                  items={Object.entries(data.coverage).map(([value, label]) => ({ value, label }))}
                />
                <TextField label="Tanggal pengukuran" type="date" value={values.bandwidth_date} onChange={(v) => set("bandwidth_date", v)} />
              </FieldGroup>
              <Field>
                <FieldLabel>Bukti pengukuran</FieldLabel>
                {s.evidence ? (
                  <div className="flex flex-wrap items-center gap-3 rounded-xl border p-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                      <FileText className="size-4" />
                    </span>
                    <div className="flex min-w-0 flex-1 flex-col">
                      <span className="truncate text-sm font-medium">{s.evidence.name}</span>
                      <span className="text-xs text-muted-foreground">Diunggah {dateLabel(s.evidence.uploaded_at)}</span>
                    </div>
                    <div className="flex items-center gap-1">
                      {s.evidence.mime.startsWith("image/") ? (
                        // A picture opens in a popup on the page; a PDF is left to the browser's viewer.
                        <Button type="button" size="sm" variant="outline" onClick={() => setViewEvidence(true)}>
                          Lihat
                        </Button>
                      ) : (
                        <Button size="sm" variant="outline" asChild>
                          <a href={url(page, { evidence: 1, library: library || undefined })} target="_blank" rel="noopener" className="notAJAX">
                            <ExternalLink data-icon="inline-start" />
                            Lihat
                          </a>
                        </Button>
                      )}
                      {data.write && (
                        <>
                          <Button type="button" size="sm" variant="ghost" onClick={() => picker.current?.click()}>
                            Ganti
                          </Button>
                          <Button
                            type="button"
                            size="icon-sm"
                            variant="ghost"
                            className="text-destructive"
                            aria-label="Hapus bukti"
                            onClick={() => setRemoveEvidence(true)}
                          >
                            <Trash2 />
                          </Button>
                        </>
                      )}
                    </div>
                  </div>
                ) : data.write ? (
                  <button
                    type="button"
                    onClick={() => picker.current?.click()}
                    className="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed p-4 text-sm font-medium outline-none hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-default disabled:opacity-50"
                  >
                    <UploadIcon className="size-4" />
                    {busy ? "Mengunggah…" : "Unggah bukti"}
                  </button>
                ) : (
                  <p className="text-sm text-muted-foreground">Belum ada bukti.</p>
                )}
                <input
                  ref={picker}
                  type="file"
                  aria-label="Berkas bukti pengukuran"
                  accept="application/pdf,image/jpeg,image/png,image/webp"
                  className="sr-only"
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    e.target.value = "";
                    if (file) run({ action: "evidence", evidence: file });
                  }}
                />
                <FieldDescription>Tangkapan layar speedtest atau kontrak layanan ISP. PDF, JPEG, PNG, atau WebP, maksimal 5 MB.</FieldDescription>
              </Field>
            </FieldGroup>
          </Section>
        </fieldset>
        <Outcome data={data} changed={changed} open={() => w.go(library ? { view: "sarpras", library } : { view: "sarpras" })} />
      </div>
      {data.write && (
        <ActionBar status={busy ? "Menyimpan…" : changed ? "Perubahan belum disimpan" : undefined}>
          <Button variant="ghost" disabled={busy || !changed} onClick={() => setValues(fields(s))}>
            Batalkan
          </Button>
          <Button
            disabled={busy || !changed}
            onClick={() =>
              run({ action: "settings", ...values, designed: values.designed ? "1" : "" }, () => {
                saved.current = true;
              })
            }
          >
            Simpan
          </Button>
        </ActionBar>
      )}
      <ImagePreview
        image={
          viewEvidence && s.evidence
            ? { url: url(page, { evidence: 1, library: library || undefined }), title: s.evidence.name, description: `Bukti pengukuran, diunggah ${dateLabel(s.evidence.uploaded_at)}.` }
            : undefined
        }
        onClose={() => setViewEvidence(false)}
      />
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
          "Lengkapi data gedung dan internet yang tidak tercatat di inventaris. Angka ini dipakai untuk menghitung Rekap Sarpras." +
          (several ? " Tiap lokasi perpustakaan punya datanya sendiri." : "")
        }
        actions={
          data &&
          several && (
            <LocationSelect locations={data.locations} value={code} onChange={(next) => w.go({ view: "facility", library: next }, true)} />
          )
        }
      />
      <ErrorBox message={error} />
      {!data ? !error && <Loading /> : <FacilityForm key={code} data={data} page={page} post={post} reload={reload} />}
    </>
  );
}
