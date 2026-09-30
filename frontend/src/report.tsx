import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { Send, CheckCheck, Info } from "lucide-react";
import { Button } from "./components/ui/button";
import { Alert, AlertDescription } from "./components/ui/alert";
import { Field, FieldLabel, FieldGroup, FieldDescription } from "./components/ui/field";
import { Switch } from "./components/ui/switch";
import { ToggleGroup, ToggleGroupItem } from "./components/ui/toggle-group";
import { useWorkspace } from "./context";
import { read, groups } from "./api";
import { roomLabel } from "./rooms";
import { PageHeader, Panel, Choice, TextField, Upload, ErrorBox, ActionBar, entries, conditions } from "./shared";
import type { Id } from "./types";

type Asset = { id: Id; item_name: string; item_code: string; item_condition: string };
const OTHER = "__other";
const actionKinds: Record<string, string> = { repair: "Perbaikan", maintenance: "Pemeliharaan", none: "Tanpa pekerjaan" };

function addDays(date: string, days: number) {
  const d = new Date(date + "T12:00:00");
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

/**
 * One form for "something broke": who reports, what, and who handles it. When the reporter
 * handles it personally and it is already done, the repair is recorded and closed in the same save.
 */
export function ReportPage() {
  const w = useWorkspace();
  const me = String(w.config.uid);
  const [values, setValues] = useState({
    location_id: String(w.route.room || ""),
    item_id: String(w.route.item_id || ""),
    object: "",
    group: "Sarana",
    problem: "",
    handler_id: "",
    priority: "medium",
    deadline: addDays(w.config.today, 7),
    kind: "repair",
    description: "",
    performed_date: w.config.today,
    cost: "",
  });
  const [fixed, setFixed] = useState(false);
  const [assets, setAssets] = useState<Asset[]>([]);
  const [photos, setPhotos] = useState<File[]>([]);
  const [fixPhotos, setFixPhotos] = useState<File[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const lock = useRef(false);
  const self = values.handler_id === me;
  const update = (key: string, value: string) => {
    setValues((v) => ({ ...v, [key]: value, ...(key === "location_id" ? { item_id: "" } : {}) }));
    w.dirty(true);
  };

  useEffect(() => {
    if (!values.location_id) {
      setAssets([]);
      return;
    }
    const controller = new AbortController();
    read<Asset[]>(w.config, "assets", { room: values.location_id }, controller.signal)
      .then(setAssets)
      .catch((e) => e.name !== "AbortError" && setError(e.message));
    return () => controller.abort();
  }, [values.location_id]);

  async function save() {
    if (lock.current) return;
    const errors: Record<string, string> = {};
    if (!values.location_id) errors.location_id = "Pilih ruangan.";
    if (!values.item_id) errors.item_id = "Pilih barang, atau pilih Lainnya.";
    if (values.item_id === OTHER && !values.object.trim()) errors.object = "Sebutkan apa yang rusak.";
    if (!values.problem.trim()) errors.problem = "Jelaskan kerusakannya.";
    if (!values.handler_id) errors.handler_id = "Pilih siapa yang menangani.";
    if (fixed && self) {
      if (!values.description.trim()) errors.description = values.kind === "none" ? "Isi alasannya." : "Isi uraian pekerjaan.";
      if (values.kind !== "none" && !fixPhotos.length) errors.fix_photos = "Tambahkan minimal satu foto hasil.";
    }
    setErrors(errors);
    if (Object.keys(errors).length) {
      setError("Lengkapi isian yang ditandai.");
      return;
    }
    lock.current = true;
    setBusy(true);
    setError("");
    try {
      const body = new FormData();
      photos.forEach((f) => body.append("photos[]", f));
      if (fixed && self) fixPhotos.forEach((f) => body.append("fix_photos[]", f));
      const reply = await w.mutate(
        {
          watch_action: "report",
          ...values,
          item_id: values.item_id === OTHER ? "" : values.item_id,
          fixed: fixed && self ? "1" : "",
        },
        body,
      );
      w.dirty(false);
      const handler = w.options.users.find((u) => String(u.user_id) === values.handler_id)?.realname;
      toast.success(fixed && self ? "Perbaikan tercatat dan selesai." : `Laporan dikirim ke ${handler}.`);
      const record = reply.url ? new URL(reply.url, window.location.href).searchParams.get("record") : null;
      w.go(record ? { view: "finding", record } : { view: "tasks", kind: "findings" }, true);
      w.refresh();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      lock.current = false;
      setBusy(false);
    }
  }

  const asset = assets.find((a) => String(a.id) === values.item_id);
  return (
    <>
      <PageHeader
        crumbs={[{ label: "Tugas", route: { view: "tasks" } }]}
        title="Lapor kerusakan"
        description="Catat barang atau fasilitas yang rusak dan tentukan siapa yang menanganinya. Setelah selesai ditangani, Anda sebagai pelapor yang memverifikasi."
      />
      <ErrorBox message={error} />
      <fieldset disabled={busy || !w.config.write} className="grid min-w-0 gap-6 lg:grid-cols-2">
        <Panel title="Apa yang rusak">
          <FieldGroup>
            <Choice
              label="Ruangan"
              required
              error={errors.location_id}
              value={values.location_id}
              onChange={(v) => update("location_id", v)}
              items={w.options.rooms.map((x) => ({ value: x.id, label: roomLabel(x, w.options.libraries) }))}
            />
            <Choice
              label="Barang"
              required
              disabled={!values.location_id}
              error={errors.item_id}
              value={values.item_id}
              placeholder={values.location_id ? "Pilih barang" : "Pilih ruangan dulu"}
              onChange={(v) => update("item_id", v)}
              items={[
                ...assets.map((a) => ({ value: a.id, label: `${a.item_name}${a.item_code ? ` (${a.item_code})` : ""}` })),
                { value: OTHER, label: "Lainnya (bukan barang inventaris)" },
              ]}
              description={asset ? `Kondisi tercatat: ${conditions[asset.item_condition] || "—"}` : undefined}
            />
            {values.item_id === OTHER && (
              <FieldGroup className="grid sm:grid-cols-[1fr_180px]">
                <TextField
                  label="Objek"
                  required
                  error={errors.object}
                  placeholder="Contoh: Stop kontak dekat meja sirkulasi"
                  value={values.object}
                  onChange={(v) => update("object", v)}
                />
                <Choice
                  label="Kelompok"
                  value={values.group}
                  onChange={(v) => update("group", v)}
                  items={groups.map((g) => ({ value: g, label: g }))}
                />
              </FieldGroup>
            )}
            <TextField
              label="Uraian kerusakan"
              required
              multiline
              error={errors.problem}
              placeholder="Contoh: Komputer mati total saat dinyalakan, tidak ada lampu indikator."
              value={values.problem}
              onChange={(v) => update("problem", v)}
            />
            <Upload
              label="Foto kerusakan (opsional)"
              files={photos}
              onChange={(f) => {
                setPhotos(f);
                w.dirty(true);
              }}
            />
          </FieldGroup>
        </Panel>
        <div className="flex flex-col gap-6">
          <Panel title="Siapa yang menangani">
            <FieldGroup>
              <Choice
                label="Ditujukan kepada"
                required
                error={errors.handler_id}
                value={values.handler_id}
                onChange={(v) => {
                  update("handler_id", v);
                  if (v !== me) setFixed(false);
                }}
                items={w.options.users.map((u) => ({
                  value: u.user_id,
                  label: String(u.user_id) === me ? `${u.realname} (saya sendiri)` : u.realname,
                }))}
              />
              <Field>
                <FieldLabel>Prioritas</FieldLabel>
                <ToggleGroup
                  type="single"
                  variant="outline"
                  className="w-full"
                  value={values.priority}
                  onValueChange={(v) => v && update("priority", v)}
                >
                  {entries(w.options.priorities).map((p) => (
                    <ToggleGroupItem key={p.value} value={p.value} className="flex-1">
                      {p.label}
                    </ToggleGroupItem>
                  ))}
                </ToggleGroup>
              </Field>
              <TextField
                label="Tenggat"
                type="date"
                min={w.config.today}
                value={values.deadline}
                onChange={(v) => update("deadline", v)}
              />
            </FieldGroup>
          </Panel>
          {self && (
            <Panel title="Sudah Anda tangani?">
              <FieldGroup>
                <Field orientation="horizontal">
                  <Switch id="report-fixed" checked={fixed} onCheckedChange={setFixed} />
                  <FieldLabel htmlFor="report-fixed">Ya, sudah saya tangani sekarang</FieldLabel>
                </Field>
                {fixed ? (
                  <>
                    <ToggleGroup
                      type="single"
                      variant="outline"
                      className="w-full"
                      value={values.kind}
                      onValueChange={(v) => v && update("kind", v)}
                      aria-label="Jenis tindakan"
                    >
                      {Object.entries(actionKinds).map(([value, label]) => (
                        <ToggleGroupItem key={value} value={value} className="flex-1">
                          {label}
                        </ToggleGroupItem>
                      ))}
                    </ToggleGroup>
                    <TextField
                      label={values.kind === "none" ? "Alasan tanpa pekerjaan" : "Uraian pekerjaan"}
                      required
                      multiline
                      rows={2}
                      error={errors.description}
                      placeholder="Contoh: Mengganti power supply 450W."
                      value={values.description}
                      onChange={(v) => update("description", v)}
                    />
                    <FieldGroup className="grid sm:grid-cols-2">
                      <TextField
                        label="Tanggal pekerjaan"
                        type="date"
                        max={w.config.today}
                        value={values.performed_date}
                        onChange={(v) => update("performed_date", v)}
                      />
                      <TextField
                        label="Biaya (Rp, opsional)"
                        type="number"
                        min="0"
                        value={values.cost}
                        onChange={(v) => update("cost", v)}
                      />
                    </FieldGroup>
                    <Upload
                      label={values.kind === "none" ? "Foto hasil (opsional)" : "Foto hasil pekerjaan"}
                      files={fixPhotos}
                      onChange={(f) => {
                        setFixPhotos(f);
                        w.dirty(true);
                      }}
                      hint={errors.fix_photos || "Minimal satu foto untuk perbaikan/pemeliharaan."}
                    />
                    <Alert>
                      <Info />
                      <AlertDescription>
                        Anda pelapor sekaligus pelaksana, jadi laporan langsung selesai dan terverifikasi atas nama Anda.
                      </AlertDescription>
                    </Alert>
                  </>
                ) : (
                  <FieldDescription>
                    Biarkan mati jika belum selesai, misalnya masih perlu dilaporkan ke unit IT. Laporan masuk ke tab Tindak
                    lanjut Anda.
                  </FieldDescription>
                )}
              </FieldGroup>
            </Panel>
          )}
        </div>
      </fieldset>
      {w.config.write && (
        <ActionBar status={busy ? "Menyimpan…" : undefined}>
          <Button variant="outline" disabled={busy} onClick={w.back}>
            Batal
          </Button>
          <Button disabled={busy} onClick={save}>
            {fixed && self ? <CheckCheck data-icon="inline-start" /> : <Send data-icon="inline-start" />}
            {fixed && self ? "Simpan & selesai" : "Kirim laporan"}
          </Button>
        </ActionBar>
      )}
    </>
  );
}
