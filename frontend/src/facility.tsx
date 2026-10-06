import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import { toast } from "sonner";
import {
  ArrowRight,
  Building2,
  CheckCircle2,
  FileText,
  FolderOpen,
  Plus,
  Trash2,
  Wifi,
  XCircle,
  type LucideIcon,
} from "lucide-react";
import { Button } from "./components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "./components/ui/dialog";
import { Field, FieldContent, FieldDescription, FieldError, FieldGroup, FieldLabel } from "./components/ui/field";
import { Input } from "./components/ui/input";
import { Switch } from "./components/ui/switch";
import { useWorkspace } from "./context";
import { dateLabel, url } from "./api";
import { ActionBar, Choice, ErrorBox, ImagePreview, Loading, LocationSelect, PageHeader, TextField, previewPdf } from "./shared";
import { Confirm, usePage } from "./settings";
import { LevelBadge, type Level } from "./sarpras";

type Settings = {
  designed: boolean;
  building_area: number;
  bandwidth_mbps: number;
  bandwidth_users: number;
  bandwidth_coverage: string;
  bandwidth_date: string;
};
type SupportDocument = { id: number; kind: string; topic: string; title: string; mime: string; created_at: string; room: { id: number; name: string } | null };
/**
 * The files the location keeps as evidence of its facilities, each of a kind under a topic: its
 * internet (speed tests, the ISP's service, the Wi-Fi coverage map) and its security and safety.
 */
type SupportData = {
  /** Null until the plugin's migration 17 has run. */
  documents: SupportDocument[] | null;
  topics: Record<string, string>;
  /** `room`: whether a document of the kind may be of one room rather than the whole location. */
  kinds: Record<string, { label: string; topic: string; room: boolean }>;
  rooms: { id: number; name: string }[];
  max_bytes: number;
  max: number;
};
type Location = { code: string; name: string; rooms: number };
type Result = { no: number; name: string; value: string; level: Level | null; checks: { label: string; ok: boolean }[] };
type Data = {
  settings: Settings;
  support: SupportData;
  /** Active SLiMS members counted for this location (Sivitas per Lokasi); not typed in here. */
  sivitas: { count: number; single: boolean; unmapped: number };
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

const documentTypes = ["application/pdf", "image/jpeg", "image/png", "image/webp"];

/** The location's supporting documents, by topic: several per kind, a speed test for each room when there are many. */
function SupportDocuments({ data, page, library, post, reload }: { data: Data; page: string; library: string; post: Post; reload: () => void }) {
  const w = useWorkspace();
  const support = data.support;
  const [adding, setAdding] = useState(false);
  const [removing, setRemoving] = useState<SupportDocument>();
  const [viewing, setViewing] = useState<SupportDocument>();
  const [busy, setBusy] = useState(false);
  const href = (document: SupportDocument) => url(page, { document: document.id, library: library || undefined });
  const kindLabel = (document: SupportDocument) => support.kinds[document.kind]?.label ?? document.kind;
  // Both kinds open in a popup over the page: a PDF in the print viewer, a picture in a dialog.
  const open = (document: SupportDocument) => (document.mime === "application/pdf" ? previewPdf(w.config, href(document), document.title) : setViewing(document));

  async function remove() {
    if (!removing) return;
    setBusy(true);
    try {
      const reply = await post({ action: "document_delete", record_id: removing.id, library, csrf: data.csrf });
      toast.success(reply.message || "Dokumen pendukung dihapus.");
      setRemoving(undefined);
      reload();
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Section
      icon={FolderOpen}
      title="Dokumen pendukung"
      description="Berkas bukti sarana dan prasarana lokasi ini. Jaringan: hasil uji kecepatan tiap ruang, layanan ISP, peta jangkauan Wi-Fi. Keamanan: sertifikat atau uji fungsi perangkat, POS tanggap darurat, berita acara pelatihan."
    >
      {support.documents === null ? (
        <p className="text-sm text-muted-foreground">Jalankan migrasi plugin hingga versi 17 di System → Plugins untuk menyimpan dokumen pendukung.</p>
      ) : (
        <>
          {support.documents.length === 0 && <p className="text-sm text-muted-foreground">Belum ada dokumen pendukung.</p>}
          {Object.entries(support.topics).map(([topic, label]) => {
            const documents = support.documents!.filter((document) => document.topic === topic);
            return documents.length === 0 ? null : (
              <div key={topic} className="flex flex-col gap-2">
                <h3 className="text-sm font-medium text-muted-foreground">{label}</h3>
                <ul className="flex flex-col gap-2">
                  {documents.map((document) => (
                    <li key={document.id} className="flex flex-wrap items-center gap-3 rounded-xl border p-3">
                      <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <FileText className="size-4" />
                      </span>
                      <div className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate text-sm font-medium">{document.title}</span>
                        <span className="text-xs text-muted-foreground">
                          {kindLabel(document)}
                          {document.room ? ` · ${document.room.name}` : ""} · Diunggah {dateLabel(document.created_at)}
                        </span>
                      </div>
                      <div className="flex items-center gap-1">
                        <Button type="button" size="sm" variant="outline" aria-label={`Lihat ${document.title}`} onClick={() => open(document)}>
                          Lihat
                        </Button>
                        {data.write && (
                          <Button
                            type="button"
                            size="icon-sm"
                            variant="ghost"
                            className="text-destructive"
                            aria-label={`Hapus ${document.title}`}
                            onClick={() => setRemoving(document)}
                          >
                            <Trash2 />
                          </Button>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              </div>
            );
          })}
          {data.write && support.documents.length < support.max && (
            <div>
              <Button type="button" variant="outline" onClick={() => setAdding(true)}>
                <Plus data-icon="inline-start" />
                Tambah dokumen
              </Button>
            </div>
          )}
        </>
      )}
      {adding && (
        <SupportDocumentDialog
          data={data}
          library={library}
          post={post}
          onClose={() => setAdding(false)}
          onSaved={() => {
            setAdding(false);
            reload();
          }}
        />
      )}
      <ImagePreview
        image={
          viewing && {
            url: href(viewing),
            title: viewing.title,
            description: `${kindLabel(viewing)}, diunggah ${dateLabel(viewing.created_at)}.`,
          }
        }
        onClose={() => setViewing(undefined)}
      />
      <Confirm
        open={!!removing}
        title="Hapus dokumen pendukung?"
        description={`${removing?.title || "Dokumen"} dan berkasnya dihapus permanen.`}
        action="Hapus dokumen"
        busy={busy}
        onCancel={() => setRemoving(undefined)}
        onConfirm={remove}
      />
    </Section>
  );
}

function SupportDocumentDialog({ data, library, post, onClose, onSaved }: { data: Data; library: string; post: Post; onClose: () => void; onSaved: () => void }) {
  const support = data.support;
  const fileId = useId();
  const titleId = useId();
  const [kind, setKind] = useState("speedtest");
  const [room, setRoom] = useState("");
  const [title, setTitle] = useState("");
  const [file, setFile] = useState<File>();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const megabytes = Math.round(support.max_bytes / 1024 / 1024);
  const fileError = file && (!documentTypes.includes(file.type) || file.size > support.max_bytes) ? `Gunakan PDF, JPEG, PNG, atau WebP maksimal ${megabytes} MB.` : "";
  // The ISP's service or an emergency procedure is the location's; a speed test or a device's certificate may be of one room.
  const ofRoom = !!support.kinds[kind]?.room && support.rooms.length > 0;

  async function save() {
    if (!file || fileError) {
      setError(fileError || "Pilih berkas dokumen.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      const reply = await post({ action: "document_upload", kind, room_id: ofRoom ? room : "", title, document: file, library, csrf: data.csrf });
      toast.success(reply.message || "Dokumen pendukung tersimpan.");
      onSaved();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Tambah dokumen pendukung</DialogTitle>
          <DialogDescription>Gambar atau PDF, maksimal {megabytes} MB.</DialogDescription>
        </DialogHeader>
        <ErrorBox message={error} />
        <FieldGroup>
          <Choice
            label="Jenis dokumen"
            value={kind}
            onChange={(v) => v && setKind(v)}
            items={Object.entries(support.kinds).map(([value, about]) => ({ value, label: `${support.topics[about.topic] ?? about.topic}: ${about.label}` }))}
          />
          {ofRoom && (
            <Choice
              label="Ruangan"
              value={room}
              onChange={setRoom}
              placeholder="Seluruh lokasi"
              items={[{ value: "", label: "Seluruh lokasi" }, ...support.rooms.map((r) => ({ value: String(r.id), label: r.name }))]}
              description="Opsional. Pilih ruangan bila dokumen ini hanya untuk satu ruangan."
            />
          )}
          <Field data-invalid={!!fileError}>
            <FieldLabel htmlFor={fileId}>Berkas dokumen</FieldLabel>
            <Input
              id={fileId}
              type="file"
              accept={documentTypes.join(",")}
              onChange={(e) => {
                setFile(e.target.files?.[0]);
                setError("");
              }}
            />
            {fileError && <FieldError>{fileError}</FieldError>}
          </Field>
          <Field>
            <FieldLabel htmlFor={titleId}>Judul</FieldLabel>
            <Input id={titleId} maxLength={150} value={title} placeholder="Contoh: Uji kecepatan ruang baca" onChange={(e) => setTitle(e.target.value)} />
            <FieldDescription>Opsional. Tanpa judul, nama berkas yang dipakai.</FieldDescription>
          </Field>
        </FieldGroup>
        <DialogFooter>
          <Button variant="outline" disabled={busy} onClick={onClose}>
            Batal
          </Button>
          <Button disabled={busy || !file || !!fileError} onClick={save}>
            {busy ? "Mengunggah…" : "Unggah"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function FacilityForm({ data, page, post, reload }: { data: Data; page: string; post: Post; reload: () => void }) {
  const w = useWorkspace();
  const s = data.settings;
  const library = data.location?.code ?? "";
  const [values, setValues] = useState(() => fields(s));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
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
  const perPerson = ratio(values.building_area, String(data.sivitas.count));
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
        <div className="flex min-w-0 flex-col gap-6">
        <fieldset disabled={busy || !data.write} className="flex min-w-0 flex-col gap-6">
          <Section icon={Building2} title="Gedung dan sivitas" description="Dipakai untuk menghitung luas perpustakaan per orang yang dilayani.">
            <FieldGroup>
              <FieldGroup className="grid sm:grid-cols-2">
                <Field>
                  <FieldLabel>Jumlah sivitas</FieldLabel>
                  <div className="flex h-9 items-center justify-between gap-2 rounded-md border bg-muted/40 px-3 text-sm">
                    <span className="font-medium tabular-nums">{data.sivitas.count.toLocaleString("id-ID")} orang</span>
                    <Button type="button" variant="link" size="sm" className="h-auto px-0" onClick={() => w.go({ view: "sivitas" })}>
                      Atur
                    </Button>
                  </div>
                  <FieldDescription>
                    Dihitung dari anggota SLiMS yang aktif
                    {data.sivitas.single ? "." : " dan dipetakan ke lokasi ini di Sivitas per Lokasi."}
                    {!data.sivitas.single && data.sivitas.unmapped > 0 && ` ${data.sivitas.unmapped.toLocaleString("id-ID")} anggota belum dipetakan.`}
                  </FieldDescription>
                </Field>
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
              <p className="text-sm text-muted-foreground">Unggah hasil uji kecepatannya di Dokumen pendukung di bawah, sebagai bukti pengukuran.</p>
            </FieldGroup>
          </Section>
        </fieldset>
        {/* Outside the fieldset: a reader may still open the documents. */}
        <SupportDocuments data={data} page={page} library={library} post={post} reload={reload} />
        </div>
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
