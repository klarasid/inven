import { useState } from "react";
import { ClipboardCheck, Clock, Wrench, ShieldCheck, TriangleAlert, ArrowRight } from "lucide-react";
import { Button } from "./components/ui/button";
import { Progress } from "./components/ui/progress";
import { Input } from "./components/ui/input";
import { Tabs, TabsList, TabsTrigger } from "./components/ui/tabs";
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from "./components/ui/table";
import { useWorkspace, useData } from "./context";
import { PageHeader, Pdf, RoomFilter, Panel, Loading, ErrorBox, Pager, Status, Blank, StatCard } from "./shared";
import { dateLabel } from "./api";
import type { Summary, Page, TaskRow } from "./types";

function Coverage({ label, value, total, hint }: { label: string; value: number; total: number; hint?: string }) {
  const percent = total ? Math.round((value / total) * 100) : 0;
  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-baseline justify-between gap-2">
        <span className="text-sm">{label}</span>
        <span className="text-sm tabular-nums">
          <strong className="text-base">{percent}%</strong>{" "}
          <span className="text-muted-foreground">
            ({value} / {total})
          </span>
        </span>
      </div>
      <Progress value={percent} aria-label={label} />
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

export function Reports() {
  const w = useWorkspace();
  const from = String(w.route.from || w.config.today.slice(0, 8) + "01");
  const to = String(w.route.to || w.config.today);
  const [tab, setTab] = useState("summary");
  const { data, error, loading } = useData<Page<TaskRow> & { summary: Summary }>("reports", { ...w.route, from, to });
  const setPeriod = (patch: { from?: string; to?: string }) => {
    const next = { from, to, ...patch };
    if (next.from && next.to && next.from <= next.to) w.go({ ...w.route, ...next, page: 1 }, true);
  };
  const presets = (() => {
    const today = new Date(w.config.today + "T12:00:00");
    const iso = (d: Date) =>
      `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    const y = today.getFullYear();
    const m = today.getMonth();
    return [
      { label: "Bulan ini", from: iso(new Date(y, m, 1)), to: w.config.today },
      { label: "Bulan lalu", from: iso(new Date(y, m - 1, 1)), to: iso(new Date(y, m, 0)) },
      { label: "Tahun ini", from: `${y}-01-01`, to: w.config.today },
      { label: "Tahun lalu", from: `${y - 1}-01-01`, to: `${y - 1}-12-31` },
    ];
  })();
  const s = data?.summary;
  return (
    <>
      <PageHeader
        title="Laporan"
        description="Capaian pemeriksaan dan tindak lanjut dalam periode yang dipilih."
        actions={<Pdf period={{ ...w.route, from, to }} label="Cetak laporan PDF" />}
      />
      <div className="flex flex-col gap-3 rounded-xl border p-3 sm:flex-row sm:flex-wrap sm:items-center">
        <div className="flex items-center gap-2">
          <Input type="date" aria-label="Dari tanggal" className="w-auto" value={from} max={to} onChange={(e) => setPeriod({ from: e.target.value })} />
          <span className="text-muted-foreground">–</span>
          <Input type="date" aria-label="Sampai tanggal" className="w-auto" value={to} min={from} onChange={(e) => setPeriod({ to: e.target.value })} />
        </div>
        <div className="flex flex-wrap gap-1">
          {presets.map((p) => (
            <Button
              key={p.label}
              size="sm"
              variant={p.from === from && p.to === to ? "secondary" : "ghost"}
              onClick={() => w.go({ ...w.route, from: p.from, to: p.to, page: 1 }, true)}
            >
              {p.label}
            </Button>
          ))}
        </div>
        <div className="sm:ml-auto">
          <RoomFilter />
        </div>
      </div>
      <ErrorBox message={error} />
      {loading && !data ? (
        <Loading />
      ) : (
        s &&
        data && (
          <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <StatCard label="Pemeriksaan selesai" value={s.counts.finalized} icon={ClipboardCheck} tone="success" hint={`${s.counts.incidental} insidental · ${s.counts.historical} impor`} />
              <StatCard
                label="Pemeriksaan terlambat"
                value={Number(s.counts.late) + s.unformed_late}
                icon={Clock}
                tone={Number(s.counts.late) + s.unformed_late ? "destructive" : "default"}
              />
              <StatCard
                label="Temuan belum selesai"
                value={s.findings.open}
                icon={Wrench}
                tone={s.findings.open ? "warning" : "default"}
                hint={s.findings.late ? `${s.findings.late} lewat tenggat` : undefined}
              />
              <StatCard label="Temuan terverifikasi" value={s.findings.closed} icon={ShieldCheck} tone="success" />
            </div>
            <Tabs value={tab} onValueChange={setTab}>
              <TabsList variant="line" className="w-full justify-start border-b">
                <TabsTrigger value="summary" className="flex-none">
                  Cakupan
                </TabsTrigger>
                <TabsTrigger value="history" className="flex-none">
                  Daftar pemeriksaan ({data.total})
                </TabsTrigger>
              </TabsList>
            </Tabs>
            {tab === "summary" && (
              <div className="grid gap-6 lg:grid-cols-2">
                <Panel title="Cakupan pemeriksaan rutin" description="Hanya hasil final Baik / Perlu tindakan dihitung diperiksa.">
                  <div className="flex flex-col gap-5">
                    <Coverage label="Ruangan diperiksa" value={s.room_examined} total={s.room_total} />
                    <Coverage
                      label="Butir diperiksa"
                      value={s.item_examined}
                      total={s.item_applicable}
                      hint={`${s.item_na} butir tidak berlaku dikeluarkan dari perhitungan.`}
                    />
                    <dl className="grid grid-cols-3 gap-3 border-t pt-4">
                      {[
                        ["Rencana rutin", s.planned],
                        ["Belum dibentuk", s.unformed],
                        ["Insidental", s.counts.incidental],
                      ].map(([label, n]) => (
                        <div key={label}>
                          <dt className="text-xs text-muted-foreground">{label}</dt>
                          <dd className="text-lg font-semibold tabular-nums">{n}</dd>
                        </div>
                      ))}
                    </dl>
                  </div>
                </Panel>
                <Panel
                  title="Ruangan tanpa jadwal"
                  description="Ruangan yang tidak memiliki pemeriksaan rutin dalam periode ini."
                  action={
                    s.missing_rooms.length > 0 &&
                    w.config.write && (
                      <Button size="sm" variant="outline" onClick={() => w.go({ view: "schedule-edit" })}>
                        Buat jadwal
                      </Button>
                    )
                  }
                >
                  {s.missing_rooms.length ? (
                    <ul className="flex max-h-72 flex-col divide-y overflow-y-auto">
                      {s.missing_rooms.map((r, i) => (
                        <li key={i} className="flex items-center justify-between gap-2 py-2 text-sm">
                          <span className="flex items-center gap-2">
                            <TriangleAlert className="size-4 text-warning" />
                            {r.room_name}
                          </span>
                          <span className="text-xs text-muted-foreground">{r.location_name}</span>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-sm text-muted-foreground">Semua ruangan memiliki jadwal dalam periode ini.</p>
                  )}
                </Panel>
              </div>
            )}
            {tab === "history" &&
              (!data.rows.length ? (
                <Blank icon={ClipboardCheck} description="Belum ada pemeriksaan dalam periode ini." />
              ) : (
                <>
                  <div className="overflow-hidden rounded-xl border">
                    <Table>
                      <TableHeader className="bg-muted/50">
                        <TableRow>
                          <TableHead>Ruangan</TableHead>
                          <TableHead className="hidden sm:table-cell">Petugas</TableHead>
                          <TableHead>Jadwal</TableHead>
                          <TableHead>Status</TableHead>
                          <TableHead className="w-12">
                            <span className="sr-only">Tindakan</span>
                          </TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {data.rows.map((r) => (
                          <TableRow key={r.id} className="cursor-pointer" onClick={() => w.go({ view: "inspection", record: r.id })}>
                            <TableCell className="max-w-64 whitespace-normal">
                              <p className="font-medium">{r.snapshot.room_name}</p>
                              <p className="text-xs text-muted-foreground">{r.snapshot.library_name}</p>
                            </TableCell>
                            <TableCell className="hidden text-muted-foreground sm:table-cell">{r.snapshot.assignee?.name}</TableCell>
                            <TableCell className="tabular-nums">{dateLabel(r.due_date)}</TableCell>
                            <TableCell>
                              <Status value={r.status} />
                            </TableCell>
                            <TableCell>
                              <ArrowRight className="size-4 text-muted-foreground" />
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                  <Pager {...data} onChange={(page) => w.go({ ...w.route, page, from, to }, true)} />
                </>
              ))}
          </>
        )
      )}
    </>
  );
}
