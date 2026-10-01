import { useState } from "react";
import { toast } from "sonner";
import { AppWindow, ChartNoAxesColumn, Pencil, Plus, Trash2 } from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "./components/ui/table";
import { FieldGroup } from "./components/ui/field";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "./components/ui/dialog";
import { useWorkspace } from "./context";
import { dateLabel } from "./api";
import { Blank, Choice, ErrorBox, Loading, PageHeader, TextField } from "./shared";
import { Confirm, usePage } from "./settings";

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
type Data = { software: Software[]; licences: Record<string, string>; write: boolean; csrf: string };

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
      <Dialog open={!!editing} onOpenChange={(o) => !o && !busy && setEditing(null)}>
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
                run({ action: "software", record_id: editing.id, ...editing.values }, () => setEditing(null), setError);
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
        onConfirm={() => remove && run({ action: "software_delete", record_id: remove.id }, () => setRemove(null), (m) => toast.error(m))}
      />
    </>
  );
}
