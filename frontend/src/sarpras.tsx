import { ArrowRight, CheckCircle2, CircleDashed, Info, XCircle } from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Alert, AlertDescription, AlertTitle } from "./components/ui/alert";
import { Card, CardContent, CardHeader, CardTitle, CardAction } from "./components/ui/card";
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from "./components/ui/accordion";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "./components/ui/table";
import { useWorkspace } from "./context";
import { dateLabel, url } from "./api";
import { ErrorBox, Loading, PageHeader, Pdf, StatCard } from "./shared";
import { usePage } from "./settings";
import type { Route } from "./types";

type Level = "a" | "b" | "c" | "d";
type Source = "inventory" | "facility" | "software" | "schedules";
type Aspect = {
  no: number;
  section: string;
  title: string;
  value: string;
  level: Level | null;
  basis: string;
  checks: { label: string; ok: boolean }[];
  rows: string[][];
  columns: string[];
  fix: string;
  sources: Source[];
};
type Data = {
  recap: {
    generated_at: string;
    aspects: Aspect[];
    summary: Record<Level | "empty", number>;
    counts: { rooms: number; items: number; uncategorized: number; unclassified_rooms: number; no_area: number };
  };
  levels: Record<Level, string>;
};

/** Where the data behind an aspect is entered; the recap itself changes nothing. */
const sources: Record<Source, { label: string; route: Route }> = {
  inventory: { label: "Ruangan & Barang", route: { view: "inventory" } },
  facility: { label: "Gedung & Jaringan", route: { view: "facility" } },
  software: { label: "Perangkat Lunak", route: { view: "software" } },
  schedules: { label: "Jadwal", route: { view: "schedules" } },
};

const tone: Record<Level, "success" | "info" | "warning" | "destructive"> = {
  a: "success",
  b: "info",
  c: "warning",
  d: "destructive",
};

function LevelBadge({ level, levels }: { level: Level | null; levels: Record<Level, string> }) {
  return level ? (
    <Badge variant={tone[level]}>{levels[level]}</Badge>
  ) : (
    <Badge variant="outline">
      <CircleDashed data-icon="inline-start" />
      Belum ada data
    </Badge>
  );
}

function AspectCard({ aspect: x, levels }: { aspect: Aspect; levels: Record<Level, string> }) {
  const w = useWorkspace();
  return (
    <Card>
      <CardHeader>
        <CardTitle className="leading-snug">{x.title}</CardTitle>
        <CardAction>
          <LevelBadge level={x.level} levels={levels} />
        </CardAction>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <p className="text-xl font-semibold tracking-tight">{x.value}</p>
        <p className="text-sm text-muted-foreground">{x.basis}</p>
        <ul className="flex flex-col gap-1.5">
          {x.checks.map((c) => (
            <li key={c.label} className="flex items-start gap-2 text-sm">
              {c.ok ? (
                <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" />
              ) : (
                <XCircle className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
              )}
              <span className={c.ok ? undefined : "text-muted-foreground"}>{c.label}</span>
            </li>
          ))}
        </ul>
        {x.level !== "a" && (
          <div className="flex items-start gap-2 rounded-lg bg-muted/50 p-2.5 text-sm">
            <Info className="mt-0.5 size-4 shrink-0 text-info" />
            <div className="flex min-w-0 flex-col items-start gap-2">
              <p>{x.fix}</p>
              <div className="flex flex-wrap gap-2">
                {x.sources.map((source) => (
                  <Button key={source} size="sm" variant="outline" onClick={() => w.go(sources[source].route)}>
                    Buka {sources[source].label}
                    <ArrowRight data-icon="inline-end" />
                  </Button>
                ))}
              </div>
            </div>
          </div>
        )}
        {x.rows.length > 0 && (
          <Accordion type="single" collapsible>
            <AccordionItem value="rows" className="border-b-0">
              <AccordionTrigger className="py-1 text-sm">Rincian ({x.rows.length})</AccordionTrigger>
              <AccordionContent>
                <div className="max-h-72 overflow-y-auto rounded-lg border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        {x.columns.map((c, i) => (
                          <TableHead key={c} className={i ? "text-right" : undefined}>
                            {c}
                          </TableHead>
                        ))}
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {x.rows.map((r, n) => (
                        <TableRow key={n}>
                          {r.map((cell, i) => (
                            <TableCell key={i} className={i ? "text-right whitespace-normal" : "whitespace-normal"}>
                              {cell}
                            </TableCell>
                          ))}
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </AccordionContent>
            </AccordionItem>
          </Accordion>
        )}
      </CardContent>
    </Card>
  );
}

function Recap({ data }: { data: Data }) {
  const w = useWorkspace();
  const { summary, counts, aspects } = data.recap;
  const gaps = [
    counts.no_area > 0 && `${counts.no_area} ruangan belum diisi luasnya`,
    counts.unclassified_rooms > 0 && `${counts.unclassified_rooms} ruangan belum diisi fungsinya`,
    counts.uncategorized > 0 && `${counts.uncategorized} dari ${counts.items} barang belum berkategori`,
  ].filter(Boolean) as string[];
  const sections = aspects.reduce<Record<string, Aspect[]>>((all, x) => ((all[x.section] ||= []).push(x), all), {});
  return (
    <>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
        <StatCard label={data.levels.a} value={summary.a} tone="success" />
        <StatCard label={data.levels.b} value={summary.b} tone="info" />
        <StatCard label={data.levels.c} value={summary.c} tone="warning" />
        <StatCard label={data.levels.d} value={summary.d} tone="destructive" />
        <StatCard label="Belum ada data" value={summary.empty} />
      </div>
      {gaps.length > 0 && (
        <Alert>
          <Info />
          <AlertTitle>Lengkapi data agar rekap akurat</AlertTitle>
          <AlertDescription>
            <ul className="list-disc pl-4">
              {gaps.map((g) => (
                <li key={g}>{g}</li>
              ))}
            </ul>
            <Button size="sm" variant="outline" className="mt-2" onClick={() => w.go({ view: "inventory" })}>
              Buka Ruangan & Barang
            </Button>
          </AlertDescription>
        </Alert>
      )}
      {Object.entries(sections).map(([section, list]) => (
        <section key={section} className="flex flex-col gap-3">
          <h2 className="text-lg font-semibold tracking-tight">{section}</h2>
          <div className="grid gap-4 lg:grid-cols-2">
            {list.map((x) => (
              <AspectCard key={x.no} aspect={x} levels={data.levels} />
            ))}
          </div>
        </section>
      ))}
    </>
  );
}

export function SarprasPage() {
  const w = useWorkspace();
  const page = w.config.pages!.sarpras;
  const { data, error } = usePage<Data>(page);
  return (
    <>
      <PageHeader
        title="Rekap Sarpras"
        description="Kondisi sarana dan prasarana perpustakaan, dihitung dari data ruangan, barang, perangkat lunak, jaringan, serta pengawasan dan pemeliharaan."
        meta={data && <span className="text-xs text-muted-foreground">Dihitung {dateLabel(data.recap.generated_at)}</span>}
        actions={<Pdf label="Cetak rekap" href={(style) => url(page, { pdf: style })} />}
      />
      <ErrorBox message={error} />
      {!data ? !error && <Loading /> : <Recap data={data} />}
    </>
  );
}
