import { useId, useState } from "react";
import { toast } from "sonner";
import { AppWindow, ChartNoAxesColumn, FileText, Paperclip, Pencil, Plus, Trash2 } from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "./components/ui/table";
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "./components/ui/field";
import { Input } from "./components/ui/input";
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
import { Blank, Choice, ErrorBox, ImagePreview, Loading, PageHeader, TextField, previewPdf } from "./shared";
import { Confirm, usePage } from "./settings";

/** A file that shows an application's licence: a certificate, an invoice, a screenshot. */
type LicenceFile = { id: number; title: string; mime: string; created_at: string };
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
  /** Null until the plugin's migration 16 has run. */
  files: LicenceFile[] | null;
};
/** `available` is false until the plugin's migration 16 has run. */
type Data = { software: Software[]; licences: Record<string, string>; files: { available: boolean; max: number; max_bytes: number }; write: boolean; csrf: string };
type Post = (values: Record<string, unknown>) => Promise<{ message?: string }>;

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

export function SoftwarePage() {
  const w = useWorkspace();
  const { data, error: loadError, reload, post } = usePage<Data>(w.config.pages!.software);
  const [editing, setEditing] = useState<{ id: number; values: typeof blank; other: boolean } | null>(null);
  const [remove, setRemove] = useState<Software | null>(null);
  // The application whose licence files are open, by id: the list reloads under the dialog.
  const [proofOf, setProofOf] = useState<number | null>(null);
  const proof = data?.software.find((s) => s.id === proofOf);
  // Licence files chosen in the form: uploaded once the application itself is saved.
  const [pending, setPending] = useState<File[]>([]);
  const [picker, setPicker] = useState(0);
  const proofId = useId();
  const edited = data?.software.find((s) => s.id === editing?.id);
  const megabytes = data ? Math.round(data.files.max_bytes / 1024 / 1024) : 5;
  const room = data ? data.files.max - (edited?.files?.length ?? 0) : 0;
  const pendingError = !data
    ? ""
    : pending.some((f) => !fileTypes.includes(f.type) || f.size > data.files.max_bytes)
      ? `Gunakan PDF, JPEG, PNG, atau WebP maksimal ${megabytes} MB.`
      : pending.length > room
        ? `Satu aplikasi memuat paling banyak ${data.files.max} berkas. Pilih paling banyak ${Math.max(room, 0)} berkas lagi.`
        : "";
  const close = () => {
    setEditing(null);
    setPending([]);
    setPicker((n) => n + 1);
  };

  async function save() {
    if (!editing || !data) return;
    if (editing.other && !editing.values.purpose.trim()) return setError("Tulis kegunaan aplikasi, atau pilih dari daftar.");
    if (pendingError) return setError(pendingError);
    setBusy(true);
    setError("");
    try {
      const reply = (await post({ action: "software", record_id: editing.id, ...editing.values, csrf: data.csrf })) as { message?: string; record?: number };
      const id = editing.id || Number(reply.record);
      // The application is saved: a failed upload must not save it a second time on the next try.
      setEditing((e) => e && { ...e, id });
      for (const file of pending) {
        await post({ action: "licence_upload", software_id: id, title: "", file, csrf: data.csrf });
        setPending((files) => files.filter((f) => f !== file));
      }
      toast.success(reply.message);
      close();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
      reload();
    }
  }
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const today = w.config.today;
  const legal = (s: Software) => s.licence !== "tidak" && (!s.valid_until || s.valid_until >= today);

  async function run(body: Record<string, unknown>, done: () => void, fail: (message: string) => void) {
    setBusy(true);
    setError("");
    try {
      const reply = await post({ ...body, csrf: data!.csrf });
      toast.success(reply.message);
      done();
      reload();
    } catch (e) {
      fail((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  const set = (key: keyof typeof blank, value: string) => setEditing((e) => e && { ...e, values: { ...e.values, [key]: value } });
  const add = () => {
    setError("");
    setEditing({ id: 0, values: blank, other: false });
  };
  const edit = (s: Software) => {
    setError("");
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
    });
  };

  return (
    <>
      <PageHeader
        title="Perangkat Lunak"
        description="Semua aplikasi yang dipakai untuk operasional perpustakaan, termasuk sistem operasi dan aplikasi perkantoran. Open source dihitung berlisensi resmi."
        meta={
          data &&
          data.software.length > 0 && (
            <span className="text-xs text-muted-foreground">
              {data.software.filter(legal).length} dari {data.software.length} aplikasi berlisensi resmi
            </span>
          )
        }
        actions={
          <>
            <Button variant="outline" onClick={() => w.go({ view: "sarpras" })}>
              <ChartNoAxesColumn data-icon="inline-start" />
              Lihat Rekap Sarpras
            </Button>
            {data?.write && (
              <Button onClick={add}>
                <Plus data-icon="inline-start" />
                Tambah aplikasi
              </Button>
            )}
          </>
        }
      />
      <ErrorBox message={loadError} />
      {!data ? (
        !loadError && <Loading />
      ) : data.software.length === 0 ? (
        <Blank icon={AppWindow} title="Belum ada aplikasi" description="Catat aplikasi seperti SLiMS, sistem operasi, dan aplikasi perkantoran beserta lisensinya.">
          {data.write && (
            <Button onClick={add}>
              <Plus data-icon="inline-start" />
              Tambah aplikasi
            </Button>
          )}
        </Blank>
      ) : (
        <div className="overflow-hidden rounded-xl border">
          <Table>
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead>Aplikasi</TableHead>
                <TableHead className="hidden md:table-cell">Kegunaan</TableHead>
                <TableHead>Lisensi</TableHead>
                <TableHead className="hidden sm:table-cell">Berlaku sampai</TableHead>
                <TableHead className="hidden text-right sm:table-cell">Instalasi</TableHead>
                <TableHead>Bukti</TableHead>
                {data.write && (
                  <TableHead className="w-0">
                    <span className="sr-only">Tindakan</span>
                  </TableHead>
                )}
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
                  <TableCell>
                    <Button size="sm" variant="ghost" aria-label={`Bukti lisensi ${s.name}`} onClick={() => setProofOf(s.id)}>
                      <Paperclip data-icon="inline-start" />
                      {s.files?.length ?? 0}
                    </Button>
                  </TableCell>
                  {data.write && (
                    <TableCell>
                      <div className="flex justify-end gap-1">
                        <Button size="icon-sm" variant="ghost" aria-label={`Ubah ${s.name}`} onClick={() => edit(s)}>
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
        </div>
      )}
      <Dialog open={!!editing} onOpenChange={(o) => !o && !busy && close()}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing?.id ? "Ubah aplikasi" : "Tambah aplikasi"}</DialogTitle>
            <DialogDescription>Lisensi kedaluwarsa dihitung tidak berlisensi.</DialogDescription>
          </DialogHeader>
          <ErrorBox message={error} />
          {editing && data && (
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
                <Field data-invalid={!!pendingError}>
                  <FieldLabel htmlFor={proofId}>Berkas bukti lisensi</FieldLabel>
                  {!data.files.available ? (
                    <p className="text-sm text-muted-foreground">Jalankan migrasi plugin hingga versi 16 di System → Plugins untuk menyimpan bukti lisensi.</p>
                  ) : (
                    <>
                      {edited && <LicenceFileList software={edited} data={data} page={w.config.pages!.software} post={post} reload={reload} onError={setError} />}
                      <Input
                        key={picker}
                        id={proofId}
                        type="file"
                        multiple
                        accept={fileTypes.join(",")}
                        onChange={(e) => {
                          setPending(Array.from(e.target.files ?? []));
                          setError("");
                        }}
                      />
                      {pendingError ? (
                        <FieldError>{pendingError}</FieldError>
                      ) : (
                        <FieldDescription>
                          Opsional. Sertifikat lisensi, faktur, atau tangkapan layar halaman lisensi. PDF, JPEG, PNG, atau WebP, maksimal {megabytes} MB, paling banyak{" "}
                          {data.files.max} berkas per aplikasi. Diunggah saat Anda menyimpan.
                        </FieldDescription>
                      )}
                    </>
                  )}
                </Field>
                <TextField label="Catatan" multiline value={editing.values.notes} onChange={(v) => set("notes", v)} />
              </FieldGroup>
            </fieldset>
          )}
          <DialogFooter>
            <Button variant="outline" disabled={busy} onClick={close}>
              Batal
            </Button>
            <Button disabled={busy} onClick={save}>
              {busy ? (pending.length ? "Menyimpan dan mengunggah…" : "Menyimpan…") : "Simpan"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      {proof && data && <LicenceFiles software={proof} data={data} page={w.config.pages!.software} post={post} reload={reload} onClose={() => setProofOf(null)} />}
      <Confirm
        open={!!remove}
        title={`Hapus ${remove?.name ?? "aplikasi"}?`}
        description="Aplikasi dihapus dari register perangkat lunak."
        action="Hapus aplikasi"
        busy={busy}
        onCancel={() => setRemove(null)}
        onConfirm={() => remove && run({ action: "software_delete", record_id: remove.id }, () => setRemove(null), (m) => toast.error(m))}
      />
    </>
  );
}

const fileTypes = ["application/pdf", "image/jpeg", "image/png", "image/webp"];

/** An application's licence files: opened by anyone who may see the register, removed by those who may write it. */
function LicenceFileList({ software, data, page, post, reload, onError }: { software: Software; data: Data; page: string; post: Post; reload: () => void; onError: (message: string) => void }) {
  const w = useWorkspace();
  const [viewing, setViewing] = useState<LicenceFile>();
  const [removing, setRemoving] = useState<LicenceFile>();
  const [busy, setBusy] = useState(false);
  const files = software.files ?? [];
  const href = (f: LicenceFile) => url(page, { licence_file: f.id });
  // Both kinds open in a popup over the page: a PDF in the print viewer, a picture in a dialog.
  const open = (f: LicenceFile) => (f.mime === "application/pdf" ? previewPdf(w.config, href(f), f.title) : setViewing(f));

  async function remove() {
    if (!removing) return;
    setBusy(true);
    onError("");
    try {
      const reply = await post({ action: "licence_delete", software_id: software.id, record_id: removing.id, csrf: data.csrf });
      toast.success(reply.message);
      setRemoving(undefined);
      reload();
    } catch (e) {
      onError((e as Error).message);
      setRemoving(undefined);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      {files.length > 0 && (
        <ul className="flex flex-col gap-2">
          {files.map((f) => (
            <li key={f.id} className="flex flex-wrap items-center gap-3 rounded-xl border p-3">
              <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <FileText className="size-4" />
              </span>
              <div className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-sm font-medium">{f.title}</span>
                <span className="text-xs text-muted-foreground">Diunggah {dateLabel(f.created_at)}</span>
              </div>
              <div className="flex items-center gap-1">
                <Button type="button" size="sm" variant="outline" aria-label={`Lihat ${f.title}`} onClick={() => open(f)}>
                  Lihat
                </Button>
                {data.write && (
                  <Button type="button" size="icon-sm" variant="ghost" className="text-destructive" aria-label={`Hapus ${f.title}`} disabled={busy} onClick={() => setRemoving(f)}>
                    <Trash2 />
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
      <ImagePreview
        image={viewing && { url: href(viewing), title: viewing.title, description: `Bukti lisensi ${software.name}, diunggah ${dateLabel(viewing.created_at)}.` }}
        onClose={() => setViewing(undefined)}
      />
      <Confirm
        open={!!removing}
        title="Hapus bukti lisensi?"
        description={`${removing?.title || "Berkas"} dihapus permanen.`}
        action="Hapus bukti"
        busy={busy}
        onCancel={() => setRemoving(undefined)}
        onConfirm={remove}
      />
    </>
  );
}

/** One application's licence files in a dialog of their own, opened from its row: where a reader sees them. */
function LicenceFiles({ software, data, page, post, reload, onClose }: { software: Software; data: Data; page: string; post: Post; reload: () => void; onClose: () => void }) {
  const fileId = useId();
  const titleId = useId();
  const [file, setFile] = useState<File>();
  const [title, setTitle] = useState("");
  const [picker, setPicker] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const files = software.files;
  const megabytes = Math.round(data.files.max_bytes / 1024 / 1024);
  const fileError = file && (!fileTypes.includes(file.type) || file.size > data.files.max_bytes) ? `Gunakan PDF, JPEG, PNG, atau WebP maksimal ${megabytes} MB.` : "";

  async function upload() {
    if (!file) return;
    setBusy(true);
    setError("");
    try {
      const reply = await post({ action: "licence_upload", software_id: software.id, title, file, csrf: data.csrf });
      toast.success(reply.message);
      setFile(undefined);
      setTitle("");
      setPicker((n) => n + 1);
      reload();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && !busy && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Bukti lisensi {software.name}</DialogTitle>
          <DialogDescription>Sertifikat lisensi, faktur, atau tangkapan layar halaman lisensi. Tidak mengubah hitungan di Rekap Sarpras.</DialogDescription>
        </DialogHeader>
        <ErrorBox message={error} />
        {files === null ? (
          <p className="text-sm text-muted-foreground">Jalankan migrasi plugin hingga versi 16 di System → Plugins untuk menyimpan bukti lisensi.</p>
        ) : (
          <>
            {files.length === 0 && <p className="text-sm text-muted-foreground">Belum ada bukti lisensi.</p>}
            <LicenceFileList software={software} data={data} page={page} post={post} reload={reload} onError={setError} />
            {data.write &&
              (files.length >= data.files.max ? (
                <p className="text-sm text-muted-foreground">Satu aplikasi memuat paling banyak {data.files.max} berkas. Hapus berkas yang tidak dipakai untuk menambah.</p>
              ) : (
                <fieldset disabled={busy} className="min-w-0">
                  <FieldGroup>
                    <Field data-invalid={!!fileError}>
                      <FieldLabel htmlFor={fileId}>Berkas bukti</FieldLabel>
                      <Input
                        key={picker}
                        id={fileId}
                        type="file"
                        accept={fileTypes.join(",")}
                        onChange={(e) => {
                          setFile(e.target.files?.[0]);
                          setError("");
                        }}
                      />
                      {fileError ? <FieldError>{fileError}</FieldError> : <FieldDescription>PDF, JPEG, PNG, atau WebP, maksimal {megabytes} MB.</FieldDescription>}
                    </Field>
                    <Field>
                      <FieldLabel htmlFor={titleId}>Judul</FieldLabel>
                      <Input id={titleId} maxLength={150} value={title} placeholder="Contoh: Sertifikat lisensi" onChange={(e) => setTitle(e.target.value)} />
                      <FieldDescription>Opsional. Tanpa judul, nama berkas yang dipakai.</FieldDescription>
                    </Field>
                  </FieldGroup>
                </fieldset>
              ))}
          </>
        )}
        <DialogFooter>
          <Button variant="outline" disabled={busy} onClick={onClose}>
            Tutup
          </Button>
          {data.write && files !== null && files.length < data.files.max && (
            <Button disabled={busy || !file || !!fileError} onClick={upload}>
              {busy ? "Mengunggah…" : "Unggah"}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
