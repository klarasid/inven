import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from "react";
import { toast } from "sonner";
import { FileUp, Stamp, Trash2, Eye } from "lucide-react";
import { Button } from "./components/ui/button";
import { Input } from "./components/ui/input";
import { Badge } from "./components/ui/badge";
import { Switch } from "./components/ui/switch";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectGroup, SelectLabel, SelectSeparator } from "./components/ui/select";
import { Select as SelectPrimitive } from "radix-ui";
import { Check } from "lucide-react";
import { Field, FieldLabel, FieldDescription } from "./components/ui/field";
import { Tabs, TabsList, TabsTrigger } from "./components/ui/tabs";
import { ToggleGroup, ToggleGroupItem } from "./components/ui/toggle-group";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "./components/ui/dialog";
import { cn } from "./lib/utils";
import { useWorkspace } from "./context";
import { read, url } from "./api";
import { ErrorBox, Loading, TextField, previewPdf } from "./shared";

export type Letterhead = {
  id: string;
  name: string;
  pages: number;
  sizes: [number, number][];
  first: { top: number; right: number; bottom: number; left: number };
  next: { top: number; bottom: number };
  body: "latex" | "iso";
  first_only?: boolean;
  font?: string;
};
type Font = { value: string; name: string; group: string; hint: string; preview: string };
type Data = { templates: Letterhead[]; bodies: Record<string, string>; maxBytes: number; fonts: Font[] };
type Edge = "top" | "right" | "bottom" | "left";

// PDF.js ships with the print-preview viewer; load it from there on demand instead of bundling it.
type PdfJs = {
  GlobalWorkerOptions: { workerSrc: string };
  getDocument: (src: { data: Uint8Array }) => { promise: Promise<PdfDoc> };
};
type PdfDoc = { numPages: number; getPage: (n: number) => Promise<PdfPage> };
type PdfPage = {
  getViewport: (o: { scale: number }) => { width: number; height: number };
  render: (o: Record<string, unknown>) => { promise: Promise<void> };
};
let pdfjs: Promise<PdfJs> | undefined;
function loadPdfJs(viewer: string): Promise<PdfJs> {
  pdfjs ??= (new Function("u", "return import(u)") as (u: string) => Promise<PdfJs>)(new URL("pdf.min.js", new URL(viewer, location.href)).href).then(
    (lib) => {
      lib.GlobalWorkerOptions.workerSrc = new URL("pdf.worker.min.js", new URL(viewer, location.href)).href;
      return lib;
    },
  );
  return pdfjs;
}

/**
 * Font picker in the manner of a word processor: every name is an image of itself set in that font
 * (rendered from the same TTF mPDF prints with), grouped by kind, with a hint beside it in the list.
 */
function FontPicker({ fonts, value, onChange, viewer, bodyLabel }: { fonts: Font[]; value: string; onChange: (v: string) => void; viewer: string; bodyLabel: string }) {
  const src = (preview: string) => new URL("../" + preview, new URL(viewer, location.href)).href;
  const groups = [...new Set(fonts.map((f) => f.group))];
  const item = (f: Font) => (
    <SelectPrimitive.Item
      key={f.value}
      value={f.value}
      className="relative flex w-full cursor-default items-center gap-3 rounded-md py-1.5 pr-8 pl-2 outline-hidden select-none focus:bg-accent data-[state=checked]:bg-accent/60"
    >
      <SelectPrimitive.ItemText>
        <img src={src(f.preview)} alt={f.name} className="h-[18px] w-auto max-w-none" />
      </SelectPrimitive.ItemText>
      {f.hint && <span className="ml-auto text-xs whitespace-nowrap text-muted-foreground">{f.hint}</span>}
      <span className="pointer-events-none absolute right-2 flex size-4 items-center justify-center">
        <SelectPrimitive.ItemIndicator>
          <Check className="size-4" />
        </SelectPrimitive.ItemIndicator>
      </span>
    </SelectPrimitive.Item>
  );
  return (
    <Select value={value || "__default"} onValueChange={(v) => onChange(v === "__default" ? "" : v)}>
      <SelectTrigger className="h-10 w-full" aria-label="Font isi">
        <SelectValue />
      </SelectTrigger>
      <SelectContent className="max-h-80">
        <SelectGroup>
          <SelectPrimitive.Item
            value="__default"
            className="relative flex w-full cursor-default items-center rounded-md py-1.5 pr-8 pl-2 text-sm outline-hidden select-none focus:bg-accent"
          >
            <SelectPrimitive.ItemText>Bawaan gaya isi ({bodyLabel})</SelectPrimitive.ItemText>
          </SelectPrimitive.Item>
        </SelectGroup>
        {groups.map((g) => (
          <SelectGroup key={g}>
            <SelectSeparator />
            <SelectLabel>{g}</SelectLabel>
            {fonts.filter((f) => f.group === g).map(item)}
          </SelectGroup>
        ))}
      </SelectContent>
    </Select>
  );
}

/** Template page with the content area drawn on top; its edges can be dragged. */
function AreaEditor({
  doc,
  page,
  size,
  area,
  onChange,
}: {
  doc: PdfDoc | null;
  page: number;
  size: [number, number];
  area: { top: number; right: number; bottom: number; left: number };
  onChange: (edge: Edge, mm: number) => void;
}) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const box = useRef<HTMLDivElement>(null);
  const [w, h] = size;
  useEffect(() => {
    let cancelled = false;
    if (!doc) {
      // Plain paper: clear any previously drawn template page.
      canvas.current?.getContext("2d")?.clearRect(0, 0, canvas.current.width, canvas.current.height);
      return;
    }
    doc.getPage(page).then((p) => {
      if (cancelled || !canvas.current) return;
      const scale = (440 * (window.devicePixelRatio || 1)) / p.getViewport({ scale: 1 }).width;
      const viewport = p.getViewport({ scale });
      canvas.current.width = Math.floor(viewport.width);
      canvas.current.height = Math.floor(viewport.height);
      p.render({ canvas: canvas.current, canvasContext: canvas.current.getContext("2d"), viewport });
    });
    return () => {
      cancelled = true;
    };
  }, [doc, page]);

  const drag = (edge: Edge) => (e: ReactPointerEvent) => {
    e.preventDefault();
    const rect = box.current!.getBoundingClientRect();
    const move = (ev: PointerEvent) => {
      const x = ((ev.clientX - rect.left) / rect.width) * w;
      const y = ((ev.clientY - rect.top) / rect.height) * h;
      const value = edge === "top" ? y : edge === "bottom" ? h - y : edge === "left" ? x : w - x;
      onChange(edge, Math.round(Math.max(0, value) * 2) / 2);
    };
    const up = () => {
      window.removeEventListener("pointermove", move);
      window.removeEventListener("pointerup", up);
    };
    window.addEventListener("pointermove", move);
    window.addEventListener("pointerup", up);
  };
  const pct = (mm: number, total: number) => `${(mm / total) * 100}%`;
  const handle = "absolute bg-primary/80 hover:bg-primary touch-none";
  return (
    <div ref={box} className="relative w-full overflow-hidden rounded-md border bg-white select-none" style={{ aspectRatio: `${w} / ${h}` }}>
      <canvas ref={canvas} className="absolute inset-0 size-full" />
      <div
        className="absolute border-2 border-dashed border-primary"
        style={{
          top: pct(area.top, h),
          bottom: pct(area.bottom, h),
          left: pct(area.left, w),
          right: pct(area.right, w),
          boxShadow: "0 0 0 9999px rgba(0,0,0,.28)",
        }}
      >
        <span className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 rounded bg-background/90 px-2 py-0.5 text-xs font-medium">
          Area konten
        </span>
        <div className={cn(handle, "-top-1.5 left-1/4 h-3 w-1/2 cursor-ns-resize rounded-full")} onPointerDown={drag("top")} aria-label="Tarik tepi atas" />
        <div className={cn(handle, "-bottom-1.5 left-1/4 h-3 w-1/2 cursor-ns-resize rounded-full")} onPointerDown={drag("bottom")} aria-label="Tarik tepi bawah" />
        <div className={cn(handle, "top-1/4 -left-1.5 h-1/2 w-3 cursor-ew-resize rounded-full")} onPointerDown={drag("left")} aria-label="Tarik tepi kiri" />
        <div className={cn(handle, "top-1/4 -right-1.5 h-1/2 w-3 cursor-ew-resize rounded-full")} onPointerDown={drag("right")} aria-label="Tarik tepi kanan" />
      </div>
    </div>
  );
}

function Editor({
  template,
  bodies,
  fonts,
  onSaved,
  onDeleted,
}: {
  template: Letterhead;
  bodies: Record<string, string>;
  fonts: Font[];
  onSaved: (t: Letterhead) => void;
  onDeleted: () => void;
}) {
  const w = useWorkspace();
  const [values, setValues] = useState(template);
  const [tab, setTab] = useState<"first" | "next">("first");
  const [doc, setDoc] = useState<PdfDoc>();
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const saved = JSON.stringify(values) === JSON.stringify(template);

  useEffect(() => {
    let cancelled = false;
    setDoc(undefined);
    (async () => {
      try {
        const lib = await loadPdfJs(w.config.viewer!);
        const response = await fetch(url(w.config.watch, { tab: "letterhead", template: template.id }), { credentials: "same-origin" });
        if (!response.ok || !(response.headers.get("content-type") || "").includes("pdf")) throw new Error("Berkas template tidak dapat dimuat. Muat ulang halaman atau masuk kembali ke SLiMS.");
        const loaded = await lib.getDocument({ data: new Uint8Array(await response.arrayBuffer()) }).promise;
        if (!cancelled) setDoc(loaded);
      } catch (e) {
        if (!cancelled) setError((e as Error).message);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [template.id]);

  const area = tab === "first" ? values.first : { ...values.first, top: values.next.top, bottom: values.next.bottom };
  const setEdge = (edge: Edge, mm: number) =>
    setValues((v) =>
      tab === "next" && (edge === "top" || edge === "bottom")
        ? { ...v, next: { ...v.next, [edge]: mm } }
        : { ...v, first: { ...v.first, [edge]: mm } },
    );
  const [pw, ph] = values.sizes[0];

  async function save() {
    setBusy(true);
    setError("");
    try {
      const reply = await w.mutate({
        watch_action: "letterhead_save",
        id: values.id,
        name: values.name,
        body: values.body,
        first_only: values.first_only ? "1" : "",
        font: values.font || "",
        first: values.first,
        next: values.next,
      });
      onSaved(reply.data as Letterhead);
      toast.success("Template kop tersimpan.");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function remove() {
    setBusy(true);
    try {
      await w.mutate({ watch_action: "letterhead_delete", id: values.id });
      toast.success("Template kop dihapus.");
      onDeleted();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
      setConfirmDelete(false);
    }
  }
  const mm = (label: string, value: number, change: (n: number) => void) => (
    <TextField label={label} type="number" min="0" value={value} onChange={(v) => change(Number(v))} />
  );

  return (
    <div className="flex min-w-0 flex-col gap-4">
      <ErrorBox message={error} />
      <div className="grid gap-4 md:grid-cols-[1fr_260px]">
        <div className="flex min-w-0 flex-col gap-2">
          <Tabs value={tab} onValueChange={(v) => setTab(v as "first" | "next")}>
            <TabsList>
              <TabsTrigger value="first">Halaman pertama</TabsTrigger>
              <TabsTrigger value="next">Halaman berikutnya</TabsTrigger>
            </TabsList>
          </Tabs>
          {doc ? (
            <AreaEditor
              doc={tab === "next" && values.first_only ? null : doc}
              page={tab === "next" && values.pages > 1 ? 2 : 1}
              size={values.sizes[tab === "next" && values.pages > 1 ? 1 : 0] || values.sizes[0]}
              area={area}
              onChange={setEdge}
            />
          ) : (
            !error && <Loading />
          )}
          <p className="text-xs text-muted-foreground">
            {values.first_only
              ? "Kop hanya di halaman pertama; halaman berikutnya dicetak di kertas polos."
              : values.pages > 1
                ? "Halaman 1 template dipakai untuk halaman pertama laporan, halaman 2 untuk halaman berikutnya."
                : "Template satu halaman dipakai untuk semua halaman laporan."}{" "}
            Tarik tepi kotak atau isi angka (mm).
          </p>
        </div>
        <div className="flex flex-col gap-4">
          <TextField label="Nama template" required value={values.name} maxLength={80} onChange={(name) => setValues({ ...values, name })} />
          <Field orientation="horizontal" className="items-start rounded-lg border p-3">
            <Switch
              id={`first-only-${values.id}`}
              checked={!!values.first_only}
              onCheckedChange={(first_only) => setValues({ ...values, first_only })}
            />
            <div className="flex flex-col gap-0.5">
              <FieldLabel htmlFor={`first-only-${values.id}`}>Kop hanya di halaman pertama</FieldLabel>
              <FieldDescription>Halaman kedua dan seterusnya tanpa kop dan footer, seperti surat dinas.</FieldDescription>
            </div>
          </Field>
          <Field>
            <FieldLabel>Gaya isi</FieldLabel>
            <ToggleGroup
              type="single"
              variant="outline"
              className="w-full"
              value={values.body}
              onValueChange={(v) => v && setValues({ ...values, body: v as Letterhead["body"] })}
            >
              {Object.entries(bodies).map(([value, label]) => (
                <ToggleGroupItem key={value} value={value} className="flex-1">
                  {label}
                </ToggleGroupItem>
              ))}
            </ToggleGroup>
          </Field>
          <Field>
            <FieldLabel>Font isi</FieldLabel>
            <FontPicker
              fonts={fonts}
              value={values.font || ""}
              onChange={(font) => setValues({ ...values, font })}
              viewer={w.config.viewer!}
              bodyLabel={values.body === "iso" ? "FreeSans" : "Computer Modern"}
            />
            <FieldDescription>Berlaku untuk seluruh isi laporan, termasuk tabel.</FieldDescription>
          </Field>
          <Field>
            <FieldLabel>Area konten {tab === "first" ? "halaman pertama" : "halaman berikutnya"} (mm)</FieldLabel>
            <div className="grid grid-cols-2 gap-2">
              {tab === "first" ? (
                <>
                  {mm("Atas", values.first.top, (n) => setValues({ ...values, first: { ...values.first, top: n } }))}
                  {mm("Bawah", values.first.bottom, (n) => setValues({ ...values, first: { ...values.first, bottom: n } }))}
                </>
              ) : (
                <>
                  {mm("Atas", values.next.top, (n) => setValues({ ...values, next: { ...values.next, top: n } }))}
                  {mm("Bawah", values.next.bottom, (n) => setValues({ ...values, next: { ...values.next, bottom: n } }))}
                </>
              )}
              {mm("Kiri", values.first.left, (n) => setValues({ ...values, first: { ...values.first, left: n } }))}
              {mm("Kanan", values.first.right, (n) => setValues({ ...values, first: { ...values.first, right: n } }))}
            </div>
            <FieldDescription>
              Kertas {pw} × {ph} mm. Kiri dan kanan berlaku untuk semua halaman.
            </FieldDescription>
          </Field>
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-2 border-t pt-4">
        {confirmDelete ? (
          <>
            <span className="text-sm">Hapus template ini?</span>
            <Button size="sm" variant="destructive" disabled={busy} onClick={remove}>
              Ya, hapus
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setConfirmDelete(false)}>
              Batal
            </Button>
          </>
        ) : (
          <Button size="sm" variant="ghost" className="text-destructive" onClick={() => setConfirmDelete(true)}>
            <Trash2 data-icon="inline-start" />
            Hapus
          </Button>
        )}
        <div className="ml-auto flex gap-2">
          <Button
            variant="outline"
            disabled={!saved}
            title={saved ? undefined : "Simpan dulu untuk mencoba"}
            onClick={() => {
              const from = w.config.today.slice(0, 8) + "01";
              previewPdf(w.config, url(w.config.watch, { tab: "pdf", from, to: w.config.today, style: `kop:${values.id}` }), `Coba cetak · ${values.name}`);
            }}
          >
            <Eye data-icon="inline-start" />
            Coba cetak
          </Button>
          <Button disabled={busy || saved} onClick={save}>
            {busy ? "Menyimpan…" : "Simpan"}
          </Button>
        </div>
      </div>
    </div>
  );
}

export function LetterheadSettings() {
  const w = useWorkspace();
  const [open, setOpen] = useState(false);
  const [data, setData] = useState<Data>();
  const [selected, setSelected] = useState<string>();
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [name, setName] = useState("");
  const file = useRef<HTMLInputElement>(null);

  const load = (select?: string) =>
    read<Data>(w.config, "letterheads")
      .then((d) => {
        setData(d);
        setSelected((s) => select ?? (d.templates.some((t) => t.id === s) ? s : d.templates[0]?.id));
      })
      .catch((e) => setError(e.message));
  useEffect(() => {
    if (open) {
      setError("");
      load();
    }
  }, [open]);

  async function upload(picked: File) {
    if (data && picked.size > data.maxBytes) {
      setError("Berkas template maksimal 5 MB.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      const body = new FormData();
      body.append("template", picked);
      const reply = await w.mutate({ watch_action: "letterhead_upload", name: name || picked.name.replace(/\.pdf$/i, "") }, body);
      setName("");
      toast.success("Template diunggah. Atur area kontennya.");
      await load((reply.data as Letterhead).id);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
      if (file.current) file.current.value = "";
    }
  }

  if (!w.config.write || !w.config.viewer) return null;
  const current = data?.templates.find((t) => t.id === selected);
  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        <Stamp data-icon="inline-start" />
        Template kop
      </Button>
      <Dialog open={open} onOpenChange={(v) => !busy && setOpen(v)}>
        <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl">
          <DialogHeader>
            <DialogTitle>Template kop institusi</DialogTitle>
            <DialogDescription>
              Unggah PDF berisi kop surat dan footer institusi, lalu tentukan area yang diisi konten laporan. Template muncul sebagai pilihan di menu Cetak PDF.
            </DialogDescription>
          </DialogHeader>
          <ErrorBox message={error} />
          {!data ? (
            !error && <Loading />
          ) : (
            <div className="grid gap-5 md:grid-cols-[220px_1fr]">
              <aside className="flex flex-col gap-3">
                <div className="flex flex-col gap-1">
                  {data.templates.map((t) => (
                    <Button
                      key={t.id}
                      variant={t.id === selected ? "secondary" : "ghost"}
                      className="h-auto justify-start py-2 text-left whitespace-normal"
                      onClick={() => setSelected(t.id)}
                    >
                      <span className="flex min-w-0 flex-col items-start">
                        <span className="font-medium">{t.name}</span>
                        <span className="text-xs text-muted-foreground">
                          {t.sizes[0][0]}×{t.sizes[0][1]} mm · {t.first_only ? "kop hlm. 1" : `${t.pages} hlm`} · {data.bodies[t.body]}
                        </span>
                      </span>
                    </Button>
                  ))}
                  {!data.templates.length && <p className="text-sm text-muted-foreground">Belum ada template.</p>}
                </div>
                <div className="flex flex-col gap-2 rounded-lg border border-dashed p-3">
                  <Input placeholder="Nama template (opsional)" value={name} maxLength={80} onChange={(e) => setName(e.target.value)} disabled={busy} />
                  <Button variant="outline" disabled={busy} onClick={() => file.current?.click()}>
                    <FileUp data-icon="inline-start" />
                    {busy ? "Mengunggah…" : "Unggah PDF"}
                  </Button>
                  <input
                    ref={file}
                    type="file"
                    accept="application/pdf,.pdf"
                    className="sr-only"
                    onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])}
                  />
                  <p className="text-xs text-muted-foreground">
                    PDF maksimal 5 MB. Halaman 1 untuk halaman pertama, halaman 2 (opsional) untuk halaman berikutnya.
                  </p>
                </div>
              </aside>
              {current ? (
                <Editor
                  key={current.id}
                  template={current}
                  bodies={data.bodies}
                  fonts={data.fonts}
                  onSaved={(t) => setData({ ...data, templates: data.templates.map((x) => (x.id === t.id ? t : x)) })}
                  onDeleted={() => load()}
                />
              ) : (
                <div className="flex min-h-60 items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground">
                  <Badge variant="outline">Unggah template untuk mulai</Badge>
                </div>
              )}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" disabled={busy} onClick={() => setOpen(false)}>
              Tutup
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
