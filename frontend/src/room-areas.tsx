import { useId, useState } from "react";
import { toast } from "sonner";
import { FileText, LayoutGrid, Map as MapIcon, Pencil, Plus, Trash2, Upload as UploadIcon } from "lucide-react";
import { Button } from "./components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "./components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "./components/ui/dialog";
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "./components/ui/field";
import { Input } from "./components/ui/input";
import { useData, useWorkspace } from "./context";
import { dateLabel } from "./api";
import { Actions, Blank, Choice, ErrorBox, ImagePreview, Loading, previewPdf } from "./shared";
import { Confirm } from "./settings";

type Area = { id: number; type: string; name: string };
type Plan = { id: number; title: string; mime: string; created_at: string; url: string };
type RoomDetails = { areas: Area[]; plans: Plan[]; maxPlanBytes: number; maxPlans: number };

const groupHints: Record<string, string> = {
  dasar: "Empat area ini dinilai di Rekap Sarpras: koleksi, baca, kerja staf, dan layanan.",
  pendukung: "Area tambahan yang mendukung layanan.",
  umum: "Toilet, musala, parkir, dan sejenisnya. Dihitung sebagai fasilitas umum di Rekap Sarpras.",
};

/** The areas inside a room: what the room is used for. */
export function RoomAreasTab({ room }: { room: string }) {
  const w = useWorkspace();
  const lists = w.options.sarpras;
  const { data, error, loading } = useData<RoomDetails>("areas", { room });
  const [editing, setEditing] = useState<Area | "new">();
  const [removing, setRemoving] = useState<Area>();
  const [busy, setBusy] = useState(false);
  const label = (area: Area) => lists.areaTypes[area.type]?.label || area.type;

  async function remove() {
    if (!removing) return;
    setBusy(true);
    try {
      const reply = await w.mutate({ form_action: "delete_area", location_id: room, record_id: removing.id }, undefined, true);
      toast.success(reply.message || "Area dihapus.");
      setRemoving(undefined);
      w.refresh();
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (error) return <ErrorBox message={error} />;
  if (loading && !data) return <Loading />;
  if (!data) return null;
  const add = w.config.write && (
    <Button onClick={() => setEditing("new")}>
      <Plus data-icon="inline-start" />
      Tambah area
    </Button>
  );
  return (
    <>
      {data.areas.length === 0 ? (
        <Blank
          icon={LayoutGrid}
          title="Belum ada area"
          description="Catat area yang ada di ruangan ini, misalnya area baca, area koleksi, atau toilet. Rekap Sarpras menghitung area layanan dan fasilitas umum dari sini."
        >
          {add}
        </Blank>
      ) : (
        <>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm text-muted-foreground">
              {data.areas.length} area di ruangan ini. Rekap Sarpras menghitung area layanan dan fasilitas umum dari sini.
            </p>
            {add}
          </div>
          <div className="grid gap-4 lg:grid-cols-3">
            {Object.entries(lists.areaGroups).map(([group, title]) => {
              const areas = data.areas.filter((area) => lists.areaTypes[area.type]?.group === group);
              return (
                <Card key={group}>
                  <CardHeader>
                    <CardTitle>{title}</CardTitle>
                    <CardDescription>{groupHints[group]}</CardDescription>
                  </CardHeader>
                  <CardContent>
                    {areas.length === 0 ? (
                      <p className="text-sm text-muted-foreground">Belum ada.</p>
                    ) : (
                      <ul className="flex flex-col divide-y">
                        {areas.map((area) => (
                          <li key={area.id} className="flex items-center gap-2 py-2 first:pt-0 last:pb-0">
                            <div className="min-w-0 flex-1">
                              <p className="text-sm font-medium">{label(area)}</p>
                              {area.name && <p className="truncate text-xs text-muted-foreground">{area.name}</p>}
                            </div>
                            {w.config.write && (
                              <Actions
                                label={`Tindakan area ${label(area)}`}
                                items={[
                                  { label: "Ubah", icon: Pencil, run: () => setEditing(area) },
                                  { label: "Hapus", icon: Trash2, destructive: true, run: () => setRemoving(area) },
                                ]}
                              />
                            )}
                          </li>
                        ))}
                      </ul>
                    )}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        </>
      )}
      {editing && (
        <AreaDialog
          room={room}
          area={editing === "new" ? undefined : editing}
          onClose={() => setEditing(undefined)}
          onSaved={() => {
            setEditing(undefined);
            w.refresh();
          }}
        />
      )}
      <Confirm
        open={!!removing}
        title="Hapus area?"
        description={`${removing ? label(removing) : "Area"}${removing?.name ? ` (${removing.name})` : ""} dihapus dari ruangan ini. Barang di ruangan tidak berubah.`}
        action="Hapus area"
        busy={busy}
        onCancel={() => setRemoving(undefined)}
        onConfirm={remove}
      />
    </>
  );
}

function AreaDialog({ room, area, onClose, onSaved }: { room: string; area?: Area; onClose: () => void; onSaved: () => void }) {
  const w = useWorkspace();
  const lists = w.options.sarpras;
  const id = useId();
  const [type, setType] = useState(area?.type || "");
  const [name, setName] = useState(area?.name || "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  async function save() {
    if (!type) {
      setError("Pilih jenis area.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      const reply = await w.mutate({ form_action: "save_area", location_id: room, record_id: area?.id || 0, type, name }, undefined, true);
      toast.success(reply.message || "Area tersimpan.");
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
          <DialogTitle>{area ? "Ubah area" : "Tambah area"}</DialogTitle>
          <DialogDescription>Satu ruangan boleh memiliki beberapa area.</DialogDescription>
        </DialogHeader>
        <ErrorBox message={error} />
        <FieldGroup>
          <Choice
            label="Jenis area"
            required
            value={type}
            onChange={setType}
            placeholder="Pilih jenis area"
            items={Object.entries(lists.areaTypes).map(([value, t]) => ({ value, label: `${t.label} · ${lists.areaGroups[t.group] || t.group}` }))}
          />
          <Field>
            <FieldLabel htmlFor={id}>Nama area</FieldLabel>
            <Input id={id} maxLength={150} value={name} placeholder="Contoh: Pojok baca anak" onChange={(e) => setName(e.target.value)} />
            <FieldDescription>Opsional. Isi bila ruangan memiliki lebih dari satu area sejenis.</FieldDescription>
          </Field>
        </FieldGroup>
        <DialogFooter>
          <Button variant="outline" disabled={busy} onClick={onClose}>
            Batal
          </Button>
          <Button disabled={busy} onClick={save}>
            {busy ? "Menyimpan…" : "Simpan"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

const planTypes = ["application/pdf", "image/jpeg", "image/png", "image/webp"];

/** A room's floor plans: pictures or PDFs, several per room. */
export function RoomPlansTab({ room }: { room: string }) {
  const w = useWorkspace();
  const { data, error, loading } = useData<RoomDetails>("areas", { room });
  const [uploading, setUploading] = useState(false);
  const [removing, setRemoving] = useState<Plan>();
  const [viewing, setViewing] = useState<Plan>();
  const [busy, setBusy] = useState(false);
  // Both kinds open in a popup over the page: a PDF in the print viewer, a picture in a dialog.
  const open = (plan: Plan) => (plan.mime === "application/pdf" ? previewPdf(w.config, plan.url, plan.title) : setViewing(plan));

  async function remove() {
    if (!removing) return;
    setBusy(true);
    try {
      const reply = await w.mutate({ form_action: "delete_plan", location_id: room, record_id: removing.id }, undefined, true);
      toast.success(reply.message || "Denah dihapus.");
      setRemoving(undefined);
      w.refresh();
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (error) return <ErrorBox message={error} />;
  if (loading && !data) return <Loading />;
  if (!data) return null;
  const megabytes = Math.round(data.maxPlanBytes / 1024 / 1024);
  const add = w.config.write && data.plans.length < data.maxPlans && (
    <Button onClick={() => setUploading(true)}>
      <UploadIcon data-icon="inline-start" />
      Unggah denah
    </Button>
  );
  return (
    <>
      {data.plans.length === 0 ? (
        <Blank
          icon={MapIcon}
          title="Belum ada denah"
          description={`Unggah denah ruangan sebagai gambar atau PDF, maksimal ${megabytes} MB. Satu ruangan boleh memiliki beberapa denah, misalnya per lantai.`}
        >
          {add}
        </Blank>
      ) : (
        <>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm text-muted-foreground">
              {data.plans.length} dari {data.maxPlans} denah. Hanya petugas yang masuk ke SLiMS yang dapat membukanya.
            </p>
            {add}
          </div>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {data.plans.map((plan) => {
              const pdf = plan.mime === "application/pdf";
              return (
                <Card key={plan.id} className="overflow-hidden pt-0">
                  <button
                    type="button"
                    className="flex aspect-[4/3] w-full flex-col items-center justify-center gap-2 border-b bg-muted text-muted-foreground hover:bg-muted/70"
                    onClick={() => open(plan)}
                    aria-label={`Buka ${plan.title}`}
                  >
                    {pdf ? (
                      <>
                        <FileText className="size-8" />
                        <span className="text-xs font-medium">PDF</span>
                      </>
                    ) : (
                      <img src={plan.url} alt={plan.title} loading="lazy" className="size-full object-contain" />
                    )}
                  </button>
                  <CardContent className="flex items-center gap-2">
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">{plan.title}</p>
                      <p className="text-xs text-muted-foreground">Diunggah {dateLabel(plan.created_at)}</p>
                    </div>
                    <Button variant="outline" size="sm" onClick={() => open(plan)}>
                      Buka
                    </Button>
                    {w.config.write && (
                      <Actions
                        label={`Tindakan denah ${plan.title}`}
                        items={[{ label: "Hapus", icon: Trash2, destructive: true, run: () => setRemoving(plan) }]}
                      />
                    )}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        </>
      )}
      {uploading && (
        <PlanDialog
          room={room}
          maxBytes={data.maxPlanBytes}
          onClose={() => setUploading(false)}
          onSaved={() => {
            setUploading(false);
            w.refresh();
          }}
        />
      )}
      <ImagePreview
        image={viewing && { url: viewing.url, title: viewing.title, description: `Denah ruangan, diunggah ${dateLabel(viewing.created_at)}.` }}
        onClose={() => setViewing(undefined)}
      />
      <Confirm
        open={!!removing}
        title="Hapus denah?"
        description={`${removing?.title || "Denah"} dan berkasnya dihapus permanen.`}
        action="Hapus denah"
        busy={busy}
        onCancel={() => setRemoving(undefined)}
        onConfirm={remove}
      />
    </>
  );
}

function PlanDialog({ room, maxBytes, onClose, onSaved }: { room: string; maxBytes: number; onClose: () => void; onSaved: () => void }) {
  const w = useWorkspace();
  const fileId = useId();
  const titleId = useId();
  const [file, setFile] = useState<File>();
  const [title, setTitle] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const megabytes = Math.round(maxBytes / 1024 / 1024);
  const fileError = file && (!planTypes.includes(file.type) || file.size > maxBytes) ? `Gunakan PDF, JPEG, PNG, atau WebP maksimal ${megabytes} MB.` : "";
  async function save() {
    if (!file || fileError) {
      setError(fileError || "Pilih berkas denah.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      const files = new FormData();
      files.append("plan", file);
      const reply = await w.mutate({ form_action: "upload_plan", location_id: room, title }, files, true);
      toast.success(reply.message || "Denah tersimpan.");
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
          <DialogTitle>Unggah denah</DialogTitle>
          <DialogDescription>Gambar atau PDF denah ruangan, maksimal {megabytes} MB.</DialogDescription>
        </DialogHeader>
        <ErrorBox message={error} />
        <FieldGroup>
          <Field data-invalid={!!fileError}>
            <FieldLabel htmlFor={fileId}>Berkas denah</FieldLabel>
            <Input
              id={fileId}
              type="file"
              accept={planTypes.join(",")}
              onChange={(e) => {
                setFile(e.target.files?.[0]);
                setError("");
              }}
            />
            {fileError && <FieldError>{fileError}</FieldError>}
          </Field>
          <Field>
            <FieldLabel htmlFor={titleId}>Judul</FieldLabel>
            <Input id={titleId} maxLength={150} value={title} placeholder="Contoh: Lantai 1" onChange={(e) => setTitle(e.target.value)} />
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
