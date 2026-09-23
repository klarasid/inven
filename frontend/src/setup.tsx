import { roomLabel } from "./rooms";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { Plus, ArrowRight, Trash2 } from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Tabs, TabsList, TabsTrigger } from "./components/ui/tabs";
import {
  Table,
  TableHeader,
  TableRow,
  TableHead,
  TableBody,
  TableCell,
} from "./components/ui/table";
import { FieldGroup } from "./components/ui/field";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "./components/ui/dialog";
import { useData, useWorkspace } from "./context";
import { read, groups, dateLabel } from "./api";
import {
  Heading,
  Panel,
  Choice,
  TextField,
  Search,
  Filters,
  ErrorBox,
  Loading,
  Blank,
  Actions,
  Pager,
  entries,
} from "./shared";
import type { Schedule, Template, ChecklistItem, Page, Id } from "./types";
export function SetupList() {
  const w = useWorkspace();
  const schedule = w.route.view === "schedules";
  const { data, error, loading } = useData<Page<Schedule | Template>>(
    schedule ? "schedules" : "templates",
    w.route,
  );
  const [stop, setStop] = useState<Schedule>();
  const [effective, setEffective] = useState(w.config.today);
  const [busy, setBusy] = useState(false);
  const [stopError, setStopError] = useState("");
  return (
    <>
      <Heading
        title={schedule ? "Jadwal pemeriksaan" : "Checklist"}
        description={
          schedule
            ? "Atur kapan ruangan diperiksa dan siapa penanggung jawabnya."
            : "Siapkan butir pemeriksaan yang jelas dan dapat digunakan kembali."
        }
        action={
          w.config.write && (
            <Button
              onClick={() =>
                w.go({ view: schedule ? "schedule-edit" : "template-edit" })
              }
            >
              <Plus data-icon="inline-start" />
              {schedule ? "Buat jadwal" : "Buat checklist"}
            </Button>
          )
        }
      />
      <div className="flex gap-3">
        <Search placeholder={schedule ? "Cari ruangan…" : "Cari checklist…"} />
        {schedule && <Filters showHistory />}
      </div>
      <ErrorBox message={error} />
      {loading ? (
        <Loading />
      ) : data && !data.rows.length ? (
        <Blank
          title={schedule ? "Belum ada jadwal" : "Belum ada checklist"}
          description={
            schedule
              ? "Buat jadwal setelah ruangan dan checklist tersedia."
              : "Mulai dari contoh checklist, lalu sesuaikan dengan kebutuhan ruangan."
          }
        />
      ) : (
        data && (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>
                  {schedule ? "Ruangan / checklist" : "Nama checklist"}
                </TableHead>
                <TableHead>{schedule ? "Jadwal / petugas" : "Butir"}</TableHead>
                <TableHead>{schedule ? "Status" : "Versi"}</TableHead>
                <TableHead>Tindakan</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.rows.map((row) => {
                if (schedule) {
                  const s = row as Schedule;
                  const active =
                    Number(s.active) &&
                    s.location_id &&
                    (!s.end_date || s.end_date >= w.config.today);
                  return (
                    <TableRow key={s.id}>
                      <TableCell>
                        <p className="font-medium">{s.snapshot.room_name}</p>
                        <p className="text-xs text-muted-foreground">
                          {s.snapshot.template_name}
                        </p>
                      </TableCell>
                      <TableCell>
                        <p>
                          {w.options.frequencies[s.frequency]} ·{" "}
                          {s.assignee_name}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {dateLabel(s.start_date)} —{" "}
                          {s.end_date ? dateLabel(s.end_date) : "Seterusnya"}
                        </p>
                      </TableCell>
                      <TableCell>
                        <Badge variant="outline">
                          {active ? "Aktif" : "Tidak aktif"}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        {w.config.write && active ? (
                          <Actions
                            items={[
                              {
                                label: "Ganti jadwal",
                                run: () =>
                                  w.go({
                                    view: "schedule-edit",
                                    replaces_id: s.id,
                                  }),
                              },
                              {
                                label: "Hentikan jadwal",
                                run: () => {
                                  setStop(s);
                                  setStopError("");
                                },
                              },
                            ]}
                          />
                        ) : (
                          <Button
                            variant="ghost"
                            onClick={() =>
                              w.go({ view: "schedule-detail", record: s.id })
                            }
                          >
                            Lihat
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                }
                const t = row as Template;
                return (
                  <TableRow key={t.id}>
                    <TableCell>
                      <Button
                        variant="link"
                        onClick={() =>
                          w.go({
                            view: w.config.write
                              ? "template-edit"
                              : "template-detail",
                            record: t.id,
                          })
                        }
                      >
                        {t.name}
                      </Button>
                    </TableCell>
                    <TableCell>{t.items.length} butir</TableCell>
                    <TableCell>
                      #{t.id}
                      {t.source_id && ` · revisi #${t.source_id}`}
                    </TableCell>
                    <TableCell>
                      <Button
                        variant="outline"
                        onClick={() =>
                          w.go({
                            view: w.config.write
                              ? "template-edit"
                              : "template-detail",
                            record: t.id,
                          })
                        }
                      >
                        {w.config.write ? "Salin / revisi" : "Lihat"}
                      </Button>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        )
      )}
      {data && (
        <Pager
          {...data}
          onChange={(page) => w.go({ ...w.route, page }, true)}
        />
      )}
      <Dialog
        open={!!stop}
        onOpenChange={(open) => {
          if (!open) setStop(undefined);
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Hentikan jadwal</DialogTitle>
            <DialogDescription>
              Pemeriksaan dan riwayat yang sudah terbentuk tetap disimpan.
            </DialogDescription>
          </DialogHeader>
          <ErrorBox message={stopError} />
          <TextField
            label="Tidak dijadwalkan mulai"
            type="date"
            value={effective}
            onChange={setEffective}
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setStop(undefined)}>
              Batal
            </Button>
            <Button
              disabled={busy}
              onClick={async () => {
                if (!stop) return;
                setBusy(true);
                try {
                  await w.mutate({
                    watch_action: "stop",
                    id: stop.id,
                    version: stop.version,
                    effective,
                  });
                  setStop(undefined);
                  w.refresh();
                } catch (e) {
                  setStopError((e as Error).message);
                } finally {
                  setBusy(false);
                }
              }}
            >
              Hentikan jadwal
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
export function TemplatePage() {
  const w = useWorkspace();
  return w.route.record ? <ExistingTemplate /> : <TemplateEditor />;
}
function ExistingTemplate() {
  const w = useWorkspace();
  const { data, error } = useData<Template>("template", {
    record: w.route.record,
  });
  return (
    <>
      <ErrorBox message={error} />
      {data ? <TemplateEditor template={data} /> : !error && <Loading />}
    </>
  );
}
function TemplateEditor({ template }: { template?: Template }) {
  const w = useWorkspace();
  const readonly = !w.config.write || w.route.view === "template-detail";
  const [name, setName] = useState(
    template
      ? template.name + (readonly ? "" : " (revisi)")
      : "Checklist pemeriksaan ruangan",
  );
  const [items, setItems] = useState<ChecklistItem[]>(
    template?.items ||
      groups.map((group) => ({
        group,
        object:
          group === "Sarana"
            ? "Meja, kursi, dan rak"
            : group === "Prasarana"
              ? "Lantai, atap, dan pintu"
              : "Kebersihan dan kenyamanan",
        instruction: "Periksa kondisi dan catat jika perlu tindakan.",
      })),
  );
  const [selected, setSelected] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const update = (patch: Partial<ChecklistItem>) => {
    setItems((list) =>
      list.map((i, n) => (n === selected ? { ...i, ...patch } : i)),
    );
    w.dirty(true);
  };
  return (
    <>
      <Heading
        back
        title={
          readonly
            ? "Detail checklist"
            : template
              ? "Revisi checklist"
              : "Buat checklist"
        }
        description={
          template
            ? "Perubahan disimpan sebagai versi baru. Jadwal dan hasil terdahulu tetap memakai versi lama."
            : "Contoh dapat disesuaikan; bukan standar penilaian resmi."
        }
      />
      <ErrorBox message={error} />
      <fieldset
        disabled={readonly || busy}
        className="flex flex-col gap-6 min-w-0"
      >
        <TextField
          label="Nama checklist"
          value={name}
          onChange={(v) => {
            setName(v);
            w.dirty(true);
          }}
          required
          maxLength={255}
        />
      </fieldset>
      <div className="grid gap-6 md:grid-cols-[240px_1fr]">
        <aside className="flex flex-col gap-4">
          {groups.map((group) => (
            <div className="flex flex-col gap-1" key={group}>
              <h2 className="text-xs uppercase tracking-wide text-muted-foreground">
                {group}
              </h2>
              {items.map(
                (item, i) =>
                  item.group === group && (
                    <Button
                      key={i}
                      variant={selected === i ? "secondary" : "ghost"}
                      className="justify-start whitespace-normal text-left h-auto py-2"
                      onClick={() => setSelected(i)}
                    >
                      {item.object || `Butir ${i + 1}`}
                    </Button>
                  ),
              )}
            </div>
          ))}
          {!readonly && (
            <Button
              variant="outline"
              disabled={busy || items.length >= 100}
              onClick={() => {
                setItems([
                  ...items,
                  {
                    group: items[selected]?.group || groups[0],
                    object: "",
                    instruction: "",
                  },
                ]);
                setSelected(items.length);
                w.dirty(true);
              }}
            >
              <Plus data-icon="inline-start" />
              Tambah butir
            </Button>
          )}
          <p className="text-xs text-muted-foreground">
            {items.length} / 100 butir
          </p>
        </aside>
        <Panel title={`Butir ${selected + 1}`}>
          <fieldset disabled={readonly || busy}>
            <FieldGroup>
              <Choice
                label="Kelompok"
                value={items[selected].group}
                onChange={(group) => update({ group })}
                items={groups.map((value) => ({ value, label: value }))}
              />
              <TextField
                label="Objek pemeriksaan"
                required
                value={items[selected].object}
                onChange={(object) => update({ object })}
              />
              <TextField
                label="Petunjuk pemeriksaan"
                multiline
                value={items[selected].instruction}
                onChange={(instruction) => update({ instruction })}
              />
              {!readonly && (
                <Button
                  variant="ghost"
                  disabled={items.length <= 1}
                  onClick={() => {
                    setItems(items.filter((_, i) => i !== selected));
                    setSelected(Math.max(0, selected - 1));
                    w.dirty(true);
                  }}
                >
                  <Trash2 data-icon="inline-start" />
                  Hapus butir dari versi ini
                </Button>
              )}
            </FieldGroup>
          </fieldset>
        </Panel>
      </div>
      {!readonly && (
        <div>
          <Button
            disabled={busy}
            onClick={async () => {
              const invalid = items.findIndex((i) => !i.object.trim());
              if (!name.trim() || invalid >= 0) {
                setError("Isi nama checklist dan objek setiap butir.");
                if (invalid >= 0) setSelected(invalid);
                return;
              }
              setBusy(true);
              setError("");
              try {
                await w.mutate({
                  watch_action: "template",
                  source_id: template?.id || 0,
                  name,
                  items,
                });
                w.dirty(false);
                toast.success("Versi checklist tersimpan.");
                w.go({ view: "checklists" });
                w.refresh();
              } catch (e) {
                setError((e as Error).message);
              } finally {
                setBusy(false);
              }
            }}
          >
            {busy ? "Menyimpan…" : "Simpan versi checklist"}
          </Button>
        </div>
      )}
    </>
  );
}
export function SchedulePage() {
  const w = useWorkspace();
  return w.route.replaces_id || w.route.view === "schedule-detail" ? (
    <ExistingSchedule />
  ) : (
    <ScheduleEditor />
  );
}
function ExistingSchedule() {
  const w = useWorkspace();
  const { data, error } = useData<Schedule>("schedule", {
    record: w.route.replaces_id || w.route.record,
  });
  return (
    <>
      <ErrorBox message={error} />
      {data ? (
        w.route.view === "schedule-detail" ? (
          <>
            <Heading
              back
              title={data.snapshot.room_name}
              description={data.snapshot.template_name}
            />
            <Panel title="Jadwal">
              <p>
                {w.options.frequencies[data.frequency]} · {data.assignee_name}
              </p>
              <p>
                {dateLabel(data.start_date)} — {dateLabel(data.end_date)}
              </p>
              {data.snapshot.items.map((i, n) => (
                <p key={n} className="py-2">
                  {i.object} · {i.item_name || "Aspek ruangan"}
                </p>
              ))}
            </Panel>
          </>
        ) : (
          <ScheduleEditor previous={data} />
        )
      ) : (
        !error && <Loading />
      )}
    </>
  );
}
function ScheduleEditor({ previous }: { previous?: Schedule }) {
  const w = useWorkspace();
  const incidental = w.route.view === "new-inspection";
  const tomorrow = new Date(w.config.today + "T12:00:00");
  tomorrow.setDate(tomorrow.getDate() + 1);
  const tomorrowString = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, "0")}-${String(tomorrow.getDate()).padStart(2, "0")}`;
  const [values, setValues] = useState({
    location_id: String(previous?.location_id || w.route.room || ""),
    template_id: String(previous?.template_id || w.route.template_id || ""),
    frequency: previous?.frequency || "monthly",
    start_date: previous ? tomorrowString : w.config.today,
    end_date: "",
    assignee_id: String(previous?.assignee_id || w.config.uid),
    reason: "",
  });
  const [mapping, setMapping] = useState<string[]>(
    previous?.snapshot.items.map((i) => String(i.item_id || "")) || [],
  );
  const [scope, setScope] = useState<{
    items: ChecklistItem[];
    assets: { id: Id; item_name: string; item_code: string }[];
  }>();
  const [dates, setDates] = useState<string[]>([]);
  const [step, setStep] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const lock = useRef(false);
  const update = (key: string, v: string) => {
    setValues((old) => ({ ...old, [key]: v }));
    if (key === "location_id" || key === "template_id") {
      setScope(undefined);
      setMapping([]);
    }
    w.dirty(true);
  };
  async function next() {
    setBusy(true);
    setError("");
    try {
      if (incidental) {
        if (step === 0) {
          if (!values.location_id || !values.template_id)
            throw new Error("Pilih ruangan dan checklist.");
          const response = await read<typeof scope>(w.config, "scope", {
            room: values.location_id,
            template_id: values.template_id,
          });
          setScope(response);
          if (!mapping.length) setMapping(response!.items.map(() => ""));
        }
        if (step === 1 && !values.reason.trim()) {
          throw new Error("Isi alasan pemeriksaan.");
        }
      } else {
        if (step === 0) {
          if (!values.location_id || !values.template_id)
            throw new Error("Pilih ruangan dan checklist.");
          const response = await read<typeof scope>(w.config, "scope", {
            room: values.location_id,
            template_id: values.template_id,
          });
          setScope(response);
          if (!mapping.length) setMapping(response!.items.map(() => ""));
        }
        if (step === 2) {
          if (!values.assignee_id || !values.frequency || !values.start_date)
            throw new Error("Lengkapi waktu dan penanggung jawab.");
          if (values.end_date && values.end_date < values.start_date)
            throw new Error("Tanggal akhir harus setelah tanggal mulai.");
          setDates(
            (await read<{ dates: string[] }>(w.config, "preview", values))
              .dates,
          );
        }
      }
      setStep(step + 1);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function save() {
    if (lock.current) return;
    lock.current = true;
    setBusy(true);
    setError("");
    try {
      const reply = await w.mutate({
        watch_action: incidental ? "incidental" : "schedule",
        ...values,
        mapping,
        replaces_id: previous?.id || 0,
        version: previous?.version || 0,
        parent_id: w.route.parent_id || 0,
      });
      w.dirty(false);
      toast.success(incidental ? "Pemeriksaan dibuat." : "Jadwal tersimpan.");
      const record = reply.url
        ? new URL(reply.url, window.location.href).searchParams.get("record")
        : null;
      w.go(
        incidental && record
          ? { view: "inspection", record }
          : { view: "schedules" },
      );
      w.refresh();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      lock.current = false;
      setBusy(false);
    }
  }
  if (!w.options.rooms.length || !w.options.templates.length)
    return (
      <>
        <Heading
          back
          title={incidental ? "Pemeriksaan insidental" : "Buat jadwal"}
        />
        <Blank
          title="Siapkan ruangan dan checklist dahulu"
          description="Keduanya diperlukan untuk menentukan objek pemeriksaan."
        >
          <div className="flex gap-3">
            <Button onClick={() => w.go({ view: "inventory" })}>
              Kelola ruangan
            </Button>
            <Button
              variant="outline"
              onClick={() => w.go({ view: "checklists" })}
            >
              Kelola checklist
            </Button>
          </div>
        </Blank>
      </>
    );
  const steps = incidental
    ? ["Ruangan & checklist", "Alasan", "Ringkasan"]
    : ["Ruangan & checklist", "Cakupan barang", "Waktu & petugas", "Ringkasan"];
  const lastStep = steps.length - 1;
  return (
    <>
      <Heading
        back
        title={
          incidental
            ? w.route.parent_id
              ? "Pemeriksaan ulang"
              : "Pemeriksaan insidental"
            : previous
              ? "Ganti jadwal"
              : "Buat jadwal"
        }
        description={`Langkah ${step + 1} dari ${steps.length} · ${steps[step]}`}
      />
      <div className="flex gap-2 flex-wrap">
        {steps.map((s, i) => (
          <Badge key={s} variant={step === i ? "default" : "outline"}>
            {i + 1}. {s}
          </Badge>
        ))}
      </div>
      <ErrorBox message={error} />
      <fieldset disabled={busy} className="min-w-0">
        <Panel title={steps[step]}>
          <FieldGroup>
            {step === 0 && (
              <>
                <Choice
                  label="Ruangan"
                  value={values.location_id}
                  onChange={(v) => update("location_id", v)}
                  items={w.options.rooms.map((x) => ({
                    value: x.id,
                    label: roomLabel(x, w.options.libraries),
                  }))}
                />
                <Choice
                  label="Checklist"
                  value={values.template_id}
                  onChange={(v) => update("template_id", v)}
                  items={w.options.templates.map((x) => ({
                    value: x.id,
                    label: `${x.name} · #${x.id}`,
                  }))}
                />
              </>
            )}
            {step === 1 &&
              !incidental &&
              scope?.items.map((i, n) => (
                <Choice
                  key={n}
                  label={`${i.group} · ${i.object}`}
                  value={mapping[n]}
                  placeholder="Aspek ruangan"
                  items={scope.assets.map((a) => ({
                    value: a.id,
                    label: `${a.item_name} ${a.item_code ? `(${a.item_code})` : ""}`,
                  }))}
                  onChange={(v) => {
                    setMapping((old) => old.map((a, k) => (k === n ? v : a)));
                    w.dirty(true);
                  }}
                />
              ))}
            {((incidental && step === 1) || (!incidental && step === 2)) &&
              (incidental ? (
                <TextField
                  label="Alasan pemeriksaan"
                  multiline
                  required
                  value={values.reason}
                  onChange={(v) => update("reason", v)}
                />
              ) : (
                <>
                  <Choice
                    label="Frekuensi"
                    value={values.frequency}
                    onChange={(v) => update("frequency", v)}
                    items={entries(w.options.frequencies)}
                  />
                  <FieldGroup className="grid sm:grid-cols-2">
                    <TextField
                      label={previous ? "Mulai versi baru" : "Tanggal mulai"}
                      type="date"
                      value={values.start_date}
                      onChange={(v) => update("start_date", v)}
                    />
                    <TextField
                      label="Tanggal akhir (opsional)"
                      type="date"
                      value={values.end_date}
                      onChange={(v) => update("end_date", v)}
                    />
                  </FieldGroup>
                  <Choice
                    label="Penanggung jawab"
                    value={values.assignee_id}
                    onChange={(v) => update("assignee_id", v)}
                    items={w.options.users.map((x) => ({
                      value: x.user_id,
                      label: x.realname,
                    }))}
                  />
                </>
              ))}
            {((incidental && step === 2) || (!incidental && step === 3)) && (
              <>
                <p className="font-medium">
                  {roomLabel(
                    w.options.rooms.find(
                      (r) => String(r.id) === values.location_id,
                    ),
                    w.options.libraries,
                  )}
                </p>
                <p>
                  {
                    w.options.templates.find(
                      (t) => String(t.id) === values.template_id,
                    )?.name
                  }{" "}
                  · {scope?.items.length} butir
                </p>
                <p>
                  {mapping.filter(Boolean).length} butir terhubung ke barang;
                  sisanya aspek ruangan.
                </p>
                {incidental ? (
                  <>
                    <p>{values.reason}</p>
                    <p className="text-sm text-muted-foreground">
                      Untuk pemeriksaan insidental, pemetaan barang per butir bersifat opsional agar input lebih cepat.
                    </p>
                  </>
                ) : (
                  <>
                    <p>
                      {w.options.frequencies[values.frequency]} ·{" "}
                      {
                        w.options.users.find(
                          (u) => String(u.user_id) === values.assignee_id,
                        )?.realname
                      }
                    </p>
                    <p className="text-sm text-muted-foreground">
                      Tanggal pemeriksaan berikutnya:
                    </p>
                    <div className="flex gap-2 flex-wrap">
                      {dates.map((date) => (
                        <Badge variant="outline" key={date}>
                          {dateLabel(date)}
                        </Badge>
                      ))}
                    </div>
                    {previous && (
                      <p className="text-sm text-muted-foreground">
                        Jadwal lama berakhir sehari sebelum versi baru dimulai.
                        Riwayat tetap disimpan.
                      </p>
                    )}
                  </>
                )}
              </>
            )}
          </FieldGroup>
        </Panel>
      </fieldset>
      <div className="flex gap-3">
        {step > 0 && (
          <Button
            variant="outline"
            disabled={busy}
            onClick={() => setStep(step - 1)}
          >
            Sebelumnya
          </Button>
        )}
        <Button
          disabled={busy || !w.config.write}
          onClick={() => (step === lastStep ? save() : next())}
        >
          {busy
            ? "Memproses…"
            : step === lastStep
              ? incidental
                ? "Buat pemeriksaan"
                : "Simpan jadwal"
              : "Lanjutkan"}
          <ArrowRight data-icon="inline-end" />
        </Button>
      </div>
    </>
  );
}
