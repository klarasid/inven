import { useEffect, useState } from "react";
import { toast } from "sonner";
import {
  AppWindow,
  CheckCircle2,
  CircleDashed,
  ExternalLink,
  Info,
  Pencil,
  Plus,
  Trash2,
  Upload as UploadIcon,
  XCircle,
} from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Alert, AlertDescription, AlertTitle } from "./components/ui/alert";
import { Card, CardContent, CardHeader, CardTitle, CardAction } from "./components/ui/card";
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from "./components/ui/accordion";
import { Tabs, TabsList, TabsTrigger } from "./components/ui/tabs";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "./components/ui/table";
import { Field, FieldDescription, FieldGroup, FieldLabel } from "./components/ui/field";
import { Input } from "./components/ui/input";
import { Switch } from "./components/ui/switch";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "./components/ui/dialog";
import { useWorkspace } from "./context";
import { dateLabel, url } from "./api";
import { ActionBar, Blank, Choice, ErrorBox, Loading, PageHeader, Panel, Pdf, StatCard, TextField } from "./shared";
import { Confirm, usePage } from "./settings";

type Level = "a" | "b" | "c" | "d";
type Aspect = {
  no: number;
  section: string;
  title: string;
  value: string;
  level: Level | null;
  basis: string;
  checks: { label: string; ok: boolean }[];
  rows: string[][];
  columns: string[];
  fix: string;
};
type Software = {
  id: number;
  name: string;
  version: string;
  purpose: string;
  licence: string;
  licence_ref: string;
  valid_until: string | null;
  installs: number;
  notes: string | null;
};
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
type Data = {
  recap: {
    generated_at: string;
    aspects: Aspect[];
    summary: Record<Level | "empty", number>;
    counts: { rooms: number; items: number; uncategorized: number; unclassified_rooms: number; no_area: number };
  };
  settings: Settings;
  software: Software[];
  licences: Record<string, string>;
  coverage: Record<string, string>;
  levels: Record<Level, string>;
  write: boolean;
  csrf: string;
};

const tone: Record<Level, "success" | "info" | "warning" | "destructive"> = {
  a: "success",
  b: "info",
  c: "warning",
  d: "destructive",
};

function LevelBadge({ level, levels }: { level: Level | null; levels: Record<Level, string> }) {
  return level ? (
    <Badge variant={tone[level]}>{levels[level]}</Badge>
  ) : (
    <Badge variant="outline">
      <CircleDashed data-icon="inline-start" />
      Belum ada data
    </Badge>
  );
}

function AspectCard({ aspect: x, levels }: { aspect: Aspect; levels: Record<Level, string> }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="leading-snug">{x.title}</CardTitle>
        <CardAction>
          <LevelBadge level={x.level} levels={levels} />
        </CardAction>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <p className="text-xl font-semibold tracking-tight">{x.value}</p>
        <p className="text-sm text-muted-foreground">{x.basis}</p>
        <ul className="flex flex-col gap-1.5">
          {x.checks.map((c) => (
            <li key={c.label} className="flex items-start gap-2 text-sm">
              {c.ok ? (
                <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" />
              ) : (
                <XCircle className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
              )}
              <span className={c.ok ? undefined : "text-muted-foreground"}>{c.label}</span>
            </li>
          ))}
        </ul>
        {x.level !== "a" && (
          <p className="flex items-start gap-2 rounded-lg bg-muted/50 p-2.5 text-sm">
            <Info className="mt-0.5 size-4 shrink-0 text-info" />
            {x.fix}
          </p>
        )}
        {x.rows.length > 0 && (
          <Accordion type="single" collapsible>
            <AccordionItem value="rows" className="border-b-0">
              <AccordionTrigger className="py-1 text-sm">Rincian ({x.rows.length})</AccordionTrigger>
              <AccordionContent>
                <div className="max-h-72 overflow-y-auto rounded-lg border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        {x.columns.map((c, i) => (
                          <TableHead key={c} className={i ? "text-right" : undefined}>
                            {c}
                          </TableHead>
                        ))}
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {x.rows.map((r, n) => (
                        <TableRow key={n}>
                          {r.map((cell, i) => (
                            <TableCell key={i} className={i ? "text-right whitespace-normal" : "whitespace-normal"}>
                              {cell}
                            </TableCell>
                          ))}
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </AccordionContent>
            </AccordionItem>
          </Accordion>
        )}
      </CardContent>
    </Card>
  );
}

function Recap({ data }: { data: Data }) {
  const w = useWorkspace();
  const { summary, counts, aspects } = data.recap;
  const gaps = [
    counts.no_area > 0 && `${counts.no_area} ruangan belum diisi luasnya`,
    counts.unclassified_rooms > 0 && `${counts.unclassified_rooms} ruangan belum diisi fungsinya`,
    counts.uncategorized > 0 && `${counts.uncategorized} dari ${counts.items} barang belum berkategori`,
  ].filter(Boolean) as string[];
  const sections = aspects.reduce<Record<string, Aspect[]>>((all, x) => ((all[x.section] ||= []).push(x), all), {});
  return (
    <>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
        <StatCard label={data.levels.a} value={summary.a} tone="success" />
        <StatCard label={data.levels.b} value={summary.b} tone="info" />
        <StatCard label={data.levels.c} value={summary.c} tone="warning" />
        <StatCard label={data.levels.d} value={summary.d} tone="destructive" />
        <StatCard label="Belum ada data" value={summary.empty} />
      </div>
      {gaps.length > 0 && (
        <Alert>
          <Info />
          <AlertTitle>Lengkapi data agar rekap akurat</AlertTitle>
          <AlertDescription>
            <ul className="list-disc pl-4">
              {gaps.map((g) => (
                <li key={g}>{g}</li>
              ))}
            </ul>
            <Button size="sm" variant="outline" className="mt-2" onClick={() => w.go({ view: "inventory" })}>
              Buka Ruangan & Barang
            </Button>
          </AlertDescription>
        </Alert>
      )}
      {Object.entries(sections).map(([section, list]) => (
        <section key={section} className="flex flex-col gap-3">
          <h2 className="text-lg font-semibold tracking-tight">{section}</h2>
          <div className="grid gap-4 lg:grid-cols-2">
            {list.map((x) => (
              <AspectCard key={x.no} aspect={x} levels={data.levels} />
            ))}
          </div>
        </section>
      ))}
    </>
  );
}

function Supporting({ data, post, reload }: { data: Data; post: (v: Record<string, unknown>) => Promise<{ message?: string }>; reload: () => void }) {
  const w = useWorkspace();
  const s = data.settings;
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
      const reply = await post({ ...body, csrf: data.csrf });
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
                    <a href={url(w.config.page!, { evidence: 1 })} target="_blank" rel="noopener" className="notAJAX">
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
                  type="file"
                  accept="application/pdf,image/jpeg,image/png,image/webp"
                  className="min-w-0 flex-1"
                  onChange={(e) => setFile(e.target.files?.[0])}
                />
                <Button
                  type="button"
                  variant="outline"
                  disabled={!file}
                  onClick={() => run({ action: "evidence", evidence: file }, () => setFile(undefined))}
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
            Simpan data pendukung
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

const blank = { name: "", version: "", purpose: "", licence: "", licence_ref: "", valid_until: "", installs: "1", notes: "" };

/** Common uses of library software; anything else is typed in after choosing Lainnya. */
const purposes = [
  "Otomasi perpustakaan",
  "Repositori institusi",
  "Jurnal elektronik",
  "E-book dan basis data daring",
  "Sistem operasi",
  "Aplikasi perkantoran",
  "Antivirus dan keamanan",
  "Peramban web",
  "Pembaca PDF",
  "Pemindaian dan OCR",
  "Manajemen referensi",
  "Deteksi plagiarisme",
  "Desain grafis",
  "Penyuntingan foto dan video",
  "Pemutar multimedia",
  "Komunikasi dan rapat daring",
  "Penyimpanan cloud",
  "Basis data",
  "Server dan jaringan",
  "Akses jarak jauh",
  "Kompresi berkas",
];
const OTHER = "__other";

function SoftwareRegister({ data, post, reload }: { data: Data; post: (v: Record<string, unknown>) => Promise<{ message?: string }>; reload: () => void }) {
  const [editing, setEditing] = useState<{ id: number; values: typeof blank; other: boolean } | null>(null);
  const [remove, setRemove] = useState<Software | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const today = new Date().toISOString().slice(0, 10);
  const legal = (s: Software) => s.licence !== "tidak" && (!s.valid_until || s.valid_until >= today);

  async function run(body: Record<string, unknown>, done: () => void) {
    setBusy(true);
    setError("");
    try {
      const reply = await post({ ...body, csrf: data.csrf });
      toast.success(reply.message);
      done();
      reload();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  const set = (key: keyof typeof blank, value: string) => setEditing((e) => e && { ...e, values: { ...e.values, [key]: value } });

  return (
    <>
      <Panel
        title="Perangkat lunak"
        description="Semua aplikasi yang dipakai untuk operasional perpustakaan, termasuk sistem operasi dan aplikasi perkantoran. Open source dihitung berlisensi resmi."
        action={
          data.write && (
            <Button size="sm" onClick={() => setEditing({ id: 0, values: blank, other: false })}>
              <Plus data-icon="inline-start" />
              Tambah aplikasi
            </Button>
          )
        }
      >
        {data.software.length === 0 ? (
          <Blank icon={AppWindow} title="Belum ada aplikasi" description="Catat aplikasi seperti SLiMS, sistem operasi, dan aplikasi perkantoran beserta lisensinya." />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Aplikasi</TableHead>
                <TableHead className="hidden md:table-cell">Kegunaan</TableHead>
                <TableHead>Lisensi</TableHead>
                <TableHead className="hidden sm:table-cell">Berlaku sampai</TableHead>
                <TableHead className="hidden sm:table-cell text-right">Instalasi</TableHead>
                {data.write && <TableHead className="w-0" />}
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.software.map((s) => (
                <TableRow key={s.id}>
                  <TableCell className="font-medium whitespace-normal">
                    {s.name}
                    {s.version && <span className="ml-1 text-muted-foreground">{s.version}</span>}
                  </TableCell>
                  <TableCell className="hidden whitespace-normal text-muted-foreground md:table-cell">{s.purpose || "—"}</TableCell>
                  <TableCell>
                    <Badge variant={legal(s) ? "success" : "destructive"}>
                      {data.licences[s.licence] || s.licence}
                      {!legal(s) && s.licence !== "tidak" && " · kedaluwarsa"}
                    </Badge>
                  </TableCell>
                  <TableCell className="hidden sm:table-cell">{s.valid_until ? dateLabel(s.valid_until) : "—"}</TableCell>
                  <TableCell className="hidden text-right tabular-nums sm:table-cell">{s.installs}</TableCell>
                  {data.write && (
                    <TableCell>
                      <div className="flex justify-end gap-1">
                        <Button
                          size="icon-sm"
                          variant="ghost"
                          aria-label={`Ubah ${s.name}`}
                          onClick={() =>
                            setEditing({
                              id: s.id,
                              values: {
                                name: s.name,
                                version: s.version,
                                purpose: s.purpose,
                                licence: s.licence,
                                licence_ref: s.licence_ref,
                                valid_until: s.valid_until || "",
                                installs: String(s.installs),
                                notes: s.notes || "",
                              },
                              other: s.purpose !== "" && !purposes.includes(s.purpose),
                            })
                          }
                        >
                          <Pencil />
                        </Button>
                        <Button size="icon-sm" variant="ghost" className="text-destructive" aria-label={`Hapus ${s.name}`} onClick={() => setRemove(s)}>
                          <Trash2 />
                        </Button>
                      </div>
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Panel>
      <Dialog open={!!editing} onOpenChange={(o) => !o && !busy && setEditing(null)}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing?.id ? "Ubah aplikasi" : "Tambah aplikasi"}</DialogTitle>
            <DialogDescription>Lisensi kedaluwarsa dihitung tidak berlisensi.</DialogDescription>
          </DialogHeader>
          <ErrorBox message={error} />
          {editing && (
            <fieldset disabled={busy} className="min-w-0">
              <FieldGroup>
                <FieldGroup className="grid sm:grid-cols-[1fr_120px]">
                  <TextField label="Nama aplikasi" required value={editing.values.name} onChange={(v) => set("name", v)} placeholder="Contoh: SLiMS" />
                  <TextField label="Versi" value={editing.values.version} onChange={(v) => set("version", v)} />
                </FieldGroup>
                <Choice
                  label="Kegunaan"
                  value={editing.other ? OTHER : editing.values.purpose}
                  placeholder="Pilih kegunaan"
                  onChange={(v) =>
                    setEditing((e) => e && { ...e, other: v === OTHER, values: { ...e.values, purpose: v === OTHER ? "" : v } })
                  }
                  items={[...purposes.map((p) => ({ value: p, label: p })), { value: OTHER, label: "Lainnya…" }]}
                />
                {editing.other && (
                  <TextField
                    label="Kegunaan lainnya"
                    required
                    value={editing.values.purpose}
                    onChange={(v) => set("purpose", v)}
                    placeholder="Tulis kegunaan aplikasi"
                  />
                )}
                <FieldGroup className="grid sm:grid-cols-2">
                  <Choice
                    label="Jenis lisensi"
                    required
                    value={editing.values.licence}
                    onChange={(v) => set("licence", v)}
                    items={Object.entries(data.licences).map(([value, label]) => ({ value, label }))}
                  />
                  <TextField label="Berlaku sampai" type="date" value={editing.values.valid_until} onChange={(v) => set("valid_until", v)} />
                </FieldGroup>
                <FieldGroup className="grid sm:grid-cols-[1fr_120px]">
                  <TextField
                    label="Nomor / bukti lisensi"
                    value={editing.values.licence_ref}
                    onChange={(v) => set("licence_ref", v)}
                    placeholder="Nomor lisensi, kontrak, atau URL lisensi"
                  />
                  <TextField label="Instalasi" type="number" value={editing.values.installs} onChange={(v) => set("installs", v)} />
                </FieldGroup>
                <TextField label="Catatan" multiline value={editing.values.notes} onChange={(v) => set("notes", v)} />
              </FieldGroup>
            </fieldset>
          )}
          <DialogFooter>
            <Button variant="outline" disabled={busy} onClick={() => setEditing(null)}>
              Batal
            </Button>
            <Button
              disabled={busy}
              onClick={() => {
                if (!editing) return;
                if (editing.other && !editing.values.purpose.trim()) return setError("Tulis kegunaan aplikasi, atau pilih dari daftar.");
                run({ action: "software", record_id: editing.id, ...editing.values }, () => setEditing(null));
              }}
            >
              {busy ? "Menyimpan…" : "Simpan"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <Confirm
        open={!!remove}
        title={`Hapus ${remove?.name ?? "aplikasi"}?`}
        description="Aplikasi dihapus dari register perangkat lunak."
        action="Hapus aplikasi"
        busy={busy}
        onCancel={() => setRemove(null)}
        onConfirm={() => remove && run({ action: "software_delete", record_id: remove.id }, () => setRemove(null))}
      />
    </>
  );
}

const tabs = { recap: "Rekap", support: "Data pendukung", software: "Perangkat lunak" } as const;

export function SarprasPage() {
  const w = useWorkspace();
  const { data, error, reload, post } = usePage<Data>();
  const [tab, setTab] = useState<keyof typeof tabs>((String(w.route.tab || "") as keyof typeof tabs) in tabs ? (w.route.tab as keyof typeof tabs) : "recap");
  useEffect(() => w.dirty(false), [tab]);
  return (
    <>
      <PageHeader
        title="Rekap Sarpras"
        description="Kondisi sarana dan prasarana perpustakaan, dihitung dari data ruangan, barang, perangkat lunak, jaringan, serta pengawasan dan pemeliharaan."
        meta={data && <span className="text-xs text-muted-foreground">Dihitung {dateLabel(data.recap.generated_at)}</span>}
        actions={<Pdf label="Cetak rekap" href={(style) => url(w.config.page!, { pdf: style })} />}
      />
      <Tabs value={tab} onValueChange={(v) => setTab(v as keyof typeof tabs)}>
        <TabsList variant="line" className="w-full justify-start border-b">
          {Object.entries(tabs).map(([value, label]) => (
            <TabsTrigger key={value} value={value} className="flex-none">
              {label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>
      <ErrorBox message={error} />
      {!data ? (
        !error && <Loading />
      ) : tab === "recap" ? (
        <Recap data={data} />
      ) : tab === "support" ? (
        <Supporting key={data.recap.generated_at} data={data} post={post} reload={reload} />
      ) : (
        <SoftwareRegister data={data} post={post} reload={reload} />
      )}
    </>
  );
}
