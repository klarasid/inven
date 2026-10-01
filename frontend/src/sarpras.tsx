import { useId, useState } from "react";
import {
  ArrowRight,
  Building2,
  CheckCircle2,
  ChevronRight,
  CircleAlert,
  CircleDashed,
  Info,
  XCircle,
  type LucideIcon,
} from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Alert, AlertDescription, AlertTitle } from "./components/ui/alert";
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from "./components/ui/accordion";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "./components/ui/table";
import { ToggleGroup, ToggleGroupItem } from "./components/ui/toggle-group";
import { cn } from "./lib/utils";
import { useWorkspace } from "./context";
import { dateLabel, url } from "./api";
import { Blank, ErrorBox, Loading, LocationSelect, PageHeader, Pdf } from "./shared";
import { usePage } from "./settings";
import type { Route } from "./types";

export type Level = "a" | "b" | "c" | "d";
type Source = "inventory" | "facility" | "software" | "schedules";
type Aspect = {
  no: number;
  section: string;
  name: string;
  value: string;
  level: Level | null;
  basis: string;
  checks: { label: string; ok: boolean }[];
  rows: string[][];
  columns: string[];
  fix: string;
  sources: Source[];
  /** In the institution's recap: the locations to look at for this aspect. */
  targets?: { code: string; name: string }[];
};
type Recap = {
  generated_at: string;
  aspects: Aspect[];
  /** Data still missing from the rooms and items; a location's recap only. */
  counts?: { rooms: number; items: number; uncategorized: number; unclassified_rooms: number; no_area: number };
};
type Location = { code: string; name: string; rooms: number };
type Compared = Location & { summary: Record<Level | "empty", number> };
type Data = { levels: Record<Level, string>; unassigned: number } & (
  | { mode: "empty"; locations: Location[] }
  | { mode: "overview"; recap: Recap; locations: Compared[] }
  | { mode: "location"; recap: Recap; locations: Location[]; location: Location | null }
);

/** Where the data behind an aspect is entered; the recap itself changes nothing. */
const sources: Record<Source, { label: string; route: Route }> = {
  inventory: { label: "Ruangan & Barang", route: { view: "inventory" } },
  facility: { label: "Gedung & Jaringan", route: { view: "facility" } },
  software: { label: "Perangkat Lunak", route: { view: "software" } },
  schedules: { label: "Jadwal", route: { view: "schedules" } },
};

/** What an aspect asks of the reader: nothing, a look, or the data it is computed from. */
type State = "attention" | "empty" | "good";
const stateOf = (level: Level | null): State => (level === null ? "empty" : level === "a" || level === "b" ? "good" : "attention");
const states: Record<State, { label: string; icon: LucideIcon; ink: string; tint: string }> = {
  attention: { label: "Perlu perhatian", icon: CircleAlert, ink: "text-warning", tint: "bg-warning/10" },
  empty: { label: "Belum ada data", icon: CircleDashed, ink: "text-muted-foreground", tint: "bg-muted" },
  good: { label: "Sudah baik", icon: CheckCircle2, ink: "text-success", tint: "bg-success/10" },
};

const tone: Record<Level, "success" | "info" | "warning" | "destructive"> = {
  a: "success",
  b: "info",
  c: "warning",
  d: "destructive",
};

export function LevelBadge({ level, levels }: { level: Level | null; levels: Record<Level, string> }) {
  return level ? <Badge variant={tone[level]}>{levels[level]}</Badge> : <Badge variant="outline">Belum ada data</Badge>;
}

/** How many of `total` aspects are fine: one hue on a lighter track of the same hue. */
function GoodMeter({ good, total, label, labelledBy }: { good: number; total: number; label?: string; labelledBy?: string }) {
  return (
    <div
      role="meter"
      aria-label={label}
      aria-labelledby={labelledBy}
      aria-valuemin={0}
      aria-valuemax={total}
      aria-valuenow={good}
      aria-valuetext={`${good} dari ${total} aspek`}
      className="h-2 overflow-hidden rounded-full bg-success/15"
    >
      <div className="h-full rounded-full bg-success transition-all" style={{ width: `${total ? (good / total) * 100 : 0}%` }} />
    </div>
  );
}

/** The page's one headline: how many aspects are fine, and what to do about the rest. */
function Summary({ counts, total, places }: { counts: Record<State, number>; total: number; places?: number }) {
  const label = useId();
  const state: State = counts.attention ? "attention" : counts.empty ? "empty" : "good";
  const { icon: Icon, ink, tint } = states[state];
  const headline = {
    attention: `${counts.attention} aspek perlu perhatian`,
    empty: `${counts.empty} aspek belum memiliki data`,
    good: "Semua aspek sudah baik",
  }[state];
  const open = places ? "Buka tiap aspek untuk melihat kondisi tiap lokasi." : "Buka tiap aspek untuk melihat penyebabnya dan cara memperbaikinya.";
  const text = {
    attention: open + (counts.empty ? ` ${counts.empty} aspek lainnya belum memiliki data.` : ""),
    empty: places
      ? "Aspek tanpa data belum bisa dinilai. Buka tiap aspek untuk melihat lokasi yang perlu melengkapi datanya."
      : "Aspek tanpa data belum bisa dinilai. Buka tiap aspek untuk melihat data yang perlu dilengkapi.",
    good: "Pertahankan dengan memeriksa ruangan secara rutin dan memperbarui data setiap ada perubahan.",
  }[state];
  return (
    <section className="flex flex-col gap-5 rounded-2xl border bg-card p-5 md:p-6">
      <div className="flex items-start gap-4">
        <span className={cn("flex size-12 shrink-0 items-center justify-center rounded-full", tint, ink)}>
          <Icon className="size-6" />
        </span>
        <div className="flex min-w-0 flex-col gap-1">
          <h2 className="text-xl font-semibold tracking-tight">{headline}</h2>
          <p className="text-sm text-muted-foreground">
            {places ? `Kondisi gabungan ${places} lokasi perpustakaan. ` : ""}
            {text}
          </p>
        </div>
      </div>
      <div className="flex flex-col gap-2">
        <div className="flex items-baseline justify-between gap-2">
          <span id={label} className="text-sm">
            Aspek yang sudah baik
          </span>
          <span className="text-sm tabular-nums">
            <strong className="text-base">{counts.good}</strong> <span className="text-muted-foreground">dari {total}</span>
          </span>
        </div>
        <GoodMeter good={counts.good} total={total} labelledBy={label} />
      </div>
    </section>
  );
}

/** One row per location, each opening that location's recap. */
function Comparison({ locations, open }: { locations: Compared[]; open: (code: string) => void }) {
  const count = (state: State, n: number) => {
    const { icon: Icon, ink } = states[state];
    return (
      <span className="inline-flex items-center gap-1.5 tabular-nums">
        <Icon className={cn("size-4", ink)} aria-hidden />
        {n}
      </span>
    );
  };
  return (
    <section className="flex flex-col gap-2">
      <div className="flex flex-col gap-0.5">
        <h2 className="text-lg font-semibold tracking-tight">Perbandingan lokasi</h2>
        <p className="text-sm text-muted-foreground">Jumlah aspek menurut kondisinya di tiap lokasi. Pilih lokasi untuk membuka rekapnya.</p>
      </div>
      <div className="overflow-hidden rounded-xl border">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead>Lokasi</TableHead>
              <TableHead className="text-right">{states.good.label}</TableHead>
              <TableHead className="text-right">{states.attention.label}</TableHead>
              <TableHead className="text-right">{states.empty.label}</TableHead>
              <TableHead className="hidden w-40 md:table-cell">
                <span className="sr-only">Aspek yang sudah baik</span>
              </TableHead>
              <TableHead className="w-10">
                <span className="sr-only">Buka</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {locations.map((x) => {
              const good = x.summary.a + x.summary.b;
              const attention = x.summary.c + x.summary.d;
              const total = good + attention + x.summary.empty;
              return (
                <TableRow key={x.code} className="cursor-pointer" onClick={() => open(x.code)}>
                  <TableCell className="whitespace-normal">
                    <button type="button" className="cursor-pointer rounded-sm text-left font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring">
                      {x.name}
                    </button>
                    <p className="text-xs text-muted-foreground">{x.rooms} ruangan</p>
                  </TableCell>
                  <TableCell className="text-right">{count("good", good)}</TableCell>
                  <TableCell className="text-right">{count("attention", attention)}</TableCell>
                  <TableCell className="text-right">{count("empty", x.summary.empty)}</TableCell>
                  <TableCell className="hidden md:table-cell">
                    <GoodMeter good={good} total={total} label={`Aspek yang sudah baik di ${x.name}`} />
                  </TableCell>
                  <TableCell>
                    <ChevronRight className="size-4 text-muted-foreground" aria-hidden />
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </div>
    </section>
  );
}

function AspectRow({ aspect: x, levels, library }: { aspect: Aspect; levels: Record<Level, string>; library: string }) {
  const w = useWorkspace();
  const state = states[stateOf(x.level)];
  const Icon = state.icon;
  return (
    <AccordionItem value={String(x.no)}>
      <AccordionTrigger className="items-center gap-3 rounded-none px-4 py-3 hover:bg-muted/50 hover:no-underline">
        <span
          className={cn(
            "flex size-8 shrink-0 items-center justify-center rounded-full",
            x.level === "d" ? "bg-destructive/10 text-destructive" : cn(state.tint, state.ink),
          )}
        >
          <Icon className="size-4" />
        </span>
        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
          <span>{x.name}</span>
          <span className="font-normal text-muted-foreground">{x.value}</span>
        </span>
        <LevelBadge level={x.level} levels={levels} />
      </AccordionTrigger>
      <AccordionContent className="flex flex-col gap-4 px-4 pb-4 sm:pl-15">
        <div className="text-muted-foreground">{x.basis}</div>
        {x.checks.length > 0 && (
          <div className="flex flex-col gap-2">
            <h3 className="text-xs font-medium text-muted-foreground">Yang dinilai</h3>
            <ul className="flex flex-col gap-1.5">
              {x.checks.map((c) => (
                <li key={c.label} className="flex items-start gap-2">
                  {c.ok ? (
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-label="Terpenuhi" />
                  ) : (
                    <XCircle className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-label="Belum terpenuhi" />
                  )}
                  <span className={c.ok ? undefined : "text-muted-foreground"}>{c.label}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
        {x.level !== "a" && (
          <div className="flex flex-col items-start gap-2 rounded-xl bg-muted/50 p-3">
            <h3 className="text-xs font-medium text-muted-foreground">
              {x.level === null ? "Cara melengkapi data" : "Cara meningkatkan"}
            </h3>
            <div>{x.fix}</div>
            <div className="flex flex-wrap gap-2">
              {x.sources.map((source) => (
                <Button
                  key={source}
                  size="sm"
                  variant="outline"
                  className="bg-background"
                  onClick={() => w.go(library ? { ...sources[source].route, library } : sources[source].route)}
                >
                  Buka {sources[source].label}
                  <ArrowRight data-icon="inline-end" />
                </Button>
              ))}
              {x.targets?.map((target) => (
                <Button
                  key={target.code}
                  size="sm"
                  variant="outline"
                  className="bg-background"
                  onClick={() => w.go({ view: "sarpras", library: target.code })}
                >
                  Buka {target.name}
                  <ArrowRight data-icon="inline-end" />
                </Button>
              ))}
            </div>
          </div>
        )}
        {x.rows.length > 0 && (
          <div className="flex flex-col gap-2">
            <h3 className="text-xs font-medium text-muted-foreground">Rincian ({x.rows.length})</h3>
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
          </div>
        )}
      </AccordionContent>
    </AccordionItem>
  );
}

function Recap({ data }: { data: Extract<Data, { recap: Recap }> }) {
  const w = useWorkspace();
  const [filter, setFilter] = useState<State | "all">("all");
  const { counts, aspects } = data.recap;
  const overview = data.mode === "overview";
  const library = data.mode === "location" ? (data.location?.code ?? "") : "";
  const inventory: Route = library ? { view: "inventory", library } : { view: "inventory" };
  const totals = aspects.reduce<Record<State, number>>((all, x) => (all[stateOf(x.level)]++, all), { attention: 0, empty: 0, good: 0 });
  const gaps = counts
    ? ([
        counts.no_area > 0 && `${counts.no_area} ruangan belum memiliki luas`,
        counts.unclassified_rooms > 0 && `${counts.unclassified_rooms} ruangan belum memiliki fungsi`,
        counts.uncategorized > 0 && `${counts.uncategorized} dari ${counts.items} barang belum berkategori`,
      ].filter(Boolean) as string[])
    : [];
  const sections = aspects
    .filter((x) => filter === "all" || stateOf(x.level) === filter)
    .reduce<Record<string, Aspect[]>>((all, x) => ((all[x.section] ||= []).push(x), all), {});
  return (
    <>
      <Summary counts={totals} total={aspects.length} places={overview ? data.locations.length : undefined} />
      {data.unassigned > 0 && (
        <Alert>
          <Info />
          <AlertTitle>{data.unassigned} ruangan belum ditetapkan lokasinya</AlertTitle>
          <AlertDescription>
            Ruangan tanpa lokasi perpustakaan tidak masuk ke rekap lokasi mana pun.
            <Button size="sm" variant="outline" className="mt-2" onClick={() => w.go({ view: "inventory" })}>
              Tetapkan di Ruangan & Barang
              <ArrowRight data-icon="inline-end" />
            </Button>
          </AlertDescription>
        </Alert>
      )}
      {gaps.length > 0 && (
        <Alert>
          <Info />
          <AlertTitle>Lengkapi data agar rekap lebih akurat</AlertTitle>
          <AlertDescription>
            <ul className="list-disc pl-4">
              {gaps.map((g) => (
                <li key={g}>{g}</li>
              ))}
            </ul>
            <Button size="sm" variant="outline" className="mt-2" onClick={() => w.go(inventory)}>
              Lengkapi di Ruangan & Barang
              <ArrowRight data-icon="inline-end" />
            </Button>
          </AlertDescription>
        </Alert>
      )}
      {overview && <Comparison locations={data.locations} open={(code) => w.go({ view: "sarpras", library: code })} />}
      {overview && (
        <div className="flex flex-col gap-0.5">
          <h2 className="text-lg font-semibold tracking-tight">Kondisi gabungan</h2>
          <p className="text-sm text-muted-foreground">
            Tiap aspek adalah rata-rata dari lokasi yang memiliki data. Buka sebuah aspek untuk melihat kondisi tiap lokasi.
          </p>
        </div>
      )}
      <ToggleGroup
        type="single"
        variant="outline"
        className="flex-wrap"
        value={filter}
        onValueChange={(v) => setFilter((v || "all") as State | "all")}
        aria-label="Tampilkan aspek"
      >
        <ToggleGroupItem value="all" className="rounded-full px-3">
          Semua
          <span className="font-normal text-muted-foreground tabular-nums">{aspects.length}</span>
        </ToggleGroupItem>
        {(Object.keys(states) as State[]).map((state) => {
          const { label, icon: Icon, ink } = states[state];
          return (
            <ToggleGroupItem key={state} value={state} disabled={!totals[state]} className="rounded-full px-3">
              <Icon className={ink} />
              {label}
              <span className="font-normal text-muted-foreground tabular-nums">{totals[state]}</span>
            </ToggleGroupItem>
          );
        })}
      </ToggleGroup>
      {Object.entries(sections).map(([section, list]) => (
        <section key={section} className="flex flex-col gap-2">
          <h3 className="text-sm font-medium text-muted-foreground">{section}</h3>
          <Accordion type="multiple" className="overflow-hidden rounded-xl border bg-card">
            {list.map((x) => (
              <AspectRow key={x.no} aspect={x} levels={data.levels} library={library} />
            ))}
          </Accordion>
        </section>
      ))}
    </>
  );
}

export function SarprasPage() {
  const w = useWorkspace();
  const page = w.config.pages!.sarpras;
  const library = String(w.route.library || "");
  const { data, error } = usePage<Data>(page, library ? { library } : {});
  // Several locations hold rooms: the page then offers each one, and all of them together.
  const several = !!data && data.mode !== "empty" && data.locations.length > 1;
  const location = data?.mode === "location" ? data.location : null;
  return (
    <>
      <PageHeader
        crumbs={several && location ? [{ label: "Semua lokasi", route: { view: "sarpras" } }] : []}
        current={location?.name}
        title="Rekap Sarpras"
        description="Pantau kondisi sarana dan prasarana perpustakaan Anda di satu tempat. Rekap dihitung otomatis dari data ruangan, barang, perangkat lunak, jaringan, dan pemeriksaan."
        meta={
          data &&
          data.mode !== "empty" && <span className="text-xs text-muted-foreground">Data per {dateLabel(data.recap.generated_at)}</span>
        }
        actions={
          data &&
          data.mode !== "empty" && (
            <>
              {several && (
                <LocationSelect
                  all="Semua lokasi"
                  locations={data.locations}
                  value={location?.code ?? ""}
                  onChange={(code) => w.go(code ? { view: "sarpras", library: code } : { view: "sarpras" }, true)}
                />
              )}
              <Pdf label="Cetak rekap" href={(style) => url(page, { pdf: style, library: location?.code })} />
            </>
          )
        }
      />
      <ErrorBox message={error} />
      {!data ? (
        !error && <Loading />
      ) : data.mode === "empty" ? (
        <Blank icon={Building2} title="Belum ada ruangan" description="Rekap muncul setelah ruangan dan barang perpustakaan Anda dicatat.">
          <Button onClick={() => w.go({ view: "inventory" })}>
            Buka Ruangan & Barang
            <ArrowRight data-icon="inline-end" />
          </Button>
        </Blank>
      ) : (
        <Recap data={data} />
      )}
    </>
  );
}
