import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { Download, MapPin, Sparkles, Undo2, Users, Wand2 } from "lucide-react";
import { Alert, AlertDescription } from "./components/ui/alert";
import { Badge } from "./components/ui/badge";
import { Button } from "./components/ui/button";
import { Checkbox } from "./components/ui/checkbox";
import { Input } from "./components/ui/input";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "./components/ui/sheet";
import { Switch } from "./components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "./components/ui/table";
import { Tabs, TabsList, TabsTrigger } from "./components/ui/tabs";
import { ToggleGroup, ToggleGroupItem } from "./components/ui/toggle-group";
import { useWorkspace } from "./context";
import { dateLabel, url } from "./api";
import { Blank, Choice, ErrorBox, Loading, PageHeader, Panel, StatCard } from "./shared";
import { Confirm, usePage } from "./settings";

type Location = { code: string; name: string };
type Institution = {
  key: string;
  label: string;
  variants: { value: string; members: number; active: number }[];
  members: number;
  active: number;
  location: string;
  suggestion: string | null;
};
type Similar = { variants: { value: string; members: number }[]; suggestion: string };
type Fix = { id: number; from: string[]; to: string; count: number; created_at: string; undone_at: string | null; by: string };
type Data = {
  counts: { total: number; unmapped: number; single: boolean; locations: (Location & { count: number })[] };
  settings: { default: string; excluded: number[]; rule?: Rule };
  types: { id: number; name: string; active: number; location: string; excluded: boolean }[];
  institutions: Institution[];
  similar: Similar[];
  fixes: Fix[];
  locations: Location[];
  /** Whether this librarian may rewrite Institusi in SLiMS (Membership write access). */
  fix: boolean;
  /** Whether a card's details may list members by name (Membership read access). */
  members?: boolean;
  write: boolean;
  csrf: string;
};
type Run = (values: Record<string, unknown>, done?: () => void) => Promise<void>;
type Rule = "sivitas" | "slims";

/** Who counts as an active member, as the server's Sivitas::RULES. */
const rules: { value: Rule; title: string; text: string; panel: string }[] = [
  {
    value: "sivitas",
    title: "Tidak tertunda dan belum kedaluwarsa",
    text: "Anggota tertunda tidak dihitung. Yang kedaluwarsa hari ini dan yang tanpa tanggal kedaluwarsa tetap dihitung.",
    panel: "Anggota aktif: tidak tertunda dan belum kedaluwarsa.",
  },
  {
    value: "slims",
    title: "Seperti laporan Keanggotaan SLiMS",
    text: "Masa berlaku lewat dari hari ini, termasuk anggota tertunda. Sama dengan Total Anggota Aktif di Laporan → Keanggotaan.",
    panel: "Anggota aktif mengikuti laporan Keanggotaan SLiMS: masa berlakunya lewat dari hari ini, termasuk yang tertunda.",
  },
];

type Member = { id: string; name: string; institution: string; type: string; location: string; basis: string; expires: string | null; pending?: boolean };
/** What one card counts: every sivitas ("all"), those placed nowhere ("unmapped"), or a location's code. */
type Detail = {
  scope: string;
  label: string;
  total: number;
  locations: (Location & { count: number })[];
  basis: { basis: string; label: string; count: number }[];
  institutions: { label: string; count: number }[];
  types: { name: string; count: number }[];
  /** null without Membership read access: the breakdown comes alone. */
  members: { total: number; rows: Member[] } | null;
  shown: number;
};

const number = (n: number) => n.toLocaleString("id-ID");
/** What to send for an Institusi group: a spelling of it, so the server derives the same key. */
const valueOf = (row: Institution) => row.variants[0]?.value ?? row.label;
const SHOWN = 200;

function LocationChoice({ data, value, onChange, disabled }: { data: Data; value: string; onChange: (v: string) => void; disabled?: boolean }) {
  return (
    <Choice
      value={value}
      onChange={onChange}
      disabled={disabled || !data.write}
      placeholder="Belum dipetakan"
      items={[{ value: "", label: "Belum dipetakan" }, ...data.locations.map((l) => ({ value: l.code, label: l.name }))]}
    />
  );
}

/** Which member types count, and where members nothing else places go. */
function Counting({ data, run, busy }: { data: Data; run: Run; busy: boolean }) {
  const [excluded, setExcluded] = useState(data.settings.excluded);
  const [fallback, setFallback] = useState(data.settings.default);
  const saved: Rule = data.settings.rule ?? "sivitas";
  const [rule, setRule] = useState<Rule>(saved);
  const changed =
    JSON.stringify([...excluded].sort()) !== JSON.stringify([...data.settings.excluded].sort()) || fallback !== data.settings.default || rule !== saved;
  return (
    <Panel
      title="Yang dihitung"
      description={`${rules.find((r) => r.value === saved)!.panel} Hilangkan centang tipe anggota yang bukan sivitas, misalnya anggota luar.`}
      action={
        data.write && (
          <Button size="sm" disabled={busy || !changed} onClick={() => run({ action: "counting", excluded, default: fallback, rule })}>
            Simpan
          </Button>
        )
      }
    >
      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-2">
          <span className="text-sm font-medium">Anggota aktif</span>
          <ToggleGroup
            type="single"
            variant="outline"
            aria-label="Aturan anggota aktif"
            className="grid w-full sm:grid-cols-2"
            value={rule}
            disabled={!data.write}
            onValueChange={(v) => v && setRule(v as Rule)}
          >
            {rules.map((r) => (
              <ToggleGroupItem key={r.value} value={r.value} className="h-auto flex-col items-start gap-0 px-3 py-2 text-left">
                <span className="font-medium whitespace-normal">{r.title}</span>
                <span className="text-xs font-normal whitespace-normal text-muted-foreground">{r.text}</span>
              </ToggleGroupItem>
            ))}
          </ToggleGroup>
          {rule === "slims" && (
            <p className="text-xs text-muted-foreground">
              Agar jumlahnya sama persis dengan laporan SLiMS, centang semua tipe anggota di bawah: laporan SLiMS menghitung semua tipe.
            </p>
          )}
        </div>
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {data.types.map((t) => (
            <label key={t.id} className="flex items-center gap-2 rounded-lg border p-2.5 text-sm">
              <Checkbox
                disabled={!data.write}
                checked={!excluded.includes(t.id)}
                onCheckedChange={(on) => setExcluded((now) => (on ? now.filter((id) => id !== t.id) : [...now, t.id]))}
              />
              <span className="flex-1">{t.name}</span>
              <span className="text-muted-foreground tabular-nums">{number(t.active)}</span>
            </label>
          ))}
        </div>
        {!data.counts.single && (
          <div className="max-w-sm">
            <Choice
              label="Lokasi bawaan"
              description="Untuk anggota yang institusi dan tipenya belum dipetakan. Kosongkan agar mereka tetap terlihat sebagai belum dipetakan."
              value={fallback}
              onChange={setFallback}
              disabled={!data.write}
              items={[{ value: "", label: "Tidak ada" }, ...data.locations.map((l) => ({ value: l.code, label: l.name }))]}
            />
          </div>
        )}
      </div>
    </Panel>
  );
}

function Institutions({ data, run, busy, merge }: { data: Data; run: Run; busy: boolean; merge: (from: string[], to: string) => void }) {
  const [query, setQuery] = useState("");
  const [onlyUnmapped, setOnlyUnmapped] = useState(false);
  const [selected, setSelected] = useState<string[]>([]);
  const names = useMemo(() => Object.fromEntries(data.locations.map((l) => [l.code, l.name])), [data.locations]);
  const rows = data.institutions.filter(
    (row) =>
      (!onlyUnmapped || row.location === "") &&
      (query === "" || row.variants.some((v) => v.value.toLowerCase().includes(query.toLowerCase()))),
  );
  const suggested = data.institutions.filter((row) => row.location === "" && row.suggestion);
  const chosen = data.institutions.filter((row) => selected.includes(row.key));
  const map = (list: Institution[], location: string) =>
    run({ action: "map", basis: "institution", values: list.map(valueOf), location }, () => setSelected([]));

  if (data.institutions.length === 0) {
    return <Blank icon={Users} title="Belum ada institusi" description="Anggota SLiMS belum mengisi Institusi. Petakan lewat tipe anggota atau lokasi bawaan." />;
  }
  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-3">
        <Input className="max-w-xs" placeholder="Cari institusi" value={query} onChange={(e) => setQuery(e.target.value)} aria-label="Cari institusi" />
        <label className="flex items-center gap-2 text-sm">
          <Switch checked={onlyUnmapped} onCheckedChange={setOnlyUnmapped} />
          Hanya yang belum dipetakan
        </label>
        {data.write && suggested.length > 0 && (
          <Button
            variant="outline"
            size="sm"
            className="ml-auto"
            disabled={busy}
            onClick={async () => {
              for (const [code, list] of groupBy(suggested)) await map(list, code);
            }}
          >
            <Sparkles data-icon="inline-start" />
            Terapkan {number(suggested.length)} saran
          </Button>
        )}
      </div>
      {data.write && chosen.length > 0 && (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border bg-muted/40 p-3 text-sm">
          <span className="font-medium">{number(chosen.length)} dipilih</span>
          <div className="w-64">
            <LocationChoice data={data} value="" disabled={busy} onChange={(code) => map(chosen, code)} />
          </div>
          {data.fix && chosen.length > 1 && (
            <Button size="sm" variant="outline" disabled={busy} onClick={() => merge(chosen.flatMap((row) => row.variants.map((v) => v.value)), chosen[0].label)}>
              <Wand2 data-icon="inline-start" />
              Gabungkan ejaan
            </Button>
          )}
          <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
            Batal pilih
          </Button>
        </div>
      )}
      <Table>
        <TableHeader>
          <TableRow>
            {data.write && <TableHead className="w-0" />}
            <TableHead>Institusi</TableHead>
            <TableHead className="text-right">Aktif</TableHead>
            <TableHead className="w-64">Lokasi</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.slice(0, SHOWN).map((row) => (
            <TableRow key={row.key}>
              {data.write && (
                <TableCell>
                  <Checkbox
                    aria-label={`Pilih ${row.label || "tanpa institusi"}`}
                    checked={selected.includes(row.key)}
                    onCheckedChange={(on) => setSelected((now) => (on ? [...now, row.key] : now.filter((k) => k !== row.key)))}
                  />
                </TableCell>
              )}
              <TableCell className="whitespace-normal">
                <div className={row.label ? "font-medium" : "text-muted-foreground italic"}>{row.label || "Tanpa institusi"}</div>
                {row.variants.length > 1 && (
                  <div className="text-xs text-muted-foreground">
                    {row.variants.length} ejaan: {row.variants.map((v) => `"${v.value}"`).join(", ")}
                  </div>
                )}
                {row.location === "" && row.suggestion && (
                  <button
                    type="button"
                    disabled={!data.write || busy}
                    className="mt-1 inline-flex items-center gap-1 text-xs text-primary hover:underline disabled:opacity-50"
                    onClick={() => map([row], row.suggestion!)}
                  >
                    <Sparkles className="size-3" />
                    Saran: {names[row.suggestion]}
                  </button>
                )}
              </TableCell>
              <TableCell className="text-right tabular-nums">{number(row.active)}</TableCell>
              <TableCell>
                <LocationChoice data={data} value={row.location} disabled={busy} onChange={(code) => map([row], code)} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {rows.length > SHOWN && (
        <p className="text-sm text-muted-foreground">
          Menampilkan {SHOWN} dari {number(rows.length)} institusi. Cari untuk mempersempit.
        </p>
      )}
    </div>
  );
}

/** Rows grouped by their suggested location, to map each group in one request. */
function groupBy(rows: Institution[]): [string, Institution[]][] {
  const groups: Record<string, Institution[]> = {};
  for (const row of rows) (groups[row.suggestion!] ??= []).push(row);
  return Object.entries(groups);
}

function Types({ data, run, busy }: { data: Data; run: Run; busy: boolean }) {
  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">Dipakai untuk anggota yang institusinya belum dipetakan, misalnya bila tiap kampus punya tipe anggota sendiri.</p>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Tipe anggota</TableHead>
            <TableHead className="text-right">Aktif</TableHead>
            <TableHead className="w-64">Lokasi</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {data.types.map((t) => (
            <TableRow key={t.id}>
              <TableCell className="font-medium">
                {t.name}
                {t.excluded && (
                  <Badge variant="secondary" className="ml-2">
                    Tidak dihitung
                  </Badge>
                )}
              </TableCell>
              <TableCell className="text-right tabular-nums">{number(t.active)}</TableCell>
              <TableCell>
                <LocationChoice data={data} value={t.location} disabled={busy} onChange={(code) => run({ action: "map", basis: "type", value: t.id, location: code })} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

/** Mistyped Institusi: one spelling picked, the others rewritten in SLiMS, each merge undoable. */
function Fixes({ data, busy, merge, undo }: { data: Data; busy: boolean; merge: (from: string[], to: string) => void; undo: (fix: Fix) => void }) {
  const [targets, setTargets] = useState<Record<number, string>>({});
  return (
    <div className="flex flex-col gap-6">
      {!data.fix && (
        <Alert>
          <AlertDescription>Memperbaiki institusi mengubah data anggota SLiMS, jadi memerlukan hak tulis Keanggotaan selain hak tulis Stock Take.</AlertDescription>
        </Alert>
      )}
      {data.similar.length === 0 ? (
        <Blank icon={Wand2} title="Tidak ada ejaan yang mirip" description="Institusi anggota sudah seragam. Gabungkan manual dengan memilih beberapa baris di tab Institusi." />
      ) : (
        <div className="flex flex-col gap-3">
          <p className="text-sm text-muted-foreground">
            Kelompok berikut tampak seperti satu institusi yang diketik berbeda. Pilih ejaan yang benar, lalu gabungkan. Data Institusi anggota di SLiMS ikut diperbaiki.
          </p>
          {data.similar.map((group, index) => {
            const target = targets[index] ?? group.suggestion;
            const others = group.variants.map((v) => v.value).filter((v) => v !== target);
            const affected = group.variants.filter((v) => v.value !== target).reduce((sum, v) => sum + v.members, 0);
            return (
              <div key={group.variants.map((v) => v.value).join("|")} className="flex flex-col gap-3 rounded-xl border p-4">
                <div className="flex flex-col gap-1.5">
                  {group.variants.map((v) => (
                    <label key={v.value} className="flex items-center gap-2 text-sm">
                      <input
                        type="radio"
                        name={`target-${index}`}
                        checked={target === v.value}
                        disabled={!data.fix}
                        onChange={() => setTargets((now) => ({ ...now, [index]: v.value }))}
                      />
                      <span className="flex-1">"{v.value}"</span>
                      <span className="text-muted-foreground tabular-nums">{number(v.members)} anggota</span>
                    </label>
                  ))}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <Input
                    className="max-w-md"
                    aria-label="Ejaan yang benar"
                    value={target}
                    disabled={!data.fix}
                    onChange={(e) => setTargets((now) => ({ ...now, [index]: e.target.value }))}
                  />
                  <Button size="sm" disabled={!data.fix || busy || target.trim() === "" || others.length === 0} onClick={() => merge(others, target)}>
                    <Wand2 data-icon="inline-start" />
                    Gabungkan {number(affected)} anggota
                  </Button>
                </div>
              </div>
            );
          })}
        </div>
      )}
      {data.fixes.length > 0 && (
        <Panel title="Riwayat perbaikan" description="Mengurungkan hanya mengembalikan anggota yang institusinya belum diubah lagi sejak itu.">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Waktu</TableHead>
                <TableHead>Perubahan</TableHead>
                <TableHead className="text-right">Anggota</TableHead>
                <TableHead className="w-0" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.fixes.map((fix) => (
                <TableRow key={fix.id}>
                  <TableCell>
                    <div>{dateLabel(fix.created_at)}</div>
                    <div className="text-xs text-muted-foreground">{fix.by}</div>
                  </TableCell>
                  <TableCell className="whitespace-normal">
                    {fix.from.map((f) => `"${f}"`).join(", ")} → <span className="font-medium">"{fix.to}"</span>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{number(fix.count)}</TableCell>
                  <TableCell>
                    {fix.undone_at ? (
                      <Badge variant="secondary">Diurungkan</Badge>
                    ) : (
                      data.fix && (
                        <Button size="sm" variant="ghost" disabled={busy} onClick={() => undo(fix)}>
                          <Undo2 data-icon="inline-start" />
                          Urungkan
                        </Button>
                      )
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Panel>
      )}
    </div>
  );
}

/** Rows of a breakdown, each with its share of the card's count. */
function Breakdown({ title, rows, total, limit = 0 }: { title: string; rows: { label: string; count: number }[]; total: number; limit?: number }) {
  const [all, setAll] = useState(false);
  if (rows.length === 0) return null;
  const shown = limit && !all ? rows.slice(0, limit) : rows;
  return (
    <section className="flex flex-col gap-2">
      <h3 className="text-sm font-medium">{title}</h3>
      <ul className="flex flex-col gap-1.5">
        {shown.map((row, index) => (
          <li key={`${row.label}-${index}`} className="flex flex-col gap-1">
            <div className="flex items-baseline justify-between gap-3 text-sm">
              <span className={row.label ? "min-w-0 break-words" : "text-muted-foreground italic"}>{row.label || "Tanpa institusi"}</span>
              <span className="shrink-0 tabular-nums">{number(row.count)}</span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden>
              <div className="h-full rounded-full bg-primary" style={{ width: `${total ? Math.max(1, (row.count / total) * 100) : 0}%` }} />
            </div>
          </li>
        ))}
      </ul>
      {limit > 0 && rows.length > limit && (
        <Button variant="ghost" size="sm" className="self-start" onClick={() => setAll((now) => !now)}>
          {all ? "Tampilkan lebih sedikit" : `Tampilkan semua ${number(rows.length)}`}
        </Button>
      )}
    </section>
  );
}

/** What a card's count is made of, with its members where the librarian may see them, and a workbook to take away. */
function CardDetail({ data, scope, onClose }: { data: Data; scope: string; onClose: () => void }) {
  const w = useWorkspace();
  const page = w.config.pages!.sivitas;
  const [query, setQuery] = useState("");
  const [search, setSearch] = useState("");
  useEffect(() => {
    const timer = setTimeout(() => setSearch(query.trim()), 300);
    return () => clearTimeout(timer);
  }, [query]);
  const { data: fresh, error } = usePage<Detail>(page, search ? { detail: scope, q: search } : { detail: scope });
  // While a search loads, the last answer stays on screen.
  const [kept, setKept] = useState<Detail>();
  useEffect(() => {
    if (fresh) setKept(fresh);
  }, [fresh]);
  const detail = fresh ?? kept;
  const everyone = scope === "all" && !data.counts.single;
  const members = detail?.members;
  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="flex w-full flex-col gap-0 sm:max-w-2xl">
        <SheetHeader className="pr-12">
          <SheetTitle>{detail?.label ?? "Rincian sivitas"}</SheetTitle>
          <SheetDescription>
            {detail ? `${number(detail.total)} anggota aktif yang dihitung sebagai sivitas, per ${dateLabel(w.config.today)}.` : "Memuat rincian…"}
          </SheetDescription>
          <div className="pt-2">
            <Button variant="outline" size="sm" asChild>
              <a className="notAJAX" href={url(page, { download: scope })} download>
                <Download data-icon="inline-start" />
                Unduh Excel
              </a>
            </Button>
          </div>
        </SheetHeader>
        <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto px-4 pb-6">
          <ErrorBox message={error} />
          {!detail && !error && <Loading />}
          {detail && detail.total === 0 && !search && (
            <Blank icon={Users} title="Tidak ada anggota" description="Belum ada anggota aktif yang dihitung di sini." />
          )}
          {detail && (detail.total > 0 || search) && (
            <>
              <div className="grid gap-6 md:grid-cols-2">
                {everyone && <Breakdown title="Per lokasi" rows={detail.locations.map((l) => ({ label: l.name, count: l.count }))} total={detail.total} />}
                {!data.counts.single && <Breakdown title="Dipetakan lewat" rows={detail.basis} total={detail.total} />}
                <Breakdown title="Tipe anggota" rows={detail.types.map((t) => ({ label: t.name, count: t.count }))} total={detail.total} />
                <Breakdown title="Institusi" rows={detail.institutions} total={detail.total} limit={8} />
              </div>
              <section className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <h3 className="text-sm font-medium">Anggota</h3>
                  {members && (
                    <Input
                      className="w-full sm:max-w-64"
                      placeholder="Cari nama, ID, atau institusi"
                      aria-label="Cari anggota"
                      value={query}
                      onChange={(e) => setQuery(e.target.value)}
                    />
                  )}
                </div>
                {members === null ? (
                  <Alert>
                    <AlertDescription>
                      Daftar anggota memerlukan hak baca Keanggotaan. Unduhan Excel hanya memuat ringkasannya.
                    </AlertDescription>
                  </Alert>
                ) : members && members.rows.length === 0 ? (
                  <p className="text-sm text-muted-foreground">Tidak ada anggota yang cocok dengan "{search}".</p>
                ) : (
                  members && (
                    <>
                      <div className="overflow-x-auto rounded-xl border">
                        <Table>
                          <TableHeader className="bg-muted/50">
                            <TableRow>
                              <TableHead>Anggota</TableHead>
                              <TableHead>Institusi</TableHead>
                              <TableHead className="hidden sm:table-cell">Tipe</TableHead>
                              {everyone && <TableHead className="hidden md:table-cell">Lokasi</TableHead>}
                              <TableHead className="hidden text-right sm:table-cell">Berlaku sampai</TableHead>
                            </TableRow>
                          </TableHeader>
                          <TableBody>
                            {members.rows.map((m) => (
                              <TableRow key={m.id}>
                                <TableCell className="max-w-56 whitespace-normal">
                                  <div className="font-medium">
                                    {m.name || "—"}
                                    {m.pending && (
                                      <Badge variant="secondary" className="ml-1.5">
                                        Tertunda
                                      </Badge>
                                    )}
                                  </div>
                                  <div className="text-xs text-muted-foreground tabular-nums">{m.id}</div>
                                </TableCell>
                                <TableCell className="max-w-56 whitespace-normal">
                                  {m.institution || <span className="text-muted-foreground italic">Tanpa institusi</span>}
                                  {m.basis && !everyone && scope !== "unmapped" && <div className="text-xs text-muted-foreground">Lewat {m.basis.toLowerCase()}</div>}
                                </TableCell>
                                <TableCell className="hidden sm:table-cell">{m.type}</TableCell>
                                {everyone && <TableCell className="hidden md:table-cell">{m.location}</TableCell>}
                                <TableCell className="hidden text-right tabular-nums sm:table-cell">{m.expires ? dateLabel(m.expires) : "—"}</TableCell>
                              </TableRow>
                            ))}
                          </TableBody>
                        </Table>
                      </div>
                      {members.total > members.rows.length && (
                        <p className="text-sm text-muted-foreground">
                          Menampilkan {number(members.rows.length)} dari {number(members.total)} anggota. Cari untuk mempersempit, atau unduh Excel untuk daftar lengkapnya.
                        </p>
                      )}
                    </>
                  )
                )}
              </section>
            </>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

export function SivitasPage() {
  const w = useWorkspace();
  const { data, error, reload, post } = usePage<Data>(w.config.pages!.sivitas);
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState("institutions");
  const [merging, setMerging] = useState<{ from: string[]; to: string } | null>(null);
  const [undoing, setUndoing] = useState<Fix | null>(null);
  const [card, setCard] = useState<string | null>(null);

  const run: Run = async (values, done) => {
    if (!data) return;
    setBusy(true);
    try {
      const reply = await post({ ...values, csrf: data.csrf });
      toast.success(reply.message);
      done?.();
      reload();
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const header = (
    <PageHeader
      title="Sivitas per Lokasi"
      description="Sivitas dihitung dari anggota SLiMS yang aktif, lalu dipakai Rekap Sarpras untuk luas per orang. Bila perpustakaan punya beberapa lokasi, petakan anggota ke lokasinya lewat institusi atau tipe anggota."
    />
  );
  if (!data)
    return (
      <>
        {header}
        <ErrorBox message={error} />
        {!error && <Loading />}
      </>
    );

  const affected = merging
    ? data.institutions.flatMap((row) => row.variants).filter((v) => merging.from.includes(v.value)).reduce((sum, v) => sum + v.members, 0) ||
      data.similar.flatMap((g) => g.variants).filter((v) => merging.from.includes(v.value)).reduce((sum, v) => sum + v.members, 0)
    : 0;

  return (
    <>
      {header}
      <ErrorBox message={error} />
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Sivitas" value={number(data.counts.total)} icon={Users} hint="Anggota aktif yang dihitung" onClick={() => setCard("all")} />
        {!data.counts.single && (
          <StatCard
            label="Belum dipetakan"
            value={number(data.counts.unmapped)}
            icon={MapPin}
            tone={data.counts.unmapped > 0 ? "warning" : "success"}
            hint={data.counts.unmapped > 0 ? "Tidak dihitung ke lokasi mana pun" : "Semua sudah punya lokasi"}
            onClick={() => setCard("unmapped")}
          />
        )}
        {!data.counts.single &&
          data.counts.locations
            .filter((l) => l.count > 0)
            .map((l) => <StatCard key={l.code} label={l.name} value={number(l.count)} onClick={() => setCard(l.code)} />)}
      </div>
      <p className="-mt-2 text-xs text-muted-foreground">Klik kartu untuk melihat rincian anggotanya dan mengunduhnya sebagai Excel.</p>
      {card !== null && <CardDetail key={card} data={data} scope={card} onClose={() => setCard(null)} />}
      {data.counts.single && (
        <Alert>
          <AlertDescription>Ruangan perpustakaan ini berada di satu lokasi, jadi semua sivitas dihitung untuknya. Pemetaan baru diperlukan bila ruangan tersebar di beberapa lokasi.</AlertDescription>
        </Alert>
      )}
      <Counting key={JSON.stringify(data.settings)} data={data} run={run} busy={busy} />
      <Tabs value={data.counts.single ? "fixes" : tab} onValueChange={setTab}>
        <TabsList variant="line" className="w-full justify-start border-b">
          {!data.counts.single && (
            <>
              <TabsTrigger value="institutions" className="flex-none">
                Institusi
              </TabsTrigger>
              <TabsTrigger value="types" className="flex-none">
                Tipe anggota
              </TabsTrigger>
            </>
          )}
          <TabsTrigger value="fixes" className="flex-none">
            Perbaiki institusi
            {data.similar.length > 0 && (
              <Badge variant="warning" className="ml-1.5">
                {data.similar.length}
              </Badge>
            )}
          </TabsTrigger>
        </TabsList>
      </Tabs>
      {!data.counts.single && tab === "institutions" && <Institutions data={data} run={run} busy={busy} merge={(from, to) => setMerging({ from, to })} />}
      {!data.counts.single && tab === "types" && <Types data={data} run={run} busy={busy} />}
      {(data.counts.single || tab === "fixes") && <Fixes data={data} busy={busy} merge={(from, to) => setMerging({ from, to })} undo={setUndoing} />}
      <Confirm
        open={merging !== null}
        title="Gabungkan ejaan institusi?"
        description={
          merging && (
            <>
              Institusi {number(affected)} anggota di SLiMS diubah menjadi <b>"{merging.to.trim()}"</b>. Anda dapat mengurungkannya dari riwayat perbaikan.
              {merging.from.length > 0 && (
                <span className="mt-2 block">Ejaan yang diganti: {merging.from.filter((f) => f !== merging.to).map((f) => `"${f}"`).join(", ")}.</span>
              )}
            </>
          )
        }
        action="Gabungkan"
        busy={busy}
        onCancel={() => setMerging(null)}
        onConfirm={() => merging && run({ action: "merge", from: merging.from.filter((f) => f !== merging.to), to: merging.to }, () => setMerging(null))}
      />
      <Confirm
        open={undoing !== null}
        title="Urungkan perbaikan ini?"
        description={undoing && `Institusi anggota yang diubah menjadi "${undoing.to}" dikembalikan ke ejaan sebelumnya, kecuali yang sudah diubah lagi sejak itu.`}
        action="Urungkan"
        busy={busy}
        onCancel={() => setUndoing(null)}
        onConfirm={() => undoing && run({ action: "undo", record_id: undoing.id }, () => setUndoing(null))}
      />
    </>
  );
}
