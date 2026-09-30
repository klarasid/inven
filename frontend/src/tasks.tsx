import { useRef, useState } from "react";
import { toast } from "sonner";
import {
  Plus,
  ArrowRight,
  Check,
  CheckCheck,
  ClipboardCheck,
  Wrench,
  ShieldCheck,
  History as HistoryIcon,
  Upload as UploadIcon,
  ListChecks,
  CalendarDays,
  CircleCheck,
  Circle,
  CalendarClock,
  User,
  Undo2,
  MoreHorizontal,
} from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "./components/ui/card";
import { Tabs, TabsList, TabsTrigger } from "./components/ui/tabs";
import { Table, TableHeader, TableHead, TableRow, TableBody, TableCell } from "./components/ui/table";
import { FieldGroup } from "./components/ui/field";
import { Progress } from "./components/ui/progress";
import { ToggleGroup, ToggleGroupItem } from "./components/ui/toggle-group";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "./components/ui/dialog";
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
} from "./components/ui/dropdown-menu";
import { cn } from "./lib/utils";
import { useData, useWorkspace } from "./context";
import { dateLabel, groups, inspectionErrors, money } from "./api";
import {
  PageHeader,
  ErrorBox,
  Loading,
  Blank,
  Status,
  Pager,
  RoomFilter,
  SearchBox,
  Panel,
  Choice,
  TextField,
  entries,
  Upload,
  Photos,
  History,
  Pdf,
  ActionBar,
} from "./shared";
import type { Document, Result, Page, TaskRow, Photo, Options, Counts, Route } from "./types";

const tasksCrumb = { label: "Tugas", route: { view: "tasks" } as Route };

const outcomeTone: Record<string, string> = {
  good: "data-[state=on]:bg-success/15 data-[state=on]:text-success data-[state=on]:border-success/40",
  action: "data-[state=on]:bg-destructive/10 data-[state=on]:text-destructive data-[state=on]:border-destructive/40",
  unchecked: "data-[state=on]:bg-warning/15 data-[state=on]:text-warning data-[state=on]:border-warning/40",
  na: "data-[state=on]:bg-muted data-[state=on]:text-foreground",
};
const outcomeBadge: Record<string, "success" | "destructive" | "warning" | "secondary"> = {
  good: "success",
  action: "destructive",
  unchecked: "warning",
  na: "secondary",
};

function SetupGuide({ counts }: { counts: Counts }) {
  const { go } = useWorkspace();
  if (counts.templates > 0 && counts.schedules > 0) return null;
  const steps = [
    {
      done: counts.templates > 0,
      icon: ListChecks,
      title: "Buat checklist",
      text: "Daftar butir yang diperiksa di setiap ruangan.",
      run: () => go({ view: "template-edit" }),
    },
    {
      done: counts.schedules > 0,
      icon: CalendarDays,
      title: "Buat jadwal",
      text: "Pilih ruangan, checklist, frekuensi, dan petugas.",
      run: () => go({ view: "schedule-edit" }),
    },
  ];
  return (
    <Card className="border-dashed bg-muted/30">
      <CardHeader>
        <CardTitle>Siapkan pemeriksaan rutin</CardTitle>
        <CardDescription>
          Dua langkah agar tugas pemeriksaan muncul otomatis di halaman ini sesuai jadwal.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3 sm:grid-cols-2">
        {steps.map((s, i) => {
          const locked = i > 0 && !steps[0].done;
          return (
            <div key={s.title} className="flex items-start gap-3 rounded-lg border bg-background p-3">
              <span
                className={cn(
                  "flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-medium",
                  s.done ? "bg-success/15 text-success" : "bg-muted",
                )}
              >
                {s.done ? <Check className="size-4" /> : i + 1}
              </span>
              <div className="flex flex-1 flex-col gap-2">
                <div>
                  <p className="font-medium">{s.title}</p>
                  <p className="text-sm text-muted-foreground">{s.text}</p>
                </div>
                {!s.done && (
                  <Button size="sm" className="self-start" disabled={locked} onClick={s.run}>
                    <s.icon data-icon="inline-start" />
                    {locked ? "Setelah langkah 1" : s.title}
                  </Button>
                )}
              </div>
            </div>
          );
        })}
      </CardContent>
    </Card>
  );
}

export function Tasks() {
  const { route, go, config } = useWorkspace();
  const tab = route.kind || "inspections";
  const history = tab === "history";
  const owner = String(route.owner || (history ? "all" : "mine"));
  const kind = history ? String(route.subject || "inspections") : tab;
  const params = { ...route, kind, owner, history: history ? "1" : "0" };
  const { data, error, loading } = useData<Page<TaskRow>>("tasks", params);
  const { data: counts } = useData<Counts>("counts");
  const count = (k: string) => {
    if (!counts) return undefined;
    if (k === "review") return counts.review;
    if (k === "history") return undefined;
    const c = counts[k as "inspections" | "findings"];
    return owner === "all" ? c.all : c.mine;
  };
  const tabs = [
    { value: "inspections", label: "Pemeriksaan", icon: ClipboardCheck },
    { value: "findings", label: "Tindak lanjut", icon: Wrench },
    { value: "review", label: "Verifikasi", icon: ShieldCheck },
    { value: "history", label: "Riwayat", icon: HistoryIcon },
  ];
  const switchTab = (kind: string) => go({ view: "tasks", kind, q: route.q, room: route.room, page: 1 }, true);
  const otherCount = counts && !history && tab !== "review" && owner === "mine" ? counts[tab as "inspections" | "findings"].all : 0;
  const findingRows = kind !== "inspections";

  return (
    <>
      <PageHeader
        title="Tugas"
        description="Pemeriksaan ruangan, tindak lanjut temuan, dan verifikasi hasil pekerjaan."
        actions={
          config.write && (
            <>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline" size="icon" aria-label="Tindakan lainnya">
                    <MoreHorizontal />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onSelect={() => go({ view: "history-import" })}>
                    <UploadIcon />
                    Impor riwayat dari Excel
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
              <Button onClick={() => go({ view: "new-inspection" })}>
                <Plus data-icon="inline-start" />
                Pemeriksaan insidental
              </Button>
            </>
          )
        }
      />
      {config.write && counts && <SetupGuide counts={counts} />}
      <Tabs value={tab} onValueChange={switchTab}>
        <TabsList variant="line" className="w-full justify-start overflow-x-auto border-b">
          {tabs.map(({ value, label, icon: Icon }) => {
            const n = count(value);
            return (
              <TabsTrigger key={value} value={value} className="flex-none">
                <Icon />
                {label}
                {n !== undefined && n > 0 && (
                  <Badge variant={value === "review" ? "info" : "secondary"} className="ml-1 tabular-nums">
                    {n}
                  </Badge>
                )}
              </TabsTrigger>
            );
          })}
        </TabsList>
      </Tabs>
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <SearchBox placeholder="Cari ruangan…" />
        <RoomFilter />
        {history && (
          <ToggleGroup
            type="single"
            variant="outline"
            value={kind}
            onValueChange={(subject) => subject && go({ ...route, subject, page: 1 }, true)}
          >
            <ToggleGroupItem value="inspections">Pemeriksaan</ToggleGroupItem>
            <ToggleGroupItem value="findings">Tindak lanjut</ToggleGroupItem>
          </ToggleGroup>
        )}
        {tab !== "review" && (
          <ToggleGroup
            type="single"
            variant="outline"
            className="sm:ml-auto"
            value={owner}
            onValueChange={(owner) => owner && go({ ...route, owner, page: 1 }, true)}
            aria-label="Penugasan"
          >
            <ToggleGroupItem value="mine">
              <User />
              Tugas saya
            </ToggleGroupItem>
            <ToggleGroupItem value="all">Semua petugas</ToggleGroupItem>
          </ToggleGroup>
        )}
      </div>
      <ErrorBox message={error} />
      {loading && !data ? (
        <Loading />
      ) : data && !data.rows.length ? (
        <Blank
          icon={history ? HistoryIcon : tab === "review" ? ShieldCheck : CircleCheck}
          title={
            history
              ? "Belum ada riwayat"
              : tab === "review"
                ? "Tidak ada yang perlu diverifikasi"
                : owner === "mine"
                  ? "Tidak ada tugas untuk Anda"
                  : "Semua tugas sudah selesai"
          }
          description={
            history
              ? "Pemeriksaan dan tindak lanjut yang selesai akan tercatat di sini."
              : tab === "review"
                ? "Pekerjaan yang diajukan petugas akan muncul di sini."
                : otherCount
                  ? `Ada ${otherCount} tugas milik petugas lain.`
                  : "Tugas baru muncul sesuai jadwal pemeriksaan atau dari temuan yang perlu tindakan."
          }
        >
          <div className="flex flex-wrap justify-center gap-2">
            {otherCount > 0 && (
              <Button onClick={() => go({ ...route, owner: "all", page: 1 }, true)}>
                Lihat semua tugas ({otherCount})
              </Button>
            )}
            {!history && (
              <Button variant="outline" onClick={() => switchTab("history")}>
                <HistoryIcon data-icon="inline-start" />
                Buka riwayat
              </Button>
            )}
          </div>
        </Blank>
      ) : (
        data && (
          <div className="overflow-hidden rounded-xl border">
            <Table>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  <TableHead>{findingRows ? "Temuan" : "Ruangan"}</TableHead>
                  <TableHead className="hidden sm:table-cell">Petugas</TableHead>
                  <TableHead>{findingRows ? "Tenggat" : "Jadwal"}</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-28">
                    <span className="sr-only">Tindakan</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.rows.map((r) => {
                  const date = String(r.deadline || r.due_date || "");
                  const late = date && date < config.today && !["final", "closed"].includes(r.status);
                  const open = () => go({ view: findingRows ? "finding" : "inspection", record: r.id });
                  return (
                    <TableRow key={r.id} className="cursor-pointer" onClick={open}>
                      <TableCell className="max-w-72 whitespace-normal">
                        <div className="font-medium">{r.result_snapshot?.object || r.snapshot.room_name}</div>
                        <div className="text-xs text-muted-foreground">
                          {findingRows ? r.snapshot.room_name : r.snapshot.library_name}
                          {r.kind === "incidental" && " · Insidental"}
                          {r.kind === "historical" && " · Impor riwayat"}
                        </div>
                      </TableCell>
                      <TableCell className="hidden text-muted-foreground sm:table-cell">
                        {r.assignee_name || r.snapshot.assignee?.name}
                      </TableCell>
                      <TableCell>
                        <div className="flex flex-col items-start gap-1">
                          <span className="tabular-nums">{dateLabel(date)}</span>
                          {late && <Badge variant="destructive">Lewat tenggat</Badge>}
                        </div>
                      </TableCell>
                      <TableCell>
                        <Status value={r.status} />
                      </TableCell>
                      <TableCell className="text-right">
                        <Button
                          size="sm"
                          variant={["final", "closed"].includes(r.status) ? "ghost" : "outline"}
                          onClick={(e) => {
                            e.stopPropagation();
                            open();
                          }}
                        >
                          {r.status === "pending"
                            ? "Mulai"
                            : r.status === "review"
                              ? "Verifikasi"
                              : ["final", "closed"].includes(r.status)
                                ? "Lihat"
                                : "Lanjutkan"}
                          <ArrowRight data-icon="inline-end" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </div>
        )
      )}
      {data && <Pager {...data} onChange={(page) => go({ ...route, page }, true)} />}
    </>
  );
}

function itemLabel(r: Result) {
  return r.snapshot.item_name
    ? `${r.snapshot.item_name}${r.snapshot.item_code ? ` (${r.snapshot.item_code})` : ""}`
    : "Aspek ruangan";
}

function InspectionResultsTable({ results, options, photos }: { results: Result[]; options: Options; photos: Photo[] }) {
  return (
    <div className="flex flex-col gap-3">
      {results.map((r) => {
        const resultPhotos = photos.filter((p) => String(p.result_id) === String(r.id));
        return (
          <div key={r.id} className="flex flex-col gap-2 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <div className="font-medium">{r.snapshot.object}</div>
                <div className="text-xs text-muted-foreground">
                  {r.snapshot.group} · {itemLabel(r)}
                </div>
              </div>
              <Badge variant={outcomeBadge[r.outcome] || "outline"}>{options.outcomes[r.outcome] || "Belum diisi"}</Badge>
            </div>
            {r.notes && <p className="text-sm whitespace-pre-wrap">{r.notes}</p>}
            {resultPhotos.length > 0 && <Photos photos={resultPhotos} size="sm" />}
          </div>
        );
      })}
    </div>
  );
}

function ResultEditor({
  r,
  options,
  photos,
  pending,
  removed,
  error,
  update,
  toggleRemove,
  setPending,
}: {
  r: Result;
  options: Options;
  photos: Photo[];
  pending: File[];
  removed: string[];
  error?: string;
  update: (patch: Partial<Result>) => void;
  toggleRemove: (p: Photo) => void;
  setPending: (f: File[]) => void;
}) {
  const [showPhotos, setShowPhotos] = useState(photos.length > 0 || pending.length > 0);
  return (
    <div
      data-result={r.id}
      className={cn(
        "flex flex-col gap-4 rounded-xl border bg-card p-4",
        error && "border-destructive/60 ring-1 ring-destructive/30",
      )}
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="font-medium">{r.snapshot.object}</div>
          <div className="text-xs text-muted-foreground">{itemLabel(r)}</div>
          {r.snapshot.instruction && <p className="mt-1 text-sm text-muted-foreground">{r.snapshot.instruction}</p>}
        </div>
      </div>
      <ToggleGroup
        type="single"
        variant="outline"
        value={r.outcome}
        onValueChange={(outcome) => outcome && update({ outcome })}
        className="w-full flex-wrap"
        aria-label={`Hasil ${r.snapshot.object}`}
      >
        {entries(options.outcomes).map((o) => (
          <ToggleGroupItem key={o.value} value={o.value} className={cn("flex-1", outcomeTone[o.value])}>
            {o.value === "good" && <Check />}
            {o.label}
          </ToggleGroupItem>
        ))}
      </ToggleGroup>
      {error && <p className="text-sm text-destructive">{error}</p>}
      {(r.outcome && r.outcome !== "good") || r.notes ? (
        <TextField
          label={r.outcome && r.outcome !== "good" ? "Catatan / alasan" : "Catatan"}
          multiline
          rows={2}
          required={!!r.outcome && r.outcome !== "good"}
          value={r.notes}
          onChange={(notes) => update({ notes })}
        />
      ) : null}
      {r.outcome === "action" && (
        <div className="flex flex-col gap-3 rounded-lg bg-destructive/5 p-3">
          <p className="text-sm font-medium">Tindak lanjut yang akan dibuat</p>
          <FieldGroup className="grid gap-3 sm:grid-cols-3">
            <Choice
              label="Penanggung jawab"
              value={r.assignee_id}
              onChange={(assignee_id) => update({ assignee_id })}
              items={options.users.map((x) => ({ value: x.user_id, label: x.realname }))}
            />
            <Choice
              label="Prioritas"
              value={r.priority}
              onChange={(priority) => update({ priority })}
              items={entries(options.priorities)}
            />
            <TextField label="Tenggat" type="date" value={r.deadline} onChange={(deadline) => update({ deadline })} />
          </FieldGroup>
        </div>
      )}
      {showPhotos ? (
        <div className="flex flex-col gap-2">
          <Photos photos={photos} size="sm" removed={removed} onToggle={toggleRemove} />
          <Upload
            files={pending}
            count={photos.length - removed.length}
            onChange={setPending}
            label="Foto bukti (opsional)"
          />
        </div>
      ) : (
        <Button type="button" variant="ghost" size="sm" className="self-start" onClick={() => setShowPhotos(true)}>
          <Plus data-icon="inline-start" />
          Tambah foto bukti
        </Button>
      )}
    </div>
  );
}

export function InspectionPage() {
  const { route } = useWorkspace();
  const { data, error } = useData<Document>("inspection", { record: route.record });
  return (
    <>
      <ErrorBox message={error} />
      {data ? (
        <InspectionEditor key={`${data.inspection.id}-${data.inspection.version}`} document={data} />
      ) : (
        !error && <Loading />
      )}
    </>
  );
}

function InspectionEditor({ document: d }: { document: Document }) {
  const w = useWorkspace();
  const { config, options } = w;
  const editable = config.write && d.inspection.status !== "final";
  const [results, setResults] = useState(d.results);
  const [performed, setPerformed] = useState(d.inspection.performed_date || config.today);
  const [notes, setNotes] = useState(d.inspection.notes || "");
  const [step, setStep] = useState(0);
  const [tab, setTab] = useState("results");
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  const [message, setMessage] = useState("");
  const [photos, setPhotos] = useState(d.photos);
  const [files, setFiles] = useState<Record<string, File[]>>({});
  const [removed, setRemoved] = useState<Record<string, string[]>>({});
  const [correction, setCorrection] = useState("");
  const [finishing, setFinishing] = useState(false);
  const version = useRef(Number(d.inspection.version));
  const available = groups.filter((group) => results.some((r) => r.snapshot.group === group));
  const current = available[step];
  const root = useRef<HTMLDivElement>(null);
  const uploadKeys = useRef(new WeakMap<File, string>());
  const filled = results.filter((r) => r.outcome).length;
  const update = (id: Result["id"], patch: Partial<Result>) => {
    setResults((rows) => rows.map((r) => (String(r.id) === String(id) ? { ...r, ...patch } : r)));
    setErrors((e) => {
      if (!e[String(id)]) return e;
      const { [String(id)]: _, ...rest } = e;
      return rest;
    });
    w.dirty(true);
  };
  const markGood = () => {
    setResults((rows) => rows.map((r) => (r.snapshot.group === current && !r.outcome ? { ...r, outcome: "good" } : r)));
    w.dirty(true);
  };
  const jump = (id: string) => {
    const index = available.indexOf(results.find((r) => String(r.id) === id)?.snapshot.group || "");
    setStep(Math.max(0, index));
    setTimeout(() => {
      const el = root.current?.querySelector<HTMLElement>(`[data-result="${id}"]`);
      el?.scrollIntoView({ block: "center", behavior: "smooth" });
      el?.querySelector<HTMLElement>("button,input,textarea")?.focus();
    }, 50);
  };
  function finish() {
    const errors = inspectionErrors(results, performed, config.today);
    delete errors.performed_date;
    setErrors(errors);
    const id = Object.keys(errors)[0];
    if (id) {
      setError(`${Object.keys(errors).length} butir belum lengkap. Lengkapi butir yang ditandai merah.`);
      jump(id);
      return;
    }
    setError("");
    setFinishing(true);
  }
  async function save(final: boolean) {
    if (busyRef.current) return;
    if (final) {
      const errors = inspectionErrors(results, performed, config.today);
      setErrors(errors);
      if (Object.keys(errors).length) {
        setError("Lengkapi butir yang ditandai sebelum menyelesaikan pemeriksaan.");
        return;
      }
    }
    busyRef.current = true;
    setBusy(true);
    setError("");
    setMessage("Menyimpan draf…");
    try {
      const answers = Object.fromEntries(
        results.map(({ id, outcome, notes, assignee_id, priority, deadline }) => [
          id,
          { outcome, notes, assignee_id, priority, deadline },
        ]),
      );
      const values = { watch_action: "inspection", id: d.inspection.id, performed_date: performed, notes, results: answers };
      const draft = await w.mutate({ ...values, version: version.current, submit_mode: "draft" });
      version.current = Number(draft.document!.version);
      for (const r of results) {
        const key = String(r.id);
        const removes = removed[key] || [];
        const pending = files[key] || [];
        if (removes.length) {
          const reply = await w.mutate({
            watch_action: "result_photos",
            inspection_id: d.inspection.id,
            result_id: r.id,
            version: version.current,
            remove: removes,
          });
          version.current = Number(reply.document!.version);
          setPhotos(reply.document!.photos);
          setRemoved((prev) => ({ ...prev, [key]: [] }));
        }
        for (const file of pending) {
          setMessage(`Mengunggah foto ${r.snapshot.object}: ${file.name}`);
          const body = new FormData();
          body.append("photos[]", file);
          let requestId = uploadKeys.current.get(file);
          if (!requestId) {
            requestId = crypto.randomUUID();
            uploadKeys.current.set(file, requestId);
          }
          try {
            const reply = await w.mutate(
              {
                watch_action: "result_photos",
                inspection_id: d.inspection.id,
                result_id: r.id,
                version: version.current,
                request_id: requestId,
              },
              body,
            );
            version.current = Number(reply.document!.version);
            setPhotos(reply.document!.photos);
            setFiles((prev) => ({ ...prev, [key]: (prev[key] || []).filter((f) => f !== file) }));
          } catch (e) {
            setErrors({ [key]: `Foto ${file.name} belum tersimpan. Coba simpan kembali.` });
            jump(key);
            throw e;
          }
        }
      }
      if (final) {
        setMessage("Menyelesaikan pemeriksaan…");
        await w.mutate({ ...values, version: version.current, submit_mode: "final" });
        w.dirty(false);
        setFinishing(false);
        toast.success("Pemeriksaan selesai. Temuan yang perlu tindakan sudah dibuat.");
        w.refresh();
      } else {
        w.dirty(false);
        setMessage("Draf dan seluruh foto tersimpan.");
        toast.success("Draf tersimpan.");
      }
    } catch (e) {
      setFinishing(false);
      setError((e as Error).message);
      setMessage("Belum tersimpan. Periksa pesan di atas.");
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }
  const tally = (outcome: string) => results.filter((r) => r.outcome === outcome).length;
  const kindLabel =
    d.inspection.kind === "historical" ? "Impor riwayat" : d.inspection.kind === "routine" ? "Terjadwal" : "Insidental";

  return (
    <div ref={root} className="flex flex-col gap-6">
      <PageHeader
        crumbs={[
          tasksCrumb,
          {
            label: d.inspection.status === "final" ? "Riwayat" : "Pemeriksaan",
            route: { view: "tasks", kind: d.inspection.status === "final" ? "history" : "inspections" },
          },
        ]}
        title={d.snapshot.room_name}
        description={`${d.snapshot.library_name} · ${d.snapshot.template_name}`}
        meta={
          <>
            <Status value={d.inspection.status} />
            <Badge variant="outline">{kindLabel}</Badge>
            <Badge variant="outline">
              <CalendarClock />
              {dateLabel(d.inspection.due_date)}
            </Badge>
            <Badge variant="outline">
              <User />
              {d.snapshot.assignee?.name}
            </Badge>
            {d.inspection.reason && <span className="text-sm text-muted-foreground">Alasan: {d.inspection.reason}</span>}
          </>
        }
        actions={
          <>
            {d.inspection.parent_id && (
              <Button variant="ghost" onClick={() => w.go({ view: "inspection", record: d.inspection.parent_id! })}>
                Pemeriksaan asal
              </Button>
            )}
            <Pdf record={d.inspection.id} />
          </>
        }
      />
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList variant="line" className="w-full justify-start border-b">
          <TabsTrigger value="results" className="flex-none">
            Hasil pemeriksaan
          </TabsTrigger>
          <TabsTrigger value="history" className="flex-none">
            Riwayat kegiatan
          </TabsTrigger>
        </TabsList>
      </Tabs>
      <ErrorBox message={error} />
      {tab === "history" && <History events={d.events} />}
      {tab === "results" && editable && (
        <>
          <div className="grid gap-6 md:grid-cols-[220px_1fr]">
            <aside className="flex flex-col gap-4 md:sticky md:top-4 md:self-start">
              <div className="flex flex-col gap-2">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Terisi</span>
                  <span className="font-medium tabular-nums">
                    {filled} / {results.length}
                  </span>
                </div>
                <Progress value={(filled / results.length) * 100} aria-label="Kelengkapan hasil" />
              </div>
              <nav className="flex gap-1 overflow-x-auto md:flex-col" aria-label="Kelompok butir">
                {available.map((g, i) => {
                  const rows = results.filter((r) => r.snapshot.group === g);
                  const done = rows.filter((r) => r.outcome).length;
                  const invalid = rows.some((r) => errors[String(r.id)]);
                  return (
                    <Button
                      key={g}
                      variant={step === i ? "secondary" : "ghost"}
                      className="flex-none justify-between md:w-full"
                      onClick={() => setStep(i)}
                    >
                      <span className="flex items-center gap-2">
                        {done === rows.length ? (
                          <CircleCheck className="text-success" />
                        ) : (
                          <Circle className={cn(invalid && "text-destructive")} />
                        )}
                        {g}
                      </span>
                      <span className="text-xs text-muted-foreground tabular-nums">
                        {done}/{rows.length}
                      </span>
                    </Button>
                  );
                })}
              </nav>
            </aside>
            <fieldset disabled={busy} className="flex min-w-0 flex-col gap-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold">{current}</h2>
                {results.some((r) => r.snapshot.group === current && !r.outcome) && (
                  <Button variant="outline" size="sm" onClick={markGood}>
                    <CheckCheck data-icon="inline-start" />
                    Tandai sisanya Baik
                  </Button>
                )}
              </div>
              {results
                .filter((r) => r.snapshot.group === current)
                .map((r) => {
                  const key = String(r.id);
                  return (
                    <ResultEditor
                      key={key}
                      r={r}
                      options={options}
                      photos={photos.filter((p) => String(p.result_id) === key)}
                      pending={files[key] || []}
                      removed={removed[key] || []}
                      error={errors[key]}
                      update={(patch) => update(r.id, patch)}
                      toggleRemove={(p) => {
                        setRemoved((prev) => {
                          const list = prev[key] || [];
                          return {
                            ...prev,
                            [key]: list.includes(String(p.id))
                              ? list.filter((id) => id !== String(p.id))
                              : [...list, String(p.id)],
                          };
                        });
                        w.dirty(true);
                      }}
                      setPending={(picked) => {
                        setFiles((prev) => ({ ...prev, [key]: picked }));
                        w.dirty(true);
                      }}
                    />
                  );
                })}
            </fieldset>
          </div>
          <ActionBar status={message}>
            <Button variant="outline" disabled={busy} onClick={() => save(false)}>
              Simpan draf
            </Button>
            {step < available.length - 1 ? (
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() => {
                  setStep(step + 1);
                  root.current?.scrollIntoView({ block: "start" });
                }}
              >
                {available[step + 1]}
                <ArrowRight data-icon="inline-end" />
              </Button>
            ) : null}
            <Button disabled={busy} onClick={finish}>
              <Check data-icon="inline-start" />
              Selesaikan pemeriksaan
            </Button>
          </ActionBar>
          <Dialog open={finishing} onOpenChange={(open) => !busy && setFinishing(open)}>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Selesaikan pemeriksaan?</DialogTitle>
                <DialogDescription>
                  Hasil akan dikunci. Koreksi setelahnya disimpan sebagai catatan tambahan.
                </DialogDescription>
              </DialogHeader>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {entries(options.outcomes).map((o) => (
                  <div key={o.value} className="rounded-lg border p-2 text-center">
                    <p className="text-lg font-semibold tabular-nums">{tally(o.value)}</p>
                    <p className="text-xs text-muted-foreground">{o.label}</p>
                  </div>
                ))}
              </div>
              {tally("action") > 0 && (
                <p className="text-sm">
                  <strong>{tally("action")} temuan</strong> akan dibuat dan dikirim ke penanggung jawabnya.
                </p>
              )}
              <FieldGroup>
                <TextField
                  label="Tanggal pelaksanaan"
                  type="date"
                  value={performed}
                  onChange={(v) => {
                    setPerformed(v);
                    w.dirty(true);
                  }}
                  required
                  max={config.today}
                  error={errors.performed_date}
                />
                <TextField
                  label="Catatan pemeriksaan (opsional)"
                  value={notes}
                  multiline
                  rows={2}
                  onChange={(v) => {
                    setNotes(v);
                    w.dirty(true);
                  }}
                />
              </FieldGroup>
              <DialogFooter>
                <Button variant="outline" disabled={busy} onClick={() => setFinishing(false)}>
                  Periksa lagi
                </Button>
                <Button disabled={busy} onClick={() => save(true)}>
                  {busy ? message || "Menyimpan…" : "Ya, selesaikan"}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </>
      )}
      {tab === "results" && !editable && (
        <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
          <InspectionResultsTable results={results} options={options} photos={photos} />
          <div className="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
            <Panel title="Ringkasan">
              <dl className="grid grid-cols-2 gap-3">
                {entries(options.outcomes).map((o) => (
                  <div key={o.value}>
                    <dt className="text-xs text-muted-foreground">{o.label}</dt>
                    <dd className="text-lg font-semibold tabular-nums">{tally(o.value)}</dd>
                  </div>
                ))}
              </dl>
              {d.inspection.performed_date && (
                <p className="mt-3 text-sm text-muted-foreground">Dilaksanakan {dateLabel(d.inspection.performed_date)}</p>
              )}
              {d.inspection.notes && <p className="mt-2 text-sm whitespace-pre-wrap">{d.inspection.notes}</p>}
            </Panel>
            <Panel title="Tindak lanjut">
              {d.findings.length ? (
                <div className="flex flex-col gap-2">
                  {d.findings.map((f) => {
                    const result = d.results.find((r) => String(r.id) === String(f.result_id));
                    return (
                      <button
                        type="button"
                        key={f.id}
                        className="flex items-center justify-between gap-2 rounded-lg border p-2 text-left text-sm hover:bg-muted/50"
                        onClick={() => w.go({ view: "finding", record: f.id })}
                      >
                        <span className="min-w-0 truncate">{result?.snapshot.object || `Temuan #${f.id}`}</span>
                        <Status value={f.status} />
                      </button>
                    );
                  })}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">Tidak ada temuan yang perlu tindakan.</p>
              )}
            </Panel>
            {config.write && (
              <Panel title="Koreksi" description="Tambahkan catatan koreksi atau buat pemeriksaan ulang.">
                <FieldGroup>
                  <TextField
                    label="Catatan koreksi"
                    value={correction}
                    onChange={(v) => {
                      setCorrection(v);
                      w.dirty(true);
                    }}
                    multiline
                    rows={2}
                  />
                  <div className="flex flex-wrap gap-2">
                    <Button
                      size="sm"
                      disabled={busy || !correction.trim()}
                      onClick={async () => {
                        setBusy(true);
                        try {
                          await w.mutate({ watch_action: "correction", id: d.inspection.id, notes: correction });
                          w.dirty(false);
                          toast.success("Catatan koreksi tersimpan.");
                          w.refresh();
                        } catch (e) {
                          setError((e as Error).message);
                        } finally {
                          setBusy(false);
                        }
                      }}
                    >
                      Simpan catatan
                    </Button>
                    {d.inspection.location_id && (
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                          w.go({
                            view: "new-inspection",
                            parent_id: d.inspection.id,
                            room: d.inspection.location_id!,
                            template_id: d.snapshot.template_id ?? undefined,
                          })
                        }
                      >
                        Pemeriksaan ulang
                      </Button>
                    )}
                  </div>
                </FieldGroup>
              </Panel>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

export function FindingPage() {
  const { route } = useWorkspace();
  const { data, error } = useData<Document>("finding", { record: route.record });
  return (
    <>
      <ErrorBox message={error} />
      {data ? <FindingEditor key={`${data.finding!.id}-${data.finding!.version}`} document={data} /> : !error && <Loading />}
    </>
  );
}

const actionKinds: Record<string, string> = { repair: "Perbaikan", maintenance: "Pemeliharaan", none: "Tanpa pekerjaan" };

function FindingEditor({ document: d }: { document: Document }) {
  const w = useWorkspace();
  const f = d.finding!;
  const result = d.results.find((r) => String(r.id) === String(f.result_id))!;
  const actions = d.actions.filter((a) => String(a.finding_id) === String(f.id));
  const draft = actions.find((a) => !a.submitted_at);
  const [values, setValues] = useState({
    kind: draft?.kind || "repair",
    description: draft?.description || "",
    performed_date: draft?.performed_date || w.config.today,
    cost: draft?.cost || "",
    notes: "",
  });
  const [files, setFiles] = useState<File[]>([]);
  const [removed, setRemoved] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const lock = useRef(false);
  const [error, setError] = useState("");
  const [tab, setTab] = useState("work");
  const update = (key: string, value: string) => {
    setValues((v) => ({ ...v, [key]: value }));
    w.dirty(true);
  };
  async function save(mode: string) {
    if (lock.current) return;
    lock.current = true;
    setBusy(true);
    setError("");
    try {
      const body = new FormData();
      files.forEach((file) => body.append("photos[]", file));
      await w.mutate({ watch_action: "finding", id: f.id, version: f.version, mode, ...values, remove: removed }, body);
      w.dirty(false);
      toast.success(
        mode === "submit"
          ? "Pekerjaan diajukan untuk verifikasi."
          : mode === "verify"
            ? "Hasil diterima."
            : mode === "reject"
              ? "Pekerjaan dikembalikan."
              : "Draf pekerjaan tersimpan.",
      );
      w.refresh();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      lock.current = false;
      setBusy(false);
    }
  }
  const workPhotos = d.photos.filter((p) => draft && String(p.action_id) === String(draft.id));
  const late = f.deadline < w.config.today && f.status !== "closed";
  const editable = w.config.write && ["open", "working"].includes(f.status);
  const reviewing = w.config.write && f.status === "review";
  const steps = [
    { key: "open", label: "Temuan dibuat" },
    { key: "working", label: "Pekerjaan dicatat" },
    { key: "review", label: "Verifikasi" },
    { key: "closed", label: "Selesai" },
  ];
  const stepIndex = steps.findIndex((s) => s.key === f.status);

  return (
    <>
      <PageHeader
        crumbs={[
          tasksCrumb,
          f.status === "closed"
            ? { label: "Riwayat", route: { view: "tasks", kind: "history", subject: "findings" } }
            : f.status === "review"
              ? { label: "Verifikasi", route: { view: "tasks", kind: "review" } }
              : { label: "Tindak lanjut", route: { view: "tasks", kind: "findings" } },
        ]}
        title={result.snapshot.object}
        description={`${d.snapshot.room_name} · ${itemLabel(result)}`}
        meta={
          <>
            <Status value={f.status} />
            <Badge variant="outline">Prioritas {w.options.priorities[f.priority]?.toLowerCase()}</Badge>
            <Badge variant={late ? "destructive" : "outline"}>
              <CalendarClock />
              Tenggat {dateLabel(f.deadline)}
            </Badge>
            <Badge variant="outline">
              <User />
              {f.assignee_name}
            </Badge>
          </>
        }
        actions={
          <Button variant="outline" onClick={() => w.go({ view: "inspection", record: f.inspection_id })}>
            Pemeriksaan asal
          </Button>
        }
      />
      <ol className="flex flex-wrap items-center gap-2 text-sm" aria-label="Tahapan tindak lanjut">
        {steps.map((s, i) => (
          <li key={s.key} className="flex items-center gap-2">
            <span
              className={cn(
                "flex items-center gap-1.5 rounded-full border px-2.5 py-0.5",
                i < stepIndex && "border-success/40 text-success",
                i === stepIndex && "border-foreground bg-foreground text-background",
                i > stepIndex && "text-muted-foreground",
              )}
            >
              {i < stepIndex ? <Check className="size-3.5" /> : <span className="tabular-nums">{i + 1}</span>}
              {s.label}
            </span>
            {i < steps.length - 1 && <ArrowRight className="size-3.5 text-muted-foreground" />}
          </li>
        ))}
      </ol>
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList variant="line" className="w-full justify-start border-b">
          <TabsTrigger value="work" className="flex-none">
            Pekerjaan
          </TabsTrigger>
          <TabsTrigger value="history" className="flex-none">
            Riwayat kegiatan
          </TabsTrigger>
        </TabsList>
      </Tabs>
      <ErrorBox message={error} />
      {tab === "history" && <History events={d.events.filter((e) => String(e.finding_id) === String(f.id))} />}
      {tab === "work" && (
        <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
          <div className="flex min-w-0 flex-col gap-6">
            {editable ? (
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  save("submit");
                }}
              >
                <fieldset disabled={busy} className="flex min-w-0 flex-col gap-6">
                  <Panel title="Catat pekerjaan" description="Isi tindakan dan bukti, lalu ajukan untuk diverifikasi.">
                    <FieldGroup>
                      <ToggleGroup
                        type="single"
                        variant="outline"
                        value={values.kind}
                        onValueChange={(v) => v && update("kind", v)}
                        className="w-full"
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
                        value={values.description}
                        onChange={(v) => update("description", v)}
                      />
                      <FieldGroup className="grid sm:grid-cols-2">
                        <TextField
                          label="Tanggal pekerjaan"
                          type="date"
                          required
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
                      <Photos
                        photos={workPhotos}
                        size="sm"
                        removed={removed}
                        onToggle={(p) => {
                          setRemoved((prev) =>
                            prev.includes(String(p.id)) ? prev.filter((id) => id !== String(p.id)) : [...prev, String(p.id)],
                          );
                          w.dirty(true);
                        }}
                      />
                      <Upload
                        label={values.kind === "none" ? "Foto hasil (opsional)" : "Foto hasil pekerjaan"}
                        files={files}
                        onChange={(f) => {
                          setFiles(f);
                          w.dirty(true);
                        }}
                        count={workPhotos.length - removed.length}
                        hint={
                          values.kind === "none"
                            ? "Foto opsional untuk tindakan tanpa pekerjaan."
                            : "Minimal satu foto diperlukan untuk pengajuan. Maksimal 5 foto, 2 MB per foto."
                        }
                      />
                    </FieldGroup>
                  </Panel>
                  <ActionBar status={busy ? "Menyimpan…" : undefined}>
                    <Button type="button" variant="outline" onClick={() => save("draft")}>
                      Simpan draf
                    </Button>
                    <Button type="submit">
                      Ajukan verifikasi
                      <ArrowRight data-icon="inline-end" />
                    </Button>
                  </ActionBar>
                </fieldset>
              </form>
            ) : (
              draft && (
                <Panel title="Draf pekerjaan">
                  <p className="mb-3 whitespace-pre-wrap">{draft.description}</p>
                  <Photos photos={workPhotos} size="sm" />
                </Panel>
              )
            )}
            {actions
              .filter((a) => a.submitted_at)
              .reverse()
              .map((a) => (
                <Panel
                  key={a.id}
                  title={`${actionKinds[a.kind] || a.kind} · ${dateLabel(a.performed_date)}`}
                  description={`${a.actor_name}${a.cost ? ` · ${money(a.cost)}` : ""}`}
                >
                  <p className="mb-3 whitespace-pre-wrap">{a.description}</p>
                  <Photos photos={d.photos.filter((p) => String(p.action_id) === String(a.id))} size="sm" />
                </Panel>
              ))}
            {reviewing && (
              <Panel title="Verifikasi hasil" description="Periksa pekerjaan di atas, lalu terima atau kembalikan.">
                <fieldset disabled={busy}>
                  <FieldGroup>
                    <TextField
                      label="Catatan verifikasi"
                      description="Wajib diisi, termasuk alasan bila dikembalikan."
                      multiline
                      required
                      value={values.notes}
                      onChange={(v) => update("notes", v)}
                    />
                    <div className="flex flex-wrap gap-2">
                      <Button disabled={!values.notes.trim()} onClick={() => save("verify")}>
                        <Check data-icon="inline-start" />
                        Terima hasil
                      </Button>
                      <Button variant="outline" disabled={!values.notes.trim()} onClick={() => save("reject")}>
                        <Undo2 data-icon="inline-start" />
                        Kembalikan untuk perbaikan
                      </Button>
                    </div>
                  </FieldGroup>
                </fieldset>
              </Panel>
            )}
          </div>
          <Panel title="Temuan awal" className="lg:sticky lg:top-4 lg:self-start">
            <p className="text-sm whitespace-pre-wrap">{result.notes || "—"}</p>
            <div className="mt-3">
              <Photos photos={d.photos.filter((p) => String(p.result_id) === String(f.result_id))} size="sm" />
            </div>
          </Panel>
        </div>
      )}
    </>
  );
}
