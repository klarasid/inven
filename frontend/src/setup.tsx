import { roomLabel } from "./rooms";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import {
  Plus,
  Trash2,
  ListChecks,
  CalendarDays,
  CalendarClock,
  Copy,
  Eye,
  Repeat,
  StopCircle,
  User,
  UserCog,
  Building2,
} from "lucide-react";
import { Button } from "./components/ui/button";
import { Checkbox } from "./components/ui/checkbox";
import { Badge } from "./components/ui/badge";
import { Card, CardContent } from "./components/ui/card";
import { Input } from "./components/ui/input";
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from "./components/ui/table";
import { FieldGroup } from "./components/ui/field";
import { ToggleGroup, ToggleGroupItem } from "./components/ui/toggle-group";
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
  PageHeader,
  Panel,
  Choice,
  TextField,
  SearchBox,
  RoomFilter,
  ErrorBox,
  Loading,
  Blank,
  Actions,
  Pager,
  entries,
  ActionBar,
} from "./shared";
import type { Schedule, Template, ChecklistItem, Page, Id } from "./types";

/**
 * Changes a schedule's assignee in place (no new schedule version). Inspections formed from now on go
 * to the new assignee; ticking the option also moves inspections already formed but not yet started.
 */
function AssigneeDialog({ schedule, onClose }: { schedule?: Schedule; onClose: () => void }) {
  const w = useWorkspace();
  const [assignee, setAssignee] = useState("");
  const [pending, setPending] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => {
    if (schedule) {
      setAssignee(String(schedule.assignee_id));
      setPending(true);
      setError("");
    }
  }, [schedule?.id]);
  const name = w.options.users.find((u) => String(u.user_id) === assignee)?.realname;
  return (
    <Dialog open={!!schedule} onOpenChange={(open) => !open && !busy && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Ganti petugas {schedule?.snapshot.room_name}</DialogTitle>
          <DialogDescription>
            Petugas saat ini: {schedule?.assignee_name}. Jadwal lain tidak berubah, dan pemeriksaan yang sudah dikerjakan tetap tercatat atas nama petugasnya.
          </DialogDescription>
        </DialogHeader>
        <ErrorBox message={error} />
        <Choice
          label="Petugas baru"
          value={assignee}
          onChange={setAssignee}
          items={w.options.users.map((u) => ({ value: u.user_id, label: u.realname }))}
        />
        <label className="flex items-start gap-2 text-sm">
          <Checkbox checked={pending} onCheckedChange={(v) => setPending(v === true)} className="mt-0.5" />
          <span>
            Alihkan juga pemeriksaan yang sudah terbentuk tetapi <b>belum dimulai</b>
            <span className="block text-xs text-muted-foreground">Tanpa ini, hanya pemeriksaan berikutnya yang menjadi tugas petugas baru.</span>
          </span>
        </label>
        <DialogFooter>
          <Button variant="outline" disabled={busy} onClick={onClose}>
            Batal
          </Button>
          <Button
            disabled={busy || !assignee || assignee === String(schedule?.assignee_id)}
            onClick={async () => {
              if (!schedule) return;
              setBusy(true);
              setError("");
              try {
                const reply = await w.mutate({
                  watch_action: "schedule_assignee",
                  id: schedule.id,
                  version: schedule.version,
                  assignee_id: assignee,
                  include_pending: pending ? "1" : "",
                });
                toast.success(
                  `Petugas diganti ke ${name}` + (reply.generated ? `; ${reply.generated} pemeriksaan belum dimulai ikut dialihkan.` : "."),
                );
                onClose();
                w.refresh();
              } catch (e) {
                setError((e as Error).message);
              } finally {
                setBusy(false);
              }
            }}
          >
            <UserCog data-icon="inline-start" />
            Ganti petugas
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function SetupList() {
  const w = useWorkspace();
  const schedule = w.route.view === "schedules";
  const { data, error, loading } = useData<Page<Schedule | Template>>(schedule ? "schedules" : "templates", w.route);
  const [stop, setStop] = useState<Schedule>();
  const [reassign, setReassign] = useState<Schedule>();
  const [effective, setEffective] = useState(w.config.today);
  const [busy, setBusy] = useState(false);
  const [stopError, setStopError] = useState("");
  const noTemplates = schedule && !w.options.templates.length;
  const filtered = !!(w.route.q || w.route.room);
  return (
    <>
      <PageHeader
        title={schedule ? "Jadwal pemeriksaan" : "Checklist"}
        description={
          schedule
            ? "Atur ruangan yang diperiksa rutin, frekuensinya, dan penanggung jawabnya. Tugas dibuat otomatis sesuai jadwal."
            : "Daftar butir pemeriksaan yang dipakai jadwal dan pemeriksaan insidental."
        }
        actions={
          w.config.write && (
            <>
              {schedule && (
                <Button variant="outline" onClick={() => w.go({ view: "checklists" })}>
                  <ListChecks data-icon="inline-start" />
                  Kelola checklist
                </Button>
              )}
              <Button
                disabled={noTemplates}
                onClick={() => w.go({ view: schedule ? "schedule-edit" : "template-edit" })}
              >
                <Plus data-icon="inline-start" />
                {schedule ? "Buat jadwal" : "Buat checklist"}
              </Button>
            </>
          )
        }
      />
      {noTemplates ? (
        <Blank
          icon={ListChecks}
          title="Buat checklist terlebih dahulu"
          description="Jadwal memerlukan checklist yang menentukan butir apa saja yang diperiksa di ruangan."
        >
          {w.config.write && (
            <Button onClick={() => w.go({ view: "template-edit" })}>
              <Plus data-icon="inline-start" />
              Buat checklist
            </Button>
          )}
        </Blank>
      ) : (
        <>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <SearchBox placeholder={schedule ? "Cari ruangan…" : "Cari checklist…"} />
            {schedule && (
              <>
                <RoomFilter />
                <ToggleGroup
                  type="single"
                  variant="outline"
                  className="sm:ml-auto"
                  value={w.route.history === "1" ? "1" : "0"}
                  onValueChange={(history) => history && w.go({ ...w.route, history, page: 1 }, true)}
                >
                  <ToggleGroupItem value="0">Aktif</ToggleGroupItem>
                  <ToggleGroupItem value="1">Semua</ToggleGroupItem>
                </ToggleGroup>
              </>
            )}
          </div>
          <ErrorBox message={error} />
          {loading && !data ? (
            <Loading />
          ) : data && !data.rows.length ? (
            <Blank
              icon={schedule ? CalendarDays : ListChecks}
              title={filtered ? "Tidak ditemukan" : schedule ? "Belum ada jadwal" : "Belum ada checklist"}
              description={
                filtered
                  ? "Ubah kata kunci atau filter."
                  : schedule
                    ? "Buat jadwal agar tugas pemeriksaan muncul otomatis."
                    : "Mulai dari contoh checklist, lalu sesuaikan dengan kebutuhan ruangan."
              }
            >
              {w.config.write && !filtered && (
                <Button onClick={() => w.go({ view: schedule ? "schedule-edit" : "template-edit" })}>
                  <Plus data-icon="inline-start" />
                  {schedule ? "Buat jadwal" : "Buat checklist"}
                </Button>
              )}
            </Blank>
          ) : (
            data &&
            (schedule ? (
              <div className="overflow-hidden rounded-xl border">
                <Table>
                  <TableHeader className="bg-muted/50">
                    <TableRow>
                      <TableHead>Ruangan</TableHead>
                      <TableHead>Frekuensi</TableHead>
                      <TableHead className="hidden md:table-cell">Petugas</TableHead>
                      <TableHead className="hidden sm:table-cell">Berlaku</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead className="w-12">
                        <span className="sr-only">Tindakan</span>
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(data.rows as Schedule[]).map((s) => {
                      const active =
                        Number(s.active) && s.location_id && (!s.end_date || s.end_date >= w.config.today);
                      return (
                        <TableRow
                          key={s.id}
                          className="cursor-pointer"
                          onClick={() => w.go({ view: "schedule-detail", record: s.id })}
                        >
                          <TableCell className="max-w-64 whitespace-normal">
                            <p className="font-medium">{s.snapshot.room_name}</p>
                            <p className="text-xs text-muted-foreground">{s.snapshot.template_name}</p>
                          </TableCell>
                          <TableCell>{w.options.frequencies[s.frequency]}</TableCell>
                          <TableCell className="hidden text-muted-foreground md:table-cell">{s.assignee_name}</TableCell>
                          <TableCell className="hidden text-muted-foreground sm:table-cell">
                            {dateLabel(s.start_date)} – {s.end_date ? dateLabel(s.end_date) : "seterusnya"}
                          </TableCell>
                          <TableCell>
                            <Badge variant={active ? "success" : "outline"}>{active ? "Aktif" : "Berhenti"}</Badge>
                          </TableCell>
                          <TableCell>
                            {w.config.write && active ? (
                              <Actions
                                items={[
                                  { label: "Lihat detail", icon: Eye, run: () => w.go({ view: "schedule-detail", record: s.id }) },
                                  { label: "Ganti petugas", icon: UserCog, run: () => setReassign(s) },
                                  {
                                    label: "Ganti jadwal",
                                    icon: Repeat,
                                    run: () => w.go({ view: "schedule-edit", replaces_id: s.id }),
                                  },
                                  {
                                    label: "Hentikan jadwal",
                                    icon: StopCircle,
                                    destructive: true,
                                    run: () => {
                                      setStop(s);
                                      setStopError("");
                                    },
                                  },
                                ]}
                              />
                            ) : null}
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </div>
            ) : (
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {(data.rows as Template[]).map((t) => {
                  const open = () => w.go({ view: "template-detail", record: t.id });
                  return (
                    <Card
                      key={t.id}
                      role="link"
                      tabIndex={0}
                      onClick={open}
                      onKeyDown={(e) => e.key === "Enter" && open()}
                      className="cursor-pointer gap-3 py-4 transition-colors hover:bg-muted/40"
                    >
                      <CardContent className="flex flex-col gap-3 px-4">
                        <div className="flex items-start justify-between gap-2">
                          <div className="flex min-w-0 items-start gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                              <ListChecks className="size-4 text-muted-foreground" />
                            </span>
                            <div className="min-w-0">
                              <p className="font-medium">{t.name}</p>
                              <p className="text-xs text-muted-foreground">
                                Versi #{t.id}
                                {t.source_id && ` · revisi dari #${t.source_id}`}
                              </p>
                            </div>
                          </div>
                          {w.config.write && (
                            <Actions
                              items={[
                                { label: "Lihat", icon: Eye, run: open },
                                {
                                  label: "Salin / revisi",
                                  icon: Copy,
                                  run: () => w.go({ view: "template-edit", record: t.id }),
                                },
                              ]}
                            />
                          )}
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                          <Badge variant="secondary">{t.items.length} butir</Badge>
                          {groups.map((g) => {
                            const n = t.items.filter((i) => i.group === g).length;
                            return n ? (
                              <Badge key={g} variant="outline">
                                {g} {n}
                              </Badge>
                            ) : null;
                          })}
                        </div>
                      </CardContent>
                    </Card>
                  );
                })}
              </div>
            ))
          )}
          {data && <Pager {...data} onChange={(page) => w.go({ ...w.route, page }, true)} />}
        </>
      )}
      <Dialog
        open={!!stop}
        onOpenChange={(open) => {
          if (!open) setStop(undefined);
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Hentikan jadwal {stop?.snapshot.room_name}?</DialogTitle>
            <DialogDescription>Pemeriksaan dan riwayat yang sudah terbentuk tetap disimpan.</DialogDescription>
          </DialogHeader>
          <ErrorBox message={stopError} />
          <TextField label="Tidak dijadwalkan lagi mulai" type="date" value={effective} onChange={setEffective} />
          <DialogFooter>
            <Button variant="outline" onClick={() => setStop(undefined)}>
              Batal
            </Button>
            <Button
              variant="destructive"
              disabled={busy}
              onClick={async () => {
                if (!stop) return;
                setBusy(true);
                try {
                  await w.mutate({ watch_action: "stop", id: stop.id, version: stop.version, effective });
                  setStop(undefined);
                  toast.success("Jadwal dihentikan.");
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
      <AssigneeDialog schedule={reassign} onClose={() => setReassign(undefined)} />
    </>
  );
}

export function TemplatePage() {
  const w = useWorkspace();
  return w.route.record ? <ExistingTemplate /> : <TemplateEditor />;
}

function ExistingTemplate() {
  const w = useWorkspace();
  const { data, error } = useData<Template>("template", { record: w.route.record });
  return (
    <>
      <ErrorBox message={error} />
      {data ? <TemplateEditor template={data} /> : !error && <Loading />}
    </>
  );
}

const sampleItems: Record<string, string[]> = {
  Sarana: ["Meja dan kursi baca", "Rak buku", "Komputer katalog (OPAC)"],
  Prasarana: ["Lantai, dinding, dan plafon", "Pintu dan jendela", "Instalasi listrik dan lampu"],
  "Lingkungan Fisik": ["Kebersihan ruangan", "Pencahayaan dan sirkulasi udara"],
};

function TemplateEditor({ template }: { template?: Template }) {
  const w = useWorkspace();
  const readonly = !w.config.write || w.route.view === "template-detail";
  const [name, setName] = useState(
    template ? template.name + (readonly ? "" : " (revisi)") : "Checklist pemeriksaan ruangan",
  );
  const [items, setItems] = useState<ChecklistItem[]>(
    template?.items ||
      groups.flatMap((group) =>
        sampleItems[group].map((object) => ({ group, object, instruction: "Periksa kondisi dan catat bila perlu tindakan." })),
      ),
  );
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [invalid, setInvalid] = useState<number[]>([]);
  const change = (next: ChecklistItem[]) => {
    setItems(next);
    w.dirty(true);
  };
  const update = (index: number, patch: Partial<ChecklistItem>) =>
    change(items.map((item, n) => (n === index ? { ...item, ...patch } : item)));

  async function save() {
    const bad = items.map((i, n) => (i.object.trim() ? -1 : n)).filter((n) => n >= 0);
    setInvalid(bad);
    if (!name.trim() || bad.length || !items.length) {
      setError(!items.length ? "Tambahkan minimal satu butir." : "Isi nama checklist dan objek setiap butir.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      await w.mutate({ watch_action: "template", source_id: template?.id || 0, name, items });
      w.dirty(false);
      toast.success("Checklist tersimpan.");
      w.go({ view: "checklists" }, true);
      w.refresh();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <PageHeader
        crumbs={[{ label: "Checklist", route: { view: "checklists" } }]}
        title={readonly ? name : template ? "Revisi checklist" : "Buat checklist"}
        description={
          readonly
            ? `${items.length} butir pemeriksaan`
            : template
              ? "Perubahan disimpan sebagai versi baru. Jadwal dan hasil terdahulu tetap memakai versi lama."
              : "Contoh butir sudah disiapkan. Ubah, hapus, atau tambahkan sesuai kebutuhan ruangan."
        }
        actions={
          readonly &&
          w.config.write &&
          template && (
            <>
              <Button variant="outline" onClick={() => w.go({ view: "template-edit", record: template.id })}>
                <Copy data-icon="inline-start" />
                Salin / revisi
              </Button>
              <Button onClick={() => w.go({ view: "schedule-edit", template_id: template.id })}>
                <CalendarDays data-icon="inline-start" />
                Jadwalkan
              </Button>
            </>
          )
        }
      />
      <ErrorBox message={error} />
      <fieldset disabled={busy} className="flex min-w-0 flex-col gap-6">
        {!readonly && (
          <div className="max-w-xl">
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
          </div>
        )}
        {groups.map((group) => {
          const rows = items.map((item, index) => ({ item, index })).filter(({ item }) => item.group === group);
          return (
            <Panel
              key={group}
              title={group}
              description={`${rows.length} butir`}
              action={
                !readonly && (
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={items.length >= 100}
                    onClick={() => change([...items, { group, object: "", instruction: "" }])}
                  >
                    <Plus data-icon="inline-start" />
                    Tambah butir
                  </Button>
                )
              }
            >
              {!rows.length ? (
                <p className="text-sm text-muted-foreground">Belum ada butir di kelompok ini.</p>
              ) : readonly ? (
                <ol className="flex flex-col divide-y">
                  {rows.map(({ item, index }, n) => (
                    <li key={index} className="flex gap-3 py-2.5">
                      <span className="w-5 text-sm text-muted-foreground tabular-nums">{n + 1}.</span>
                      <div>
                        <p className="font-medium">{item.object}</p>
                        {item.instruction && <p className="text-sm text-muted-foreground">{item.instruction}</p>}
                      </div>
                    </li>
                  ))}
                </ol>
              ) : (
                <div className="flex flex-col gap-2">
                  {rows.map(({ item, index }, n) => (
                    <div key={index} className="flex items-start gap-2">
                      <span className="mt-1.5 w-5 shrink-0 text-sm text-muted-foreground tabular-nums">{n + 1}.</span>
                      <div className="grid flex-1 gap-2 sm:grid-cols-[1fr_1.4fr]">
                        <Input
                          aria-label={`Objek butir ${n + 1} ${group}`}
                          placeholder="Objek, mis. Rak buku"
                          value={item.object}
                          autoFocus={!item.object && index === items.length - 1}
                          aria-invalid={invalid.includes(index) && !item.object.trim()}
                          onChange={(e) => update(index, { object: e.target.value })}
                        />
                        <Input
                          aria-label={`Petunjuk butir ${n + 1} ${group}`}
                          placeholder="Petunjuk pemeriksaan (opsional)"
                          value={item.instruction}
                          onChange={(e) => update(index, { instruction: e.target.value })}
                        />
                      </div>
                      <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Hapus butir ${n + 1} ${group}`}
                        onClick={() => change(items.filter((_, i) => i !== index))}
                      >
                        <Trash2 />
                      </Button>
                    </div>
                  ))}
                </div>
              )}
            </Panel>
          );
        })}
      </fieldset>
      {!readonly && (
        <ActionBar status={`${items.length} / 100 butir`}>
          <Button variant="outline" disabled={busy} onClick={w.back}>
            Batal
          </Button>
          <Button disabled={busy} onClick={save}>
            {busy ? "Menyimpan…" : "Simpan checklist"}
          </Button>
        </ActionBar>
      )}
    </>
  );
}

export function SchedulePage() {
  const w = useWorkspace();
  return w.route.replaces_id || w.route.view === "schedule-detail" ? <ExistingSchedule /> : <ScheduleEditor />;
}

function ExistingSchedule() {
  const w = useWorkspace();
  const { data, error } = useData<Schedule>("schedule", { record: w.route.replaces_id || w.route.record });
  const [reassign, setReassign] = useState(false);
  if (error) return <ErrorBox message={error} />;
  if (!data) return <Loading />;
  if (w.route.view !== "schedule-detail") return <ScheduleEditor previous={data} />;
  const active = Number(data.active) && data.location_id && (!data.end_date || data.end_date >= w.config.today);
  return (
    <>
      <PageHeader
        crumbs={[{ label: "Jadwal", route: { view: "schedules" } }]}
        title={data.snapshot.room_name}
        description={data.snapshot.template_name}
        meta={
          <>
            <Badge variant={active ? "success" : "outline"}>{active ? "Aktif" : "Berhenti"}</Badge>
            <Badge variant="outline">
              <Repeat />
              {w.options.frequencies[data.frequency]}
            </Badge>
            <Badge variant="outline">
              <User />
              {data.assignee_name}
            </Badge>
            <Badge variant="outline">
              <CalendarClock />
              {dateLabel(data.start_date)} – {data.end_date ? dateLabel(data.end_date) : "seterusnya"}
            </Badge>
          </>
        }
        actions={
          w.config.write &&
          active && (
            <>
              <Button variant="outline" onClick={() => setReassign(true)}>
                <UserCog data-icon="inline-start" />
                Ganti petugas
              </Button>
              <Button variant="outline" onClick={() => w.go({ view: "schedule-edit", replaces_id: data.id })}>
                <Repeat data-icon="inline-start" />
                Ganti jadwal
              </Button>
            </>
          )
        }
      />
      <AssigneeDialog schedule={reassign ? data : undefined} onClose={() => setReassign(false)} />
      <Panel title="Butir yang diperiksa" description={`${data.snapshot.items.length} butir`}>
        <ol className="flex flex-col divide-y">
          {data.snapshot.items.map((i, n) => (
            <li key={n} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
              <span>
                <span className="mr-2 text-sm text-muted-foreground tabular-nums">{n + 1}.</span>
                {i.object}
              </span>
              <span className="flex gap-1.5">
                <Badge variant="outline">{i.group}</Badge>
                <Badge variant="secondary">{i.item_name || "Aspek ruangan"}</Badge>
              </span>
            </li>
          ))}
        </ol>
      </Panel>
    </>
  );
}

type Scope = { items: ChecklistItem[]; assets: { id: Id; item_name: string; item_code: string }[] };

function ScheduleEditor({ previous }: { previous?: Schedule }) {
  const w = useWorkspace();
  const incidental = w.route.view === "new-inspection";
  // A replacement must start after the old schedule's start and not in the past: default to the later of
  // tomorrow and the day after the old start (schedules that have not begun yet start in the future).
  const tomorrow = new Date(w.config.today + "T12:00:00");
  tomorrow.setDate(tomorrow.getDate() + 1);
  if (previous) {
    const afterOld = new Date(previous.start_date + "T12:00:00");
    afterOld.setDate(afterOld.getDate() + 1);
    if (afterOld > tomorrow) tomorrow.setTime(afterOld.getTime());
  }
  const tomorrowString = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, "0")}-${String(tomorrow.getDate()).padStart(2, "0")}`;
  const [values, setValues] = useState({
    location_id: String(previous?.location_id || w.route.room || ""),
    template_id: String(previous?.template_id || w.route.template_id || w.options.templates[0]?.id || ""),
    frequency: previous?.frequency || "monthly",
    start_date: previous ? tomorrowString : w.config.today,
    end_date: "",
    assignee_id: String(previous?.assignee_id || w.config.uid),
    reason: "",
  });
  const [mapping, setMapping] = useState<string[]>(previous?.snapshot.items.map((i) => String(i.item_id || "")) || []);
  const [scope, setScope] = useState<Scope>();
  const [dates, setDates] = useState<string[]>([]);
  const [moved, setMoved] = useState<Record<string, { from: string; reason: string | null }>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [showMapping, setShowMapping] = useState(!!previous?.snapshot.items.some((i) => i.item_id));
  const lock = useRef(false);
  const update = (key: string, v: string) => {
    setValues((old) => ({ ...old, [key]: v }));
    if (key === "location_id" || key === "template_id") setMapping([]);
    w.dirty(true);
  };

  useEffect(() => {
    if (!values.location_id || !values.template_id) {
      setScope(undefined);
      return;
    }
    const controller = new AbortController();
    read<Scope>(w.config, "scope", { room: values.location_id, template_id: values.template_id }, controller.signal)
      .then((s) => {
        setScope(s);
        setMapping((m) => (m.length === s.items.length ? m : s.items.map(() => "")));
      })
      .catch((e) => e.name !== "AbortError" && setError(e.message));
    return () => controller.abort();
  }, [values.location_id, values.template_id]);

  useEffect(() => {
    if (incidental || !values.start_date || !values.frequency) return;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      read<{ dates: string[]; moved: Record<string, { from: string; reason: string | null }> }>(w.config, "preview", values, controller.signal)
        .then((r) => {
          setDates(r.dates);
          setMoved(r.moved || {});
        })
        .catch(() => {});
    }, 250);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [values.frequency, values.start_date, values.end_date]);

  async function save() {
    if (lock.current) return;
    const errors: Record<string, string> = {};
    if (!values.location_id) errors.location_id = "Pilih ruangan.";
    if (!values.template_id) errors.template_id = "Pilih checklist.";
    if (incidental && !values.reason.trim()) errors.reason = "Isi alasan pemeriksaan.";
    if (!incidental) {
      if (!values.assignee_id) errors.assignee_id = "Pilih penanggung jawab.";
      if (!values.start_date) errors.start_date = "Isi tanggal mulai.";
      if (values.end_date && values.end_date < values.start_date) errors.end_date = "Tanggal akhir harus setelah tanggal mulai.";
    }
    setErrors(errors);
    if (Object.keys(errors).length) return;
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
      toast.success(incidental ? "Pemeriksaan dibuat. Silakan isi hasilnya." : "Jadwal tersimpan.");
      const record = reply.url ? new URL(reply.url, window.location.href).searchParams.get("record") : null;
      w.go(incidental && record ? { view: "inspection", record } : { view: "schedules" }, true);
      w.refresh();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      lock.current = false;
      setBusy(false);
    }
  }

  const title = incidental
    ? w.route.parent_id
      ? "Pemeriksaan ulang"
      : "Pemeriksaan insidental"
    : previous
      ? "Ganti jadwal"
      : "Buat jadwal";
  const crumbs = incidental
    ? [{ label: "Tugas", route: { view: "tasks" } }]
    : [{ label: "Jadwal", route: { view: "schedules" } }, ...(previous ? [{ label: previous.snapshot.room_name, route: { view: "schedule-detail", record: previous.id } }] : [])];

  if (!w.options.rooms.length || !w.options.templates.length)
    return (
      <>
        <PageHeader crumbs={crumbs} title={title} />
        <Blank
          icon={ListChecks}
          title="Siapkan ruangan dan checklist dahulu"
          description="Keduanya diperlukan untuk menentukan apa yang diperiksa."
        >
          <div className="flex flex-wrap justify-center gap-2">
            {!w.options.templates.length && (
              <Button onClick={() => w.go({ view: "template-edit" })}>
                <ListChecks data-icon="inline-start" />
                Buat checklist
              </Button>
            )}
            {!w.options.rooms.length && (
              <Button variant="outline" onClick={() => w.go({ view: "room-edit" })}>
                <Building2 data-icon="inline-start" />
                Tambah ruangan
              </Button>
            )}
          </div>
        </Blank>
      </>
    );

  const mapped = mapping.filter(Boolean).length;
  const assignee = w.options.users.find((u) => String(u.user_id) === values.assignee_id)?.realname;

  return (
    <>
      <PageHeader
        crumbs={crumbs}
        title={title}
        description={
          incidental
            ? "Pemeriksaan di luar jadwal, misalnya setelah ada laporan kerusakan. Setelah dibuat, Anda langsung mengisi hasilnya."
            : previous
              ? "Jadwal lama berakhir sehari sebelum jadwal baru dimulai. Riwayat tetap disimpan."
              : "Tugas pemeriksaan dibuat otomatis untuk petugas sesuai frekuensi."
        }
      />
      <ErrorBox message={error} />
      <fieldset disabled={busy || !w.config.write} className="grid min-w-0 gap-6 lg:grid-cols-[1fr_300px]">
        <div className="flex min-w-0 flex-col gap-6">
          <Panel title="Apa yang diperiksa">
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
                label="Checklist"
                required
                error={errors.template_id}
                value={values.template_id}
                onChange={(v) => update("template_id", v)}
                items={w.options.templates.map((x) => ({ value: x.id, label: `${x.name} · versi #${x.id}` }))}
              />
              {incidental && (
                <TextField
                  label="Alasan pemeriksaan"
                  multiline
                  rows={2}
                  required
                  error={errors.reason}
                  placeholder="Contoh: laporan atap bocor setelah hujan deras"
                  value={values.reason}
                  onChange={(v) => update("reason", v)}
                />
              )}
            </FieldGroup>
          </Panel>
          {!incidental && (
            <Panel title="Kapan dan oleh siapa">
              <FieldGroup>
                <Choice
                  label="Frekuensi"
                  value={values.frequency}
                  onChange={(v) => update("frequency", v)}
                  items={entries(w.options.frequencies)}
                />
                <FieldGroup className="grid sm:grid-cols-2">
                  <TextField
                    label={previous ? "Jadwal baru mulai" : "Tanggal mulai"}
                    type="date"
                    required
                    error={errors.start_date}
                    value={values.start_date}
                    onChange={(v) => update("start_date", v)}
                  />
                  <TextField
                    label="Tanggal akhir"
                    description="Kosongkan bila berlaku seterusnya."
                    type="date"
                    error={errors.end_date}
                    value={values.end_date}
                    onChange={(v) => update("end_date", v)}
                  />
                </FieldGroup>
                <Choice
                  label="Penanggung jawab"
                  required
                  error={errors.assignee_id}
                  value={values.assignee_id}
                  onChange={(v) => update("assignee_id", v)}
                  items={w.options.users.map((x) => ({ value: x.user_id, label: x.realname }))}
                />
              </FieldGroup>
            </Panel>
          )}
          {scope && scope.items.length > 0 && (
            <Panel
              title="Hubungkan butir ke barang"
              description={
                scope.assets.length
                  ? "Opsional. Hubungkan butir dengan barang tertentu di ruangan agar riwayat per barang tercatat."
                  : "Ruangan ini belum memiliki barang; semua butir diperiksa sebagai aspek ruangan."
              }
              action={
                scope.assets.length > 0 &&
                !showMapping && (
                  <Button variant="outline" size="sm" onClick={() => setShowMapping(true)}>
                    Atur
                  </Button>
                )
              }
            >
              {showMapping && scope.assets.length > 0 ? (
                <div className="flex flex-col divide-y">
                  {scope.items.map((i, n) => (
                    <div key={n} className="grid items-center gap-2 py-2 sm:grid-cols-[1fr_1fr]">
                      <div>
                        <p className="text-sm font-medium">{i.object}</p>
                        <p className="text-xs text-muted-foreground">{i.group}</p>
                      </div>
                      <Choice
                        value={mapping[n]}
                        placeholder="Aspek ruangan"
                        items={scope.assets.map((a) => ({
                          value: a.id,
                          label: `${a.item_name}${a.item_code ? ` (${a.item_code})` : ""}`,
                        }))}
                        onChange={(v) => {
                          setMapping((old) => old.map((a, k) => (k === n ? v : a)));
                          w.dirty(true);
                        }}
                      />
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">
                  {scope.items.length} butir akan diperiksa sebagai aspek ruangan
                  {mapped ? `; ${mapped} terhubung ke barang` : ""}.
                </p>
              )}
            </Panel>
          )}
        </div>
        <aside className="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
          <Panel title="Ringkasan">
            <dl className="flex flex-col gap-3 text-sm">
              <div>
                <dt className="text-xs text-muted-foreground">Ruangan</dt>
                <dd>{w.options.rooms.find((r) => String(r.id) === values.location_id)?.room_name || "—"}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Checklist</dt>
                <dd>
                  {w.options.templates.find((t) => String(t.id) === values.template_id)?.name || "—"}
                  {scope && ` · ${scope.items.length} butir`}
                </dd>
              </div>
              {!incidental && (
                <>
                  <div>
                    <dt className="text-xs text-muted-foreground">Petugas</dt>
                    <dd>{assignee || "—"}</dd>
                  </div>
                  <div>
                    <dt className="mb-1 text-xs text-muted-foreground">Tanggal pemeriksaan berikutnya</dt>
                    <dd className="flex flex-wrap gap-1.5">
                      {dates.length ? (
                        dates.map((date) =>
                          moved[date] ? (
                            <Badge
                              variant="warning"
                              key={date}
                              title={`Digeser dari ${dateLabel(moved[date].from)} (${moved[date].reason || "hari libur"})`}
                            >
                              {dateLabel(date)}*
                            </Badge>
                          ) : (
                            <Badge variant="outline" key={date}>
                              {dateLabel(date)}
                            </Badge>
                          ),
                        )
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </dd>
                    {Object.keys(moved).length > 0 && (
                      <p className="mt-1.5 text-xs text-muted-foreground">
                        * Digeser ke hari kerja berikutnya karena libur:{" "}
                        {Object.values(moved)
                          .map((m) => `${dateLabel(m.from)} (${m.reason || "libur"})`)
                          .join(", ")}
                        .
                      </p>
                    )}
                  </div>
                </>
              )}
            </dl>
          </Panel>
        </aside>
      </fieldset>
      {w.config.write && (
        <ActionBar status={busy ? "Menyimpan…" : undefined}>
          <Button variant="outline" disabled={busy} onClick={w.back}>
            Batal
          </Button>
          <Button disabled={busy} onClick={save}>
            {incidental ? "Buat dan mulai periksa" : previous ? "Simpan jadwal baru" : "Simpan jadwal"}
          </Button>
        </ActionBar>
      )}
    </>
  );
}
