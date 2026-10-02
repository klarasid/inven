import { roomLabel } from "./rooms";
import { Fragment, useEffect, useId, useRef, useState, type ReactNode } from "react";
import {
  ChevronLeft,
  ChevronRight,
  FileText,
  MoreHorizontal,
  ImagePlus,
  Camera,
  X,
  Search as SearchIcon,
  Undo2,
  ChevronDown,
  Stamp,
  type LucideIcon,
  ExternalLink,
} from "lucide-react";
import { Button } from "./components/ui/button";
import { Field, FieldLabel, FieldDescription, FieldError } from "./components/ui/field";
import { Input } from "./components/ui/input";
import { Textarea } from "./components/ui/textarea";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectGroup,
  SelectItem,
} from "./components/ui/select";
import { Alert, AlertTitle, AlertDescription } from "./components/ui/alert";
import {
  Empty,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
  EmptyDescription,
  EmptyContent,
} from "./components/ui/empty";
import { Badge } from "./components/ui/badge";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "./components/ui/dialog";
import { Skeleton } from "./components/ui/skeleton";
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
} from "./components/ui/dropdown-menu";
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardAction } from "./components/ui/card";
import {
  Breadcrumb,
  BreadcrumbList,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "./components/ui/breadcrumb";
import { cn } from "./lib/utils";
import { useWorkspace } from "./context";
import { dateLabel, statuses, url, read } from "./api";
import type { Event, Photo, Route } from "./types";

export function ErrorBox({ message }: { message: string }) {
  return message ? (
    <Alert variant="destructive">
      <AlertTitle>Perlu diperiksa</AlertTitle>
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  ) : null;
}

export function Loading() {
  return (
    <div className="flex flex-col gap-3" aria-label="Memuat" role="status">
      <Skeleton className="h-9 w-full" />
      <Skeleton className="h-14 w-full" />
      <Skeleton className="h-14 w-full" />
      <Skeleton className="h-14 w-full" />
    </div>
  );
}

export function Blank({
  icon: Icon,
  title = "Belum ada data",
  description = "Data yang sesuai akan tampil di sini.",
  children,
}: {
  icon?: LucideIcon;
  title?: string;
  description?: string;
  children?: ReactNode;
}) {
  return (
    <Empty className="border border-dashed">
      <EmptyHeader>
        {Icon && (
          <EmptyMedia variant="icon">
            <Icon />
          </EmptyMedia>
        )}
        <EmptyTitle>{title}</EmptyTitle>
        <EmptyDescription>{description}</EmptyDescription>
      </EmptyHeader>
      {children && <EmptyContent>{children}</EmptyContent>}
    </Empty>
  );
}

export interface Crumb {
  label: string;
  route?: Route;
}

/**
 * Page title with a breadcrumb trail: parent levels are links, the last item is a short
 * name of the current page (defaults to the title when it is plain text).
 */
export function PageHeader({
  crumbs = [],
  current,
  title,
  description,
  actions,
  meta,
}: {
  crumbs?: Crumb[];
  current?: string;
  title: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
  meta?: ReactNode;
}) {
  const w = useWorkspace();
  const here = current ?? (typeof title === "string" ? title : "");
  return (
    <header className="flex flex-col gap-3">
      {crumbs.length > 0 && (
        <Breadcrumb>
          <BreadcrumbList className="gap-1 sm:gap-1.5">
            {crumbs.map((c, i) => (
              <Fragment key={i}>
                <BreadcrumbItem className="min-w-0">
                  <BreadcrumbLink asChild>
                    <button
                      type="button"
                      className="inline-flex max-w-48 min-w-0 cursor-pointer items-center rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                      onClick={() => (c.route ? w.go(c.route) : w.back())}
                    >
                      <span className="truncate">{c.label}</span>
                    </button>
                  </BreadcrumbLink>
                </BreadcrumbItem>
                <BreadcrumbSeparator className="flex items-center" />
              </Fragment>
            ))}
            {here && (
              <BreadcrumbItem className="min-w-0">
                <BreadcrumbPage className="block max-w-56 truncate">{here}</BreadcrumbPage>
              </BreadcrumbItem>
            )}
          </BreadcrumbList>
        </Breadcrumb>
      )}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
          {description && <p className="text-sm text-muted-foreground">{description}</p>}
          {meta && <div className="mt-1 flex flex-wrap items-center gap-2">{meta}</div>}
        </div>
        {actions && <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">{actions}</div>}
      </div>
    </header>
  );
}

export function Panel({
  title,
  description,
  action,
  children,
  className,
}: {
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <Card className={className}>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        {description && <CardDescription>{description}</CardDescription>}
        {action && <CardAction>{action}</CardAction>}
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

export function TextField({
  label,
  value,
  onChange,
  type = "text",
  error,
  description,
  required = false,
  multiline = false,
  maxLength,
  min,
  max,
  placeholder,
  rows = 3,
}: {
  label: string;
  value: unknown;
  onChange: (s: string) => void;
  type?: string;
  error?: string;
  description?: string;
  required?: boolean;
  multiline?: boolean;
  maxLength?: number;
  min?: string;
  max?: string;
  placeholder?: string;
  rows?: number;
}) {
  const id = useId();
  const common = {
    id,
    value: String(value ?? ""),
    onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => onChange(e.target.value),
    "aria-invalid": !!error,
    required,
    maxLength,
    placeholder,
  };
  return (
    <Field data-invalid={!!error}>
      <FieldLabel htmlFor={id}>
        {label}
        {required && <span className="text-destructive">*</span>}
      </FieldLabel>
      {multiline ? (
        <Textarea {...common} rows={rows} />
      ) : (
        <Input {...common} type={type} min={min} max={max} step={type === "number" ? "any" : undefined} />
      )}
      {description && <FieldDescription>{description}</FieldDescription>}
      {error && <FieldError>{error}</FieldError>}
    </Field>
  );
}

export function Choice({
  label,
  value,
  onChange,
  items,
  placeholder = "Pilih",
  disabled = false,
  error,
  required = false,
  description,
}: {
  label?: string;
  value: unknown;
  onChange: (s: string) => void;
  items: { value: unknown; label: string }[];
  placeholder?: string;
  disabled?: boolean;
  error?: string;
  required?: boolean;
  description?: string;
}) {
  const id = useId();
  return (
    <Field data-invalid={!!error} data-disabled={disabled}>
      {label && (
        <FieldLabel htmlFor={id}>
          {label}
          {required && <span className="text-destructive">*</span>}
        </FieldLabel>
      )}
      <ChoiceSelect
        id={id}
        value={value}
        onChange={onChange}
        items={items}
        placeholder={placeholder}
        disabled={disabled}
        invalid={!!error}
      />
      {description && <FieldDescription>{description}</FieldDescription>}
      {error && <FieldError>{error}</FieldError>}
    </Field>
  );
}

function ChoiceSelect({
  id,
  value,
  onChange,
  items,
  placeholder,
  disabled,
  invalid,
  className = "w-full",
  label,
  clearable = true,
}: {
  id?: string;
  value: unknown;
  onChange: (s: string) => void;
  items: { value: unknown; label: string }[];
  placeholder: string;
  disabled?: boolean;
  invalid?: boolean;
  className?: string;
  label?: string;
  /** Whether the placeholder is itself a choice (of nothing). */
  clearable?: boolean;
}) {
  return (
    <Select
      value={String(value ?? "") || "__empty"}
      onValueChange={(v) => onChange(v === "__empty" ? "" : v)}
      disabled={disabled}
    >
      <SelectTrigger id={id} aria-invalid={invalid} aria-label={label} className={className}>
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        <SelectGroup>
          {clearable && <SelectItem value="__empty">{placeholder}</SelectItem>}
          {items
            .filter((x) => String(x.value) !== "")
            .map((x) => (
              <SelectItem key={String(x.value)} value={String(x.value)}>
                {x.label}
              </SelectItem>
            ))}
        </SelectGroup>
      </SelectContent>
    </Select>
  );
}

export const entries = (o: Record<string, string>) => Object.entries(o).map(([value, label]) => ({ value, label }));

const statusTone: Record<string, "outline" | "info" | "success" | "warning" | "secondary"> = {
  pending: "outline",
  draft: "info",
  final: "success",
  open: "warning",
  working: "info",
  review: "secondary",
  closed: "success",
};
export function Status({ value }: { value: string }) {
  return <Badge variant={statusTone[value] || "outline"}>{statuses[value] || value}</Badge>;
}

export const conditions: Record<string, string> = { B: "Baik", KB: "Kurang baik", RB: "Rusak berat" };
const conditionTone: Record<string, "success" | "warning" | "destructive"> = { B: "success", KB: "warning", RB: "destructive" };
export function Condition({ value }: { value: unknown }) {
  const v = String(value || "");
  return <Badge variant={conditionTone[v] || "outline"}>{conditions[v] || "—"}</Badge>;
}

export function Pager({
  page,
  pages,
  total,
  onChange,
}: {
  page: number;
  pages: number;
  total: number;
  onChange: (n: number) => void;
}) {
  return (
    <div className="flex items-center justify-between gap-3">
      <span className="text-sm text-muted-foreground">
        {total} data{pages > 1 && ` · Halaman ${page} dari ${pages}`}
      </span>
      {pages > 1 && (
        <div className="flex gap-2">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => onChange(page - 1)}>
            <ChevronLeft data-icon="inline-start" />
            Sebelumnya
          </Button>
          <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => onChange(page + 1)}>
            Berikutnya
            <ChevronRight data-icon="inline-end" />
          </Button>
        </div>
      )}
    </div>
  );
}

type Colorbox = ((options: Record<string, unknown>) => void) & { resize?: (options: Record<string, unknown>) => void };

/**
 * Opens a PDF in SLiMS's own print preview (the colorbox iframe popup used for catalog and barcode
 * printing) instead of a download or new tab. The popup loads the plugin's PDF.js viewer, which fetches
 * and draws the file itself, so browser PDF settings (e.g. Firefox set to save or hand PDFs to Acrobat)
 * and download managers cannot turn the preview into a download. Falls back to a new tab where the
 * popup is missing, e.g. the standalone QR page, or hidden behind this app in fullscreen.
 */
export function previewPdf(config: { viewer?: string }, pdf: string, title: string) {
  const href = config.viewer ? url(config.viewer, { file: pdf, title }) : pdf;
  let reason = "fullscreen";
  try {
    // Call colorbox as a method of top's jQuery: it relies on `this` being jQuery (calls this.each).
    const top = window.top as (Window & { jQuery?: { colorbox?: Colorbox } }) | null;
    const jq = top?.jQuery;
    if (typeof jq?.colorbox !== "function") reason = "SLiMS colorbox not loaded";
    else if (!document.fullscreenElement) {
      // Pixel sizes from the real viewport: colorbox's own "92%" sizing came out far too short on
      // Firefox for Windows. innerHeight leaves room for SLiMS's title bar under the frame.
      const size = {
        innerWidth: Math.max(320, Math.round(top!.innerWidth * 0.92 - 20)),
        innerHeight: Math.max(320, Math.round(top!.innerHeight * 0.92 - 70)),
      };
      jq.colorbox({
        href,
        iframe: true,
        ...size,
        title,
        fastIframe: false,
        onComplete: () => jq.colorbox?.resize?.(size),
      });
      return;
    }
  } catch (e) {
    reason = `colorbox failed: ${(e as Error).message}`;
  }
  console.warn(`[inventaris] PDF preview opened in a new tab (${reason}).`);
  window.open(href, "_blank", "noopener");
}

export function Pdf({
  record,
  room,
  period,
  label = "Cetak PDF",
  href,
}: {
  record?: unknown;
  room?: unknown;
  period?: Route;
  label?: string;
  /** PDF address per style, for pages outside the supervision reports. */
  href?: (style: string) => string;
}) {
  const { config, go } = useWorkspace();
  const [letterheads, setLetterheads] = useState<{ id: string; name: string }[]>();
  if (room)
    return (
      <Button
        variant="outline"
        onClick={() => previewPdf(config, url(config.inventory, { workspace: "", action: "print_pdf", location_id: room }), label)}
      >
        <FileText data-icon="inline-start" />
        {label}
      </Button>
    );
  // Reports and inspection documents come in two typesetting styles, plus any institution letterheads.
  const target = href ?? ((style: string) => url(config.watch, { ...period, tab: "pdf", record: record ?? "", style }));
  const styles = [
    { value: "latex", title: "Gaya LaTeX", text: "Huruf serif, tabel booktabs, ringkas dan formal." },
    { value: "iso", title: "Dokumen ISO", text: "Kepala dokumen terkendali, tabel bergaris, lembar pengesahan." },
    ...(letterheads || []).map((t) => ({ value: `kop:${t.id}`, title: `Kop: ${t.name}`, text: "Template kop institusi." })),
  ];
  return (
    <DropdownMenu
      onOpenChange={(open) => {
        if (open && !letterheads)
          read<{ templates: { id: string; name: string }[] }>(config, "letterheads")
            .then((d) => setLetterheads(d.templates))
            .catch(() => setLetterheads([]));
      }}
    >
      <DropdownMenuTrigger asChild>
        <Button variant="outline">
          <FileText data-icon="inline-start" />
          {label}
          <ChevronDown data-icon="inline-end" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-72 p-1.5">
        <DropdownMenuLabel className="text-xs text-muted-foreground">Pilih format PDF</DropdownMenuLabel>
        <DropdownMenuGroup>
          {styles.map((s) => (
            <DropdownMenuItem
              key={s.value}
              className="flex flex-col items-start gap-0.5 px-2 py-2"
              onSelect={() => previewPdf(config, target(s.value), `${label} · ${s.title}`)}
            >
              <span className="font-medium">{s.title}</span>
              <span className="text-xs leading-snug whitespace-normal text-muted-foreground">{s.text}</span>
            </DropdownMenuItem>
          ))}
        </DropdownMenuGroup>
        {config.write && (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem className="gap-2 px-2 py-1.5" onSelect={() => go({ view: "print-settings" })}>
              <Stamp />
              Kelola template kop…
            </DropdownMenuItem>
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export function Actions({
  items,
  label = "Tindakan lainnya",
}: {
  items: { label: string; run: () => void; destructive?: boolean; icon?: LucideIcon }[];
  label?: string;
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" aria-label={label} onClick={(e) => e.stopPropagation()}>
          <MoreHorizontal />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-auto min-w-44 p-1.5" onClick={(e) => e.stopPropagation()}>
        <DropdownMenuGroup>
          {items.map(({ label, run, destructive, icon: Icon }) => (
            <DropdownMenuItem key={label} variant={destructive ? "destructive" : "default"} onSelect={run} className="gap-2 px-2 py-1.5">
              {Icon && <Icon />}
              {label}
            </DropdownMenuItem>
          ))}
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/** Search as you type; the list updates after a short pause instead of requiring a submit button. */
export function SearchBox({ placeholder = "Cari…" }: { placeholder?: string }) {
  const { route, go } = useWorkspace();
  const [value, setValue] = useState(String(route.q || ""));
  const latest = useRef(route);
  latest.current = route;
  useEffect(() => setValue(String(route.q || "")), [route.q]);
  useEffect(() => {
    if (value === String(latest.current.q || "")) return;
    const timer = setTimeout(() => go({ ...latest.current, q: value, page: 1 }, true), 350);
    return () => clearTimeout(timer);
  }, [value]);
  return (
    <div className="relative w-full sm:max-w-xs">
      <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
      <Input
        aria-label="Cari"
        type="search"
        value={value}
        placeholder={placeholder}
        className="pl-8"
        onChange={(e) => setValue(e.target.value)}
      />
    </div>
  );
}

/** Inline room filter; room labels already include the library, so one control replaces the old filter sheet. */
export function RoomFilter() {
  const { route, go, options } = useWorkspace();
  return (
    <ChoiceSelect
      label="Filter ruangan"
      className="w-full sm:w-64"
      value={route.room}
      onChange={(room) => go({ ...route, room, page: 1 }, true)}
      placeholder="Semua ruangan"
      items={options.rooms.map((x) => ({ value: x.id, label: roomLabel(x, options.libraries) }))}
    />
  );
}

export function LibraryFilter() {
  const { route, go, options } = useWorkspace();
  return (
    <ChoiceSelect
      label="Filter perpustakaan"
      className="w-full sm:w-64"
      value={route.library}
      onChange={(library) => go({ ...route, library, page: 1 }, true)}
      placeholder="Semua perpustakaan"
      items={options.libraries.map((x) => ({ value: x.location_id, label: x.location_name }))}
    />
  );
}

/** The library location a page works on. `all` names the choice of every location; without it one must be chosen. */
export function LocationSelect({
  locations,
  value,
  onChange,
  all,
}: {
  locations: { code: string; name: string }[];
  value: string;
  onChange: (code: string) => void;
  all?: string;
}) {
  return (
    <ChoiceSelect
      label="Lokasi perpustakaan"
      className="w-full sm:w-72"
      value={value}
      onChange={onChange}
      placeholder={all ?? "Pilih lokasi"}
      clearable={!!all}
      items={locations.map((x) => ({ value: x.code, label: x.name }))}
    />
  );
}

const eventLabels: Record<string, string> = {
  import_history: "Riwayat diimpor",
  import_action: "Pekerjaan historis diimpor",
  import_verification: "Verifikasi historis dicatat",
  start: "Pekerjaan dimulai",
  save_draft: "Draf disimpan",
  finalize: "Pemeriksaan selesai",
  save_action: "Pekerjaan disimpan",
  submit: "Diajukan untuk verifikasi",
  verify: "Hasil diterima",
  reject: "Dikembalikan",
  correction: "Catatan koreksi",
  report: "Kerusakan dilaporkan",
  progress: "Catatan perkembangan",
};
export function History({ events }: { events: Event[] }) {
  if (!events.length) return <Blank title="Belum ada kegiatan" description="Kegiatan akan tercatat di sini." />;
  return (
    <ol className="relative flex flex-col gap-5 border-l pl-5">
      {events.map((e) => (
        <li key={e.id} className="relative">
          <span className="absolute top-1.5 -left-[25px] size-2.5 rounded-full border-2 border-background bg-foreground" />
          <p className="font-medium">{eventLabels[e.event] || e.event}</p>
          <p className="text-xs text-muted-foreground">
            {e.actor_name} · {dateLabel(e.created_at)}
          </p>
          {e.notes && <p className="mt-1 text-sm whitespace-pre-wrap">{e.notes}</p>}
        </li>
      ))}
    </ol>
  );
}

/**
 * A picture opened in a popup over the page, so looking at it never leaves the form or list behind.
 * The full-size link is there for reading small print.
 */
export function ImagePreview({
  image,
  onClose,
}: {
  image?: { url: string; title: string; description?: string };
  onClose: () => void;
}) {
  return (
    <Dialog open={!!image} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-5xl">
        <DialogHeader>
          <DialogTitle>{image?.title}</DialogTitle>
          <DialogDescription>{image?.description || "Pratinjau gambar."}</DialogDescription>
        </DialogHeader>
        {image && <img src={image.url} alt={image.title} className="max-h-[70vh] w-full rounded-md border bg-muted object-contain" />}
        <DialogFooter>
          {image && (
            <Button variant="outline" asChild>
              <a href={image.url} target="_blank" rel="noopener" className="notAJAX">
                <ExternalLink data-icon="inline-start" />
                Buka ukuran penuh
              </a>
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Thumbnail grid; a thumbnail opens its photo in a popup. With onToggle, each saved photo gets a
 * remove/undo control; removal applies on save.
 */
export function Photos({
  photos,
  removed = [],
  onToggle,
  size = "md",
}: {
  photos: Photo[];
  removed?: string[];
  onToggle?: (p: Photo) => void;
  size?: "sm" | "md";
}) {
  const [viewing, setViewing] = useState<string>();
  if (!photos.length) return null;
  const box = size === "sm" ? "size-20" : "size-28";
  return (
    <div className="flex flex-wrap gap-2">
      <ImagePreview image={viewing ? { url: viewing, title: "Foto" } : undefined} onClose={() => setViewing(undefined)} />
      {photos.map((p, index) => {
        const gone = removed.includes(String(p.id));
        return (
          <div key={p.id} className={cn("group relative overflow-hidden rounded-lg border bg-muted", box)}>
            {p.url ? (
              <button type="button" className="block size-full" aria-label={`Lihat foto ${index + 1}`} onClick={() => setViewing(p.url!)}>
                <img
                  src={p.url}
                  alt="Foto"
                  loading="lazy"
                  className={cn("size-full object-cover", gone && "opacity-30 grayscale")}
                />
              </button>
            ) : (
              <p className="p-2 text-xs text-muted-foreground">Foto lama tidak dapat dibaca.</p>
            )}
            {onToggle && (
              <Button
                type="button"
                size="icon-xs"
                variant={gone ? "secondary" : "destructive"}
                className="absolute top-1 right-1 bg-background/90 shadow-sm"
                aria-label={gone ? "Batalkan hapus foto" : "Hapus foto"}
                onClick={() => onToggle(p)}
              >
                {gone ? <Undo2 /> : <X />}
              </Button>
            )}
          </div>
        );
      })}
    </div>
  );
}

export function Upload({
  files,
  onChange,
  count = 0,
  label = "Foto bukti",
  hint = "JPEG, PNG, atau WebP. Maksimal 5 foto, masing-masing 2 MB.",
}: {
  files: File[];
  onChange: (f: File[]) => void;
  count?: number;
  label?: string;
  hint?: string;
}) {
  const id = useId();
  const cameraId = useId();
  const [error, setError] = useState("");
  const [previews, setPreviews] = useState<string[]>([]);
  useEffect(() => {
    const urls = files.map((f) => URL.createObjectURL(f));
    setPreviews(urls);
    return () => urls.forEach((u) => URL.revokeObjectURL(u));
  }, [files]);
  const full = count + files.length >= 5;
  const handle = (picked: File[]) => {
    if (count + files.length + picked.length > 5) {
      setError("Maksimal lima foto.");
      return;
    }
    if (picked.some((f) => f.size > 2 * 1024 * 1024 || !["image/jpeg", "image/png", "image/webp"].includes(f.type))) {
      setError("Gunakan JPEG, PNG, atau WebP maksimal 2 MB.");
      return;
    }
    setError("");
    onChange([...files, ...picked]);
  };
  return (
    <Field data-invalid={!!error}>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <div className="flex flex-wrap gap-2">
        {files.map((f, i) => (
          <div key={i} className="relative size-20 overflow-hidden rounded-lg border bg-muted">
            <img src={previews[i]} alt={f.name} className="size-full object-cover" />
            <Badge variant="info" className="absolute bottom-1 left-1">
              Baru
            </Badge>
            <Button
              type="button"
              size="icon-xs"
              variant="destructive"
              className="absolute top-1 right-1 bg-background/90 shadow-sm"
              aria-label={`Batalkan ${f.name}`}
              onClick={() => onChange(files.filter((_, n) => n !== i))}
            >
              <X />
            </Button>
          </div>
        ))}
        {!full && (
          <div className="flex h-20 flex-col justify-center gap-1 rounded-lg border border-dashed px-2">
            <Button type="button" size="sm" variant="ghost" asChild>
              <label htmlFor={id} className="cursor-pointer">
                <ImagePlus data-icon="inline-start" />
                Pilih foto
              </label>
            </Button>
            <Button type="button" size="sm" variant="ghost" asChild className="sm:hidden">
              <label htmlFor={cameraId} className="cursor-pointer">
                <Camera data-icon="inline-start" />
                Kamera
              </label>
            </Button>
          </div>
        )}
      </div>
      <Input
        id={cameraId}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        capture="environment"
        className="sr-only"
        tabIndex={-1}
        onChange={(e) => {
          const picked = Array.from(e.target.files || []);
          e.target.value = "";
          handle(picked);
        }}
      />
      <Input
        id={id}
        type="file"
        multiple
        accept="image/jpeg,image/png,image/webp"
        className="sr-only"
        onChange={(e) => {
          const picked = Array.from(e.target.files || []);
          e.target.value = "";
          handle(picked);
        }}
      />
      <FieldDescription>{hint}</FieldDescription>
      {error && <FieldError>{error}</FieldError>}
    </Field>
  );
}

export function StatCard({
  label,
  value,
  icon: Icon,
  tone = "default",
  hint,
  active,
  onClick,
}: {
  label: string;
  value: ReactNode;
  icon?: LucideIcon;
  tone?: "default" | "success" | "warning" | "destructive" | "info";
  hint?: ReactNode;
  active?: boolean;
  onClick?: () => void;
}) {
  const toneClass = {
    default: "text-foreground",
    success: "text-success",
    warning: "text-warning",
    destructive: "text-destructive",
    info: "text-info",
  }[tone];
  const body = (
    <>
      <div className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
        <span>{label}</span>
        {Icon && <Icon className={cn("size-4", toneClass)} />}
      </div>
      <p className={cn("text-2xl font-semibold tabular-nums", toneClass)}>{value}</p>
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </>
  );
  const base = "flex flex-col gap-1 rounded-xl border bg-card p-4 text-left";
  return onClick ? (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={cn(base, "cursor-pointer transition-colors hover:bg-muted/50", active && "ring-2 ring-ring")}
    >
      {body}
    </button>
  ) : (
    <div className={base}>{body}</div>
  );
}

/** Sticky bar for page-level save actions so they stay reachable on long forms. */
export function ActionBar({ children, status }: { children: ReactNode; status?: ReactNode }) {
  return (
    <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-3 border-t bg-background/95 px-4 py-3 backdrop-blur md:-mx-8 md:px-8">
      {status && (
        <p className="mr-auto text-sm text-muted-foreground" role="status">
          {status}
        </p>
      )}
      <div className="ml-auto flex flex-wrap items-center gap-2">{children}</div>
    </div>
  );
}
