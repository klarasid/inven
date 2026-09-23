import { useRef, useState } from "react";
import { toast } from "sonner";
import { Plus, ArrowRight, Check } from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "./components/ui/tabs";
import {
  Table,
  TableHeader,
  TableHead,
  TableRow,
  TableBody,
  TableCell,
} from "./components/ui/table";
import { FieldGroup } from "./components/ui/field";
import { Progress } from "./components/ui/progress";
import {
  Accordion,
  AccordionItem,
  AccordionTrigger,
  AccordionContent,
} from "./components/ui/accordion";
import { useData, useWorkspace } from "./context";
import { dateLabel, groups, inspectionErrors, money } from "./api";
import {
  Heading,
  ErrorBox,
  Loading,
  Blank,
  Status,
  Pager,
  Filters,
  Search,
  Panel,
  Choice,
  TextField,
  entries,
  Upload,
  Photos,
  History,
  Pdf,
  Actions,
} from "./shared";
import type {
  Document,
  Result,
  Page,
  TaskRow,
  Photo,
  WorkAction,
  Options,
} from "./types";
export function Tasks() {
  const { route, go, config } = useWorkspace();
  const kind = route.kind || "inspections";
  const { data, error, loading } = useData<Page<TaskRow>>("tasks", route);
  return (
    <>
      <Heading
        title="Tugas"
        description="Selesaikan pemeriksaan dan tindak lanjut, satu pekerjaan pada satu waktu."
        action={
          config.write && (
            <div className="flex flex-wrap gap-2">
              <Button
                variant="outline"
                onClick={() => go({ view: "history-import" })}
              >
                Impor riwayat
              </Button>
              <Button onClick={() => go({ view: "new-inspection" })}>
                <Plus data-icon="inline-start" />
                Pemeriksaan insidental
              </Button>
            </div>
          )
        }
      />
      <Tabs
        value={kind}
        onValueChange={(kind) =>
          go({ ...route, kind, page: 1, history: "0" }, true)
        }
      >
        <TabsList>
          <TabsTrigger value="inspections">Pemeriksaan</TabsTrigger>
          <TabsTrigger value="findings">Tindak lanjut</TabsTrigger>
          <TabsTrigger value="review">Verifikasi</TabsTrigger>
        </TabsList>
      </Tabs>
      <div className="flex items-end gap-3 flex-wrap">
        <Search />
        {kind !== "review" && (
          <div className="w-44">
            <Choice
              label="Penugasan"
              value={route.owner || "mine"}
              onChange={(owner) => go({ ...route, owner, page: 1 }, true)}
              items={[
                { value: "mine", label: "Tugas saya" },
                { value: "all", label: "Semua tugas" },
              ]}
            />
          </div>
        )}
        <Filters showHistory={kind !== "review"} />
      </div>
      {kind === "review" && (
        <p className="text-sm text-muted-foreground">
          Antrean verifikasi bersama. Semua pengguna dengan hak tulis dapat
          memeriksa hasil.
        </p>
      )}
      <ErrorBox message={error} />
      {loading ? (
        <Loading />
      ) : data && !data.rows.length ? (
        <Blank
          title="Tidak ada tugas dalam daftar ini"
          description={
            kind === "review"
              ? "Pekerjaan yang diajukan akan muncul di sini."
              : "Coba Semua tugas atau ubah filter untuk melihat pekerjaan lainnya."
          }
        />
      ) : (
        data && (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>
                    {kind === "inspections" ? "Ruangan" : "Temuan"}
                  </TableHead>
                  <TableHead>
                    {kind === "inspections" ? "Jadwal" : "Tenggat"}
                  </TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>
                    <span className="sr-only">Tindakan</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.rows.map((r) => {
                  const date = r.deadline || r.due_date;
                  return (
                    <TableRow key={r.id}>
                      <TableCell>
                        <div className="font-medium">
                          {r.result_snapshot?.object || r.snapshot.room_name}
                        </div>
                        <div className="text-xs text-muted-foreground">
                          {kind === "inspections"
                            ? r.snapshot.library_name
                            : r.snapshot.room_name}{" "}
                          · {r.assignee_name || r.snapshot.assignee?.name}
                        </div>
                      </TableCell>
                      <TableCell>
                        {dateLabel(date)}
                        {date < config.today &&
                          !["final", "closed"].includes(r.status) && (
                            <div>
                              <Badge variant="destructive">Lewat tenggat</Badge>
                            </div>
                          )}
                      </TableCell>
                      <TableCell>
                        <Status value={r.status} />
                      </TableCell>
                      <TableCell>
                        <Button
                          variant="outline"
                          onClick={() =>
                            go({
                              view:
                                kind === "inspections"
                                  ? "inspection"
                                  : "finding",
                              record: r.id,
                            })
                          }
                        >
                          {r.status === "pending"
                            ? "Periksa"
                            : r.status === "review"
                              ? "Verifikasi"
                              : "Buka"}
                          <ArrowRight data-icon="inline-end" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </>
        )
      )}
      {data && (
        <Pager {...data} onChange={(page) => go({ ...route, page }, true)} />
      )}
    </>
  );
}

function InspectionResultsTable({
  results,
  options,
  photos,
}: {
  results: Result[];
  options: Options;
  photos: Photo[];
}) {
  return (
    <Panel
      title="Hasil pemeriksaan"
      description="Ringkasan seluruh butir pemeriksaan."
    >
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Kelompok / Butir</TableHead>
            <TableHead>Objek</TableHead>
            <TableHead>Hasil</TableHead>
            <TableHead>Catatan</TableHead>
            <TableHead>Foto</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {results.map((r) => {
            const photoCount = photos.filter(
              (p) => String(p.result_id) === String(r.id),
            ).length;
            return (
              <TableRow key={r.id}>
                <TableCell>
                  <div className="font-medium">{r.snapshot.group}</div>
                  <div className="text-xs text-muted-foreground">
                    Butir #{r.id}
                  </div>
                </TableCell>
                <TableCell>
                  <div className="font-medium">{r.snapshot.object}</div>
                  <div className="text-xs text-muted-foreground">
                    {r.snapshot.item_name
                      ? `${r.snapshot.item_name}${r.snapshot.item_code ? ` (${r.snapshot.item_code})` : ""}`
                      : "Aspek ruangan"}
                  </div>
                </TableCell>
                <TableCell>
                  <Badge variant="secondary">
                    {options.outcomes[r.outcome] || "Belum diisi"}
                  </Badge>
                </TableCell>
                <TableCell>
                  <p className="text-sm whitespace-pre-wrap">
                    {r.notes || "-"}
                  </p>
                </TableCell>
                <TableCell>
                  <span className="text-sm">{photoCount} foto</span>
                  {photoCount > 0 && (
                    <Photos
                      photos={photos.filter(
                        (p) => String(p.result_id) === String(r.id),
                      )}
                    />
                  )}
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </Panel>
  );
}

function InspectionResultsEditableTable({
  results,
  options,
  photos,
  files,
  removed,
  errors,
  update,
  setRemoved,
  setFiles,
  markDirty,
}: {
  results: Result[];
  options: Options;
  photos: Photo[];
  files: Record<string, File[]>;
  removed: Record<string, string[]>;
  errors: Record<string, string>;
  update: (id: Result["id"], patch: Partial<Result>) => void;
  setRemoved: React.Dispatch<React.SetStateAction<Record<string, string[]>>>;
  setFiles: React.Dispatch<React.SetStateAction<Record<string, File[]>>>;
  markDirty: () => void;
}) {
  return (
    <Panel
      title="Hasil pemeriksaan"
      description="Isi hasil, catatan, dan bukti langsung per butir dalam tabel."
    >
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Butir</TableHead>
            <TableHead>Hasil</TableHead>
            <TableHead>Catatan</TableHead>
            <TableHead>Tindak lanjut</TableHead>
            <TableHead>Foto</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {results.map((r) => {
            const resultPhotos = photos.filter(
              (p) => String(p.result_id) === String(r.id),
            );
            const key = String(r.id);
            const removedIds = removed[key] || [];
            const visiblePhotos = resultPhotos.filter(
              (p) => !removedIds.includes(String(p.id)),
            );
            const pending = files[key] || [];
            return (
              <TableRow key={r.id}>
                <TableCell>
                  <div className="font-medium">{r.snapshot.object}</div>
                  <div className="text-xs text-muted-foreground">
                    {r.snapshot.group} · {r.snapshot.item_name || "Aspek ruangan"}
                  </div>
                  <ErrorBox message={errors[key] || ""} />
                </TableCell>
                <TableCell>
                  <Choice
                    label=""
                    value={r.outcome}
                    onChange={(outcome) => update(r.id, { outcome })}
                    items={entries(options.outcomes)}
                    error={errors[key]}
                  />
                </TableCell>
                <TableCell>
                  <TextField
                    label=""
                    multiline
                    required={r.outcome !== "good"}
                    value={r.notes}
                    onChange={(notes) => update(r.id, { notes })}
                  />
                </TableCell>
                <TableCell>
                  {r.outcome === "action" ? (
                    <div className="grid gap-2 min-w-60">
                      <Choice
                        label="Penanggung jawab"
                        value={r.assignee_id}
                        onChange={(assignee_id) => update(r.id, { assignee_id })}
                        items={options.users.map((x) => ({
                          value: x.user_id,
                          label: x.realname,
                        }))}
                      />
                      <Choice
                        label="Prioritas"
                        value={r.priority}
                        onChange={(priority) => update(r.id, { priority })}
                        items={entries(options.priorities)}
                      />
                      <TextField
                        label="Tenggat"
                        type="date"
                        value={r.deadline}
                        onChange={(deadline) => update(r.id, { deadline })}
                      />
                    </div>
                  ) : (
                    <span className="text-sm text-muted-foreground">-</span>
                  )}
                </TableCell>
                <TableCell>
                  <Accordion type="single" collapsible>
                    <AccordionItem value={`evidence-${r.id}`}>
                      <AccordionTrigger>
                        {visiblePhotos.length + pending.length} foto
                      </AccordionTrigger>
                      <AccordionContent>
                        <FieldGroup>
                          <Photos photos={visiblePhotos} />
                          {resultPhotos.map((p) => (
                            <Button
                              key={p.id}
                              variant="ghost"
                              type="button"
                              onClick={() => {
                                setRemoved((prev) => ({
                                  ...prev,
                                  [key]: (prev[key] || []).includes(String(p.id))
                                    ? (prev[key] || []).filter(
                                        (id) => id !== String(p.id),
                                      )
                                    : [...(prev[key] || []), String(p.id)],
                                }));
                                markDirty();
                              }}
                            >
                              {(removedIds || []).includes(String(p.id))
                                ? "Batalkan penghapusan"
                                : "Hapus"}{" "}
                              foto #{p.id}
                            </Button>
                          ))}
                          <Upload
                            files={pending}
                            count={visiblePhotos.length}
                            onChange={(picked) => {
                              setFiles((prev) => ({ ...prev, [key]: picked }));
                              markDirty();
                            }}
                          />
                        </FieldGroup>
                      </AccordionContent>
                    </AccordionItem>
                  </Accordion>
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </Panel>
  );
}

export function InspectionPage() {
  const { route } = useWorkspace();
  const { data, error } = useData<Document>("inspection", {
    record: route.record,
  });
  return (
    <>
      <ErrorBox message={error} />
      {data ? (
        <InspectionEditor
          key={`${data.inspection.id}-${data.inspection.version}`}
          document={data}
        />
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
  const [performed, setPerformed] = useState(
    d.inspection.performed_date || config.today,
  );
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
  const version = useRef(Number(d.inspection.version));
  const available = groups.filter((group) =>
    results.some((r) => r.snapshot.group === group),
  );
  const review = step === available.length;
  const root = useRef<HTMLDivElement>(null);
  const uploadKeys = useRef(new WeakMap<File, string>());
  const update = (id: Result["id"], patch: Partial<Result>) => {
    setResults((rows) =>
      rows.map((r) => (String(r.id) === String(id) ? { ...r, ...patch } : r)),
    );
    w.dirty(true);
  };
  const jump = (id: string) => {
    const index = available.indexOf(
      results.find((r) => String(r.id) === id)?.snapshot.group || "",
    );
    setStep(Math.max(0, index));
    setTimeout(() => {
      const el = root.current?.querySelector<HTMLElement>(
        `[data-result="${id}"]`,
      );
      el?.scrollIntoView({ block: "center", behavior: "smooth" });
      el?.querySelector<HTMLElement>("button,input,textarea")?.focus();
    }, 50);
  };
  async function save(final: boolean) {
    if (busyRef.current) return;
    if (final) {
      const errors = inspectionErrors(results, performed, config.today);
      setErrors(errors);
      if (Object.keys(errors).length) {
        setError(
          "Lengkapi butir yang ditandai sebelum menyelesaikan pemeriksaan.",
        );
        const id = Object.keys(errors).find((x) => x !== "performed_date");
        if (id) jump(id);
        else setStep(available.length);
        return;
      }
    }
    busyRef.current = true;
    setBusy(true);
    setError("");
    setMessage("Menyimpan draf…");
    try {
      const answers = Object.fromEntries(
        results.map(
          ({ id, outcome, notes, assignee_id, priority, deadline }) => [
            id,
            { outcome, notes, assignee_id, priority, deadline },
          ],
        ),
      );
      const values = {
        watch_action: "inspection",
        id: d.inspection.id,
        performed_date: performed,
        notes,
        results: answers,
      };
      const draft = await w.mutate({
        ...values,
        version: version.current,
        submit_mode: "draft",
      });
      version.current = Number(draft.document!.version);
      for (const r of results) {
        const key = String(r.id);
        let removes = removed[key] || [];
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
          removes = [];
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
            setFiles((prev) => ({
              ...prev,
              [key]: (prev[key] || []).filter((f) => f !== file),
            }));
          } catch (e) {
            setErrors({
              [key]: `Foto ${file.name} belum tersimpan. Coba simpan kembali.`,
            });
            jump(key);
            throw e;
          }
        }
      }
      if (final) {
        setMessage("Menyelesaikan pemeriksaan…");
        await w.mutate({
          ...values,
          version: version.current,
          submit_mode: "final",
        });
        w.dirty(false);
        toast.success(
          "Pemeriksaan selesai. Temuan yang perlu tindakan sudah dibuat.",
        );
        w.refresh();
      } else {
        w.dirty(false);
        setMessage("Draf dan seluruh foto tersimpan.");
        toast.success("Draf tersimpan.");
      }
    } catch (e) {
      setError((e as Error).message);
      setMessage("Pemeriksaan belum diselesaikan. Periksa pesan di atas.");
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }
  return (
    <div ref={root} className="flex flex-col gap-6">
      <Heading
        back
        title={`Pemeriksaan · ${d.snapshot.room_name}`}
        description={`${d.snapshot.library_name} · ${d.snapshot.template_name} · ${dateLabel(d.inspection.due_date)}`}
        action={<Pdf record={d.inspection.id} />}
      />
      <div className="flex items-center gap-3">
        <Status value={d.inspection.status} />
        <span className="text-sm text-muted-foreground">
          {d.inspection.kind === "historical"
            ? "Impor riwayat"
            : d.inspection.kind === "routine"
              ? "Terjadwal"
              : "Insidental"}
          {d.inspection.reason && ` · ${d.inspection.reason}`}
        </span>
        {d.inspection.parent_id && (
          <Button
            variant="link"
            onClick={() =>
              w.go({ view: "inspection", record: d.inspection.parent_id! })
            }
          >
            Pemeriksaan asal
          </Button>
        )}
      </div>
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="results">Hasil pemeriksaan</TabsTrigger>
          <TabsTrigger value="history">Riwayat</TabsTrigger>
        </TabsList>
        <TabsContent value="history">
          <History events={d.events} />
        </TabsContent>
      </Tabs>
      <ErrorBox message={error} />
      {tab === "results" && (
        <>
          <fieldset disabled={busy} className="flex flex-col gap-6 min-w-0">
            {editable && (
              <>
                <div className="flex justify-between text-sm">
                  <span>
                    {results.filter((r) => r.outcome).length} dari{" "}
                    {results.length} butir terisi
                  </span>
                  <span>
                    Langkah {step + 1} / {available.length + 1}
                  </span>
                </div>
                <Progress
                  value={
                    (results.filter((r) => r.outcome).length / results.length) *
                    100
                  }
                  aria-label="Kelengkapan hasil"
                />
                <Tabs
                  value={String(step)}
                  onValueChange={(v) => setStep(Number(v))}
                >
                  <TabsList className="flex-wrap h-auto">
                    {[...available, "Ringkasan"].map((g, i) => (
                      <TabsTrigger value={String(i)} key={g}>
                        {g}
                      </TabsTrigger>
                    ))}
                  </TabsList>
                </Tabs>
              </>
            )}
            {editable && !review && (
              <InspectionResultsEditableTable
                results={results}
                options={options}
                photos={photos}
                files={files}
                removed={removed}
                errors={errors}
                update={update}
                setRemoved={setRemoved}
                setFiles={setFiles}
                markDirty={() => w.dirty(true)}
              />
            )}
            {!editable && (
              <InspectionResultsTable
                results={results}
                options={options}
                photos={photos}
              />
            )}
            {editable && review && (
              <Panel
                title="Ringkasan pemeriksaan"
                description="Periksa hasil sebelum menyelesaikan. Hasil yang selesai akan dikunci; koreksi disimpan sebagai catatan tambahan."
              >
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
                    label="Catatan pemeriksaan"
                    value={notes}
                    multiline
                    onChange={(v) => {
                      setNotes(v);
                      w.dirty(true);
                    }}
                  />
                  <div className="flex flex-col gap-2">
                    {results.map((r) => (
                      <Button
                        key={r.id}
                        variant="outline"
                        onClick={() => jump(String(r.id))}
                        className="justify-between h-auto py-3 whitespace-normal"
                      >
                        <span>{r.snapshot.object}</span>
                        <span>
                          {options.outcomes[r.outcome] || "Belum diisi"}
                        </span>
                      </Button>
                    ))}
                  </div>
                  <p className="text-sm text-muted-foreground">
                    {results.filter((r) => r.outcome === "action").length}{" "}
                    temuan akan dibuat untuk ditindaklanjuti.
                  </p>
                </FieldGroup>
              </Panel>
            )}
          </fieldset>
          {editable ? (
            <div className="sticky bottom-0 border-t bg-background py-4 flex flex-wrap items-center gap-3">
              <Button
                variant="outline"
                disabled={busy}
                onClick={() => save(false)}
              >
                Simpan draf
              </Button>
              {step > 0 && (
                <Button
                  variant="ghost"
                  disabled={busy}
                  onClick={() => setStep(step - 1)}
                >
                  Sebelumnya
                </Button>
              )}
              <Button
                disabled={busy}
                onClick={() => (review ? save(true) : setStep(step + 1))}
              >
                {busy
                  ? "Menyimpan…"
                  : review
                    ? "Selesaikan pemeriksaan"
                    : "Lanjutkan"}
                <ArrowRight data-icon="inline-end" />
              </Button>
              <p className="text-sm text-muted-foreground" role="status">
                {message}
              </p>
            </div>
          ) : (
            <>
              <Panel title="Tindak lanjut">
                {d.findings.length ? (
                  d.findings.map((f) => (
                    <div className="flex justify-between py-2" key={f.id}>
                      <Status value={f.status} />
                      <Button
                        variant="outline"
                        onClick={() => w.go({ view: "finding", record: f.id })}
                      >
                        Buka temuan #{f.id}
                      </Button>
                    </div>
                  ))
                ) : (
                  <p className="text-sm text-muted-foreground">
                    Tidak ada temuan yang perlu tindakan.
                  </p>
                )}
              </Panel>
              {config.write && (
                <Accordion type="single" collapsible>
                  <AccordionItem value="correction">
                    <AccordionTrigger>
                      Catatan koreksi dan pemeriksaan ulang
                    </AccordionTrigger>
                    <AccordionContent>
                      <FieldGroup>
                        <TextField
                          label="Catatan koreksi"
                          value={correction}
                          onChange={(v) => {
                            setCorrection(v);
                            w.dirty(true);
                          }}
                          multiline
                        />
                        <div className="flex gap-3">
                          <Button
                            disabled={busy || !correction.trim()}
                            onClick={async () => {
                              setBusy(true);
                              try {
                                await w.mutate({
                                  watch_action: "correction",
                                  id: d.inspection.id,
                                  notes: correction,
                                });
                                w.dirty(false);
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
                              variant="outline"
                              onClick={() =>
                                w.go({
                                  view: "new-inspection",
                                  parent_id: d.inspection.id,
                                  room: d.inspection.location_id!,
                                  template_id:
                                    d.snapshot.template_id ?? undefined,
                                })
                              }
                            >
                              Pemeriksaan ulang
                            </Button>
                          )}
                        </div>
                      </FieldGroup>
                    </AccordionContent>
                  </AccordionItem>
                </Accordion>
              )}
            </>
          )}
        </>
      )}
    </div>
  );
}
export function FindingPage() {
  const { route } = useWorkspace();
  const { data, error } = useData<Document>("finding", {
    record: route.record,
  });
  return (
    <>
      <ErrorBox message={error} />
      {data ? (
        <FindingEditor
          key={`${data.finding!.id}-${data.finding!.version}`}
          document={data}
        />
      ) : (
        !error && <Loading />
      )}
    </>
  );
}
function FindingEditor({ document: d }: { document: Document }) {
  const w = useWorkspace();
  const f = d.finding!;
  const result = d.results.find((r) => String(r.id) === String(f.result_id))!;
  const actions = d.actions.filter(
    (a) => String(a.finding_id) === String(f.id),
  );
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
      await w.mutate(
        {
          watch_action: "finding",
          id: f.id,
          version: f.version,
          mode,
          ...values,
          remove: removed,
        },
        body,
      );
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
  const workPhotos = d.photos.filter(
    (p) => draft && String(p.action_id) === String(draft.id),
  );
  return (
    <>
      <Heading
        back
        title={result.snapshot.object}
        description={`${d.snapshot.room_name} · ${f.assignee_name} · Tenggat ${dateLabel(f.deadline)}`}
        action={
          <Button
            variant="outline"
            onClick={() =>
              w.go({ view: "inspection", record: f.inspection_id })
            }
          >
            Pemeriksaan asal
          </Button>
        }
      />
      <div className="flex gap-3">
        <Status value={f.status} />
        <Badge variant="outline">{w.options.priorities[f.priority]}</Badge>
      </div>
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="work">Pekerjaan</TabsTrigger>
          <TabsTrigger value="history">Riwayat</TabsTrigger>
        </TabsList>
        <TabsContent value="history">
          <History
            events={d.events.filter(
              (e) => String(e.finding_id) === String(f.id),
            )}
          />
        </TabsContent>
      </Tabs>
      <ErrorBox message={error} />
      {tab === "work" && (
        <>
          <Panel title="Temuan awal">
            <p className="whitespace-pre-wrap mb-4">{result.notes}</p>
            <Photos
              photos={d.photos.filter(
                (p) => String(p.result_id) === String(f.result_id),
              )}
            />
          </Panel>
          {actions
            .filter((a) => a.submitted_at)
            .map((a) => (
              <Panel
                key={a.id}
                title={`${a.kind === "none" ? "Tanpa pekerjaan" : a.kind === "repair" ? "Perbaikan" : "Pemeliharaan"} · ${dateLabel(a.performed_date)}`}
                description={`${a.actor_name}${a.cost ? ` · ${money(a.cost)}` : ""}`}
              >
                <p className="whitespace-pre-wrap mb-4">{a.description}</p>
                <Photos
                  photos={d.photos.filter(
                    (p) => String(p.action_id) === String(a.id),
                  )}
                />
              </Panel>
            ))}
          {w.config.write && ["open", "working"].includes(f.status) ? (
            <form
              onSubmit={(e) => {
                e.preventDefault();
                save("submit");
              }}
            >
              <fieldset disabled={busy} className="flex flex-col gap-6 min-w-0">
                <Panel
                  title="Catat pekerjaan"
                  description="Isi tindakan dan bukti, lalu ajukan hasil untuk diverifikasi."
                >
                  <FieldGroup>
                    <Choice
                      label="Jenis tindakan"
                      value={values.kind}
                      onChange={(v) => update("kind", v)}
                      items={entries({
                        repair: "Perbaikan",
                        maintenance: "Pemeliharaan",
                        none: "Tanpa pekerjaan",
                      })}
                    />
                    <TextField
                      label={
                        values.kind === "none"
                          ? "Alasan tanpa pekerjaan"
                          : "Uraian pekerjaan"
                      }
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
                        label="Biaya (opsional, Rp)"
                        type="number"
                        min="0"
                        value={values.cost}
                        onChange={(v) => update("cost", v)}
                      />
                    </FieldGroup>
                    <Photos
                      photos={workPhotos.filter(
                        (p) => !removed.includes(String(p.id)),
                      )}
                    />
                    {workPhotos.map((p) => (
                      <Button
                        type="button"
                        variant="ghost"
                        key={p.id}
                        onClick={() => {
                          setRemoved((prev) =>
                            prev.includes(String(p.id))
                              ? prev.filter((id) => id !== String(p.id))
                              : [...prev, String(p.id)],
                          );
                          w.dirty(true);
                        }}
                      >
                        {removed.includes(String(p.id))
                          ? "Batalkan hapus"
                          : "Hapus"}{" "}
                        foto #{p.id}
                      </Button>
                    ))}
                    <Upload
                      files={files}
                      onChange={(f) => {
                        setFiles(f);
                        w.dirty(true);
                      }}
                      count={workPhotos.length - removed.length}
                    />
                    <p className="text-sm text-muted-foreground">
                      {values.kind === "none"
                        ? "Alasan wajib diisi; foto opsional."
                        : "Minimal satu foto hasil diperlukan untuk pengajuan."}
                    </p>
                  </FieldGroup>
                </Panel>
                <div className="flex gap-3">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => save("draft")}
                  >
                    Simpan draf
                  </Button>
                  <Button type="submit">
                    {busy ? "Menyimpan…" : "Ajukan verifikasi"}
                    <ArrowRight data-icon="inline-end" />
                  </Button>
                </div>
              </fieldset>
            </form>
          ) : f.status === "review" && w.config.write ? (
            <Panel title="Verifikasi hasil">
              <fieldset disabled={busy}>
                <FieldGroup>
                  <TextField
                    label="Catatan verifikasi / alasan pengembalian"
                    multiline
                    required
                    value={values.notes}
                    onChange={(v) => update("notes", v)}
                  />
                  <div className="flex gap-3">
                    <Button
                      disabled={!values.notes.trim()}
                      onClick={() => save("verify")}
                    >
                      <Check data-icon="inline-start" />
                      Terima hasil
                    </Button>
                    <Button
                      variant="outline"
                      disabled={!values.notes.trim()}
                      onClick={() => save("reject")}
                    >
                      Kembalikan untuk perbaikan
                    </Button>
                  </div>
                </FieldGroup>
              </fieldset>
            </Panel>
          ) : (
            draft && (
              <Panel title="Draf pekerjaan">
                <p>{draft.description}</p>
                <Photos photos={workPhotos} />
              </Panel>
            )
          )}
        </>
      )}
    </>
  );
}
