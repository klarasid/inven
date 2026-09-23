import { useState } from "react";
import { Button } from "./components/ui/button";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "./components/ui/tabs";
import { Card, CardHeader, CardTitle, CardContent } from "./components/ui/card";
import {
  Table,
  TableHeader,
  TableRow,
  TableHead,
  TableBody,
  TableCell,
} from "./components/ui/table";
import { FieldGroup } from "./components/ui/field";
import { useWorkspace, useData } from "./context";
import {
  Heading,
  Pdf,
  Filters,
  TextField,
  Panel,
  Loading,
  ErrorBox,
  Pager,
  Status,
  Blank,
} from "./shared";
import { dateLabel } from "./api";
import type { Summary, Page, TaskRow } from "./types";
export function Reports() {
  const w = useWorkspace();
  const from = w.route.from || w.config.today.slice(0, 8) + "01";
  const to = w.route.to || w.config.today;
  const [dates, setDates] = useState({ from, to });
  const [tab, setTab] = useState("summary");
  const { data, error, loading } = useData<
    Page<TaskRow> & { summary: Summary }
  >("reports", { ...w.route, from, to });
  return (
    <>
      <Heading
        title="Laporan"
        description="Tinjau pemeriksaan dan tindak lanjut dalam periode yang dipilih."
        action={<Pdf period={{ ...w.route, from, to }} />}
      />
      <form
        className="flex items-end flex-wrap gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          w.go({ ...w.route, ...dates, page: 1 }, true);
        }}
      >
        <FieldGroup className="grid grid-cols-2 max-w-lg">
          <TextField
            label="Dari tanggal"
            type="date"
            value={dates.from}
            onChange={(from) => setDates({ ...dates, from })}
          />
          <TextField
            label="Sampai tanggal"
            type="date"
            value={dates.to}
            onChange={(to) => setDates({ ...dates, to })}
          />
        </FieldGroup>
        <Button variant="outline" type="submit">
          Terapkan
        </Button>
        <Filters />
      </form>
      <ErrorBox message={error} />
      {loading ? (
        <Loading />
      ) : (
        data && (
          <>
            <Tabs value={tab} onValueChange={setTab}>
              <TabsList>
                <TabsTrigger value="summary">Ringkasan</TabsTrigger>
                <TabsTrigger value="coverage">Cakupan</TabsTrigger>
                <TabsTrigger value="history">Riwayat pemeriksaan</TabsTrigger>
              </TabsList>
              <TabsContent value="summary" className="pt-4">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                  {Object.entries({
                    "Pemeriksaan selesai": data.summary.counts.finalized,
                    "Pemeriksaan terlambat":
                      Number(data.summary.counts.late) +
                      data.summary.unformed_late,
                    "Temuan belum selesai": data.summary.findings.open,
                    "Selesai terverifikasi": data.summary.findings.closed,
                  }).map(([label, n]) => (
                    <Card key={label}>
                      <CardHeader>
                        <CardTitle>{label}</CardTitle>
                      </CardHeader>
                      <CardContent>
                        <p className="text-3xl font-semibold tabular-nums">
                          {n}
                        </p>
                      </CardContent>
                    </Card>
                  ))}
                </div>
                <p className="mt-4 text-sm text-muted-foreground">
                  Temuan mengikuti periode pemeriksaan asal; status tindak
                  lanjut adalah status saat ini.
                </p>
              </TabsContent>
              <TabsContent value="coverage" className="pt-4">
                <Panel title="Cakupan pemeriksaan rutin">
                  <dl className="grid gap-6 sm:grid-cols-2">
                    {Object.entries({
                      "Ruangan diperiksa": `${data.summary.room_examined} / ${data.summary.room_total}`,
                      "Butir diperiksa": `${data.summary.item_examined} / ${data.summary.item_applicable}`,
                      "Butir tidak berlaku": data.summary.item_na,
                      "Rencana rutin": data.summary.planned,
                      "Belum dibentuk": data.summary.unformed,
                      "Pemeriksaan insidental": data.summary.counts.incidental,
                    }).map(([label, n]) => (
                      <div key={label}>
                        <dt className="text-sm text-muted-foreground">
                          {label}
                        </dt>
                        <dd className="text-xl">{n}</dd>
                      </div>
                    ))}
                  </dl>
                  <p className="my-5 text-sm text-muted-foreground">
                    Hanya hasil final Baik / Perlu tindakan dihitung diperiksa.
                    Tidak diperiksa tetap masuk penyebut; Tidak berlaku
                    dikeluarkan. Insidental dilaporkan terpisah.
                  </p>
                  <h3 className="font-medium mb-3">Ruangan tanpa jadwal</h3>
                  {data.summary.missing_rooms.length ? (
                    data.summary.missing_rooms.map((r, i) => (
                      <p key={i}>
                        {r.room_name} · {r.location_name}
                      </p>
                    ))
                  ) : (
                    <p className="text-sm text-muted-foreground">
                      Semua ruangan memiliki jadwal dalam periode ini.
                    </p>
                  )}
                </Panel>
              </TabsContent>
              <TabsContent value="history" className="pt-4">
                {!data.rows.length ? (
                  <Blank description="Belum ada pemeriksaan dalam periode ini." />
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Ruangan</TableHead>
                        <TableHead>Jadwal</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Tindakan</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {data.rows.map((r) => (
                        <TableRow key={r.id}>
                          <TableCell>{r.snapshot.room_name}</TableCell>
                          <TableCell>{dateLabel(r.due_date)}</TableCell>
                          <TableCell>
                            <Status value={r.status} />
                          </TableCell>
                          <TableCell>
                            <Button
                              variant="outline"
                              onClick={() =>
                                w.go({ view: "inspection", record: r.id })
                              }
                            >
                              Detail
                            </Button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
                <Pager
                  {...data}
                  onChange={(page) =>
                    w.go({ ...w.route, page, from, to }, true)
                  }
                />
              </TabsContent>
            </Tabs>
          </>
        )
      )}
    </>
  );
}
