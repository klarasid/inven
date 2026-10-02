import { useId, useRef, useState } from "react";
import { toast } from "sonner";
import {
  Plus,
  Building2,
  Package,
  Pencil,
  Trash2,
  ImageOff,
  TriangleAlert,
  MapPin,
  Wand2,
  CircleCheck,
  CircleAlert,
  CircleX,
  Boxes,
  FileText,
  ChevronDown,
  QrCode,
  Tags,
  X,
} from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Card, CardContent } from "./components/ui/card";
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from "./components/ui/table";
import { FieldGroup, Field, FieldLabel, FieldDescription } from "./components/ui/field";
import { ToggleGroup, ToggleGroupItem } from "./components/ui/toggle-group";
import { Separator } from "./components/ui/separator";
import { Input } from "./components/ui/input";
import { Checkbox } from "./components/ui/checkbox";
import { Tabs, TabsList, TabsTrigger } from "./components/ui/tabs";
import { RoomAreasTab, RoomPlansTab } from "./room-areas";
import { LabelDialog } from "./labels";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "./components/ui/dialog";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetFooter } from "./components/ui/sheet";
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuGroup,
} from "./components/ui/dropdown-menu";
import { useData, useWorkspace } from "./context";
import { money, url } from "./api";
import {
  PageHeader,
  Panel,
  SearchBox,
  LibraryFilter,
  Pager,
  Loading,
  ErrorBox,
  Blank,
  TextField,
  Choice,
  Upload,
  Photos,
  Actions,
  Condition,
  conditions,
  StatCard,
  ActionBar,
  previewPdf,
} from "./shared";
import type { Page, Values, Photo, Route } from "./types";

/** An item's categories as the server keeps them: comma-separated codes. */
const categoryCodes = (category: unknown) => String(category || "").split(",").filter(Boolean);

/**
 * Item categories for Rekap Sarpras, with types suggested for the chosen ones. An item may have
 * several categories, e.g. a computer that is also multimedia equipment.
 */
export function CategoryFields({
  category,
  type,
  onCategory,
  onType,
  categoryHint = "Dipakai di Rekap Sarpras. Satu barang bisa memiliki lebih dari satu kategori, misalnya komputer yang juga perangkat multimedia.",
  typeHint = "Contoh: Proyektor, APAR, Toilet. Barang sejenis dihitung satu jenis.",
}: {
  category: unknown;
  type: unknown;
  onCategory: (v: string) => void;
  onType: (v: string) => void;
  categoryHint?: string;
  typeHint?: string;
}) {
  const w = useWorkspace();
  const lists = w.options.sarpras;
  const id = useId();
  const chosen = categoryCodes(category);
  const suggestions = [...new Set(chosen.flatMap((code) => lists.types[code] || []))];
  return (
    <FieldGroup>
      <Field>
        <FieldLabel>Kategori</FieldLabel>
        <div className="grid gap-2 sm:grid-cols-2">
          {Object.entries(lists.categories).map(([code, label]) => (
            <label key={code} className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={chosen.includes(code)}
                onCheckedChange={(on) => onCategory((on ? [...chosen, code] : chosen.filter((c) => c !== code)).join(","))}
              />
              {label}
            </label>
          ))}
        </div>
        <FieldDescription>{categoryHint}</FieldDescription>
      </Field>
      <Field className="sm:max-w-sm">
        <FieldLabel htmlFor={id}>Jenis</FieldLabel>
        <Input id={id} list={`${id}-types`} maxLength={100} value={String(type ?? "")} onChange={(e) => onType(e.target.value)} />
        <datalist id={`${id}-types`}>
          {suggestions.map((t) => (
            <option key={t} value={t} />
          ))}
        </datalist>
        <FieldDescription>{typeHint}</FieldDescription>
      </Field>
    </FieldGroup>
  );
}

/** What a room's page shows: its items, the areas inside it, and its floor plans. */
const roomTabs = { items: "Barang", areas: "Area", plans: "Denah" } as const;

const home: Route = { view: "inventory" };
const homeCrumb = { label: "Ruangan & Barang", route: home };

export function InventoryList() {
  const w = useWorkspace();
  return w.route.room ? <RoomItems /> : <RoomGrid />;
}

function DeleteDialog({
  target,
  kind,
  onClose,
  onDeleted,
}: {
  target?: Values;
  kind: "item" | "room";
  onClose: () => void;
  onDeleted: () => void;
}) {
  const w = useWorkspace();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  return (
    <Dialog
      open={!!target}
      onOpenChange={(open) => {
        if (!busy && !open) {
          setError("");
          onClose();
        }
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Hapus {kind === "item" ? "barang" : "ruangan"}?</DialogTitle>
          <DialogDescription>
            {kind === "item"
              ? `${target?.item_name || "Barang"} beserta fotonya akan dihapus permanen.`
              : `Ruangan ${target?.room_name || ""} dan seluruh barang di dalamnya akan dihapus. Jadwal dihentikan; riwayat pemeriksaan tetap disimpan.`}
          </DialogDescription>
        </DialogHeader>
        <ErrorBox message={error} />
        <DialogFooter>
          <Button variant="outline" disabled={busy} onClick={onClose}>
            Batal
          </Button>
          <Button
            variant="destructive"
            disabled={busy}
            onClick={async () => {
              if (!target) return;
              setBusy(true);
              try {
                await w.mutate(
                  {
                    form_action: kind === "item" ? "delete_item" : "delete_location",
                    record_id: target.id,
                    location_id: kind === "item" ? target.location_id : "",
                  },
                  undefined,
                  true,
                );
                toast.success(kind === "item" ? "Barang dihapus." : "Ruangan dihapus.");
                onDeleted();
              } catch (e) {
                setError((e as Error).message);
              } finally {
                setBusy(false);
              }
            }}
          >
            <Trash2 data-icon="inline-start" />
            Hapus {kind === "item" ? "barang" : "ruangan"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function RoomGrid() {
  const w = useWorkspace();
  const { data, error, loading } = useData<Page<Values>>("rooms", w.route);
  const [selected, setSelected] = useState<Values>();
  const open = (id: unknown) => w.go({ view: "inventory", room: String(id) });
  return (
    <>
      <PageHeader
        title="Ruangan & Barang"
        description="Pilih ruangan untuk melihat, menambah, dan mencetak kartu inventaris barangnya."
        actions={
          w.config.write && (
            <Button onClick={() => w.go({ view: "room-edit" })}>
              <Plus data-icon="inline-start" />
              Tambah ruangan
            </Button>
          )
        }
      />
      <div className="flex flex-col gap-2 sm:flex-row">
        <SearchBox placeholder="Cari nama atau kode ruangan…" />
        <LibraryFilter />
      </div>
      <ErrorBox message={error} />
      {loading ? (
        <Loading />
      ) : data && !data.rows.length ? (
        <Blank
          icon={Building2}
          title={w.route.q || w.route.library ? "Ruangan tidak ditemukan" : "Belum ada ruangan"}
          description={
            w.route.q || w.route.library
              ? "Ubah kata kunci atau filter perpustakaan."
              : "Tambahkan ruangan pertama untuk mulai mencatat inventaris."
          }
        >
          {w.config.write && !w.route.q && (
            <Button onClick={() => w.go({ view: "room-edit" })}>
              <Plus data-icon="inline-start" />
              Tambah ruangan
            </Button>
          )}
        </Blank>
      ) : (
        data && (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {data.rows.map((r) => (
              <Card
                key={String(r.id)}
                role="link"
                tabIndex={0}
                onClick={() => open(r.id)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") open(r.id);
                }}
                className="cursor-pointer gap-3 py-4 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
              >
                <CardContent className="flex flex-col gap-3 px-4">
                  <div className="flex items-start justify-between gap-2">
                    <div className="flex min-w-0 items-start gap-3">
                      <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                        <Building2 className="size-4 text-muted-foreground" />
                      </span>
                      <div className="min-w-0">
                        <p className="truncate font-medium">{r.room_name}</p>
                        <p className="flex items-center gap-1 truncate text-xs text-muted-foreground">
                          <MapPin className="size-3 shrink-0" />
                          {r.library_name || "Lokasi belum ditentukan"}
                        </p>
                      </div>
                    </div>
                    {w.config.write && (
                      <Actions
                        items={[
                          {
                            label: "Ubah ruangan",
                            icon: Pencil,
                            run: () => w.go({ view: "room-edit", record: String(r.id) }),
                          },
                          { label: "Hapus ruangan", icon: Trash2, destructive: true, run: () => setSelected(r) },
                        ]}
                      />
                    )}
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">
                      <Package />
                      {r.item_count} barang
                    </Badge>
                    {Number(r.damaged_count) > 0 && (
                      <Badge variant="warning">
                        <TriangleAlert />
                        {r.damaged_count} perlu perhatian
                      </Badge>
                    )}
                    {r.location_code && (
                      <span className="ml-auto text-xs text-muted-foreground tabular-nums">{r.location_code}</span>
                    )}
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )
      )}
      {data && <Pager {...data} onChange={(page) => w.go({ ...w.route, page }, true)} />}
      <DeleteDialog
        kind="room"
        target={selected}
        onClose={() => setSelected(undefined)}
        onDeleted={() => {
          setSelected(undefined);
          w.refresh();
        }}
      />
    </>
  );
}

/** KIR print menu: the classic form stays available next to the modern layout. */
function KirMenu({ room }: { room: string }) {
  const { config } = useWorkspace();
  const target = (template: string) =>
    url(config.inventory, { workspace: "", action: "print_pdf", location_id: room, template });
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline">
          <FileText data-icon="inline-start" />
          Cetak KIR
          <ChevronDown data-icon="inline-end" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-64">
        <DropdownMenuLabel>Pilih template Kartu Inventaris Ruangan</DropdownMenuLabel>
        <DropdownMenuGroup>
          <DropdownMenuItem
            className="flex flex-col items-start gap-0.5"
            onSelect={() => previewPdf(config, target("classic"), "Kartu Inventaris Ruangan · Klasik")}
          >
            <span className="font-medium">Klasik</span>
            <span className="text-xs text-muted-foreground">Format lama, huruf serif, hitam-putih.</span>
          </DropdownMenuItem>
          <DropdownMenuItem
            className="flex flex-col items-start gap-0.5"
            onSelect={() => previewPdf(config, target("modern"), "Kartu Inventaris Ruangan · Modern")}
          >
            <span className="font-medium">Modern</span>
            <span className="text-xs text-muted-foreground">Baris jumlah, nomor kolom, dan nomor halaman.</span>
          </DropdownMenuItem>
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function RoomItems() {
  const w = useWorkspace();
  const room = String(w.route.room);
  const { item, tab: routeTab, ...params } = w.route;
  const tab = String(routeTab || "") in roomTabs ? (routeTab as keyof typeof roomTabs) : "items";
  const { data, error, loading } = useData<Page<Values>>("items", params);
  const [selected, setSelected] = useState<Values>();
  const [deleteRoom, setDeleteRoom] = useState<Values>();
  // Items ticked for label printing, kept across pages of this room: id -> name.
  const [picked, setPicked] = useState<Record<string, string>>({});
  const [labelItems, setLabelItems] = useState<{ id: string; name: string }[] | null | undefined>(undefined);
  const pickedCount = Object.keys(picked).length;
  const [categorize, setCategorize] = useState(false);
  const toggle = (id: string, name: string) =>
    setPicked((p) => {
      const { [id]: had, ...rest } = p;
      return had === undefined ? { ...p, [id]: name } : rest;
    });
  const pageIds = (data?.rows || []).map((r) => String(r.id));
  const allOnPage = pageIds.length > 0 && pageIds.every((id) => picked[id] !== undefined);
  const info = data?.room;
  const counts = data?.conditions || {};
  const total = Object.values(counts).reduce((n, v) => n + Number(v), 0);
  const condition = String(w.route.condition || "");
  const filter = (value: string) => w.go({ ...w.route, condition: value, page: 1, item: undefined }, true);
  const openItem = (id: unknown) => w.go({ ...w.route, item: String(id) }, true);
  return (
    <>
      <PageHeader
        crumbs={[homeCrumb]}
        title={String(info?.room_name || "Memuat ruangan…")}
        meta={
          info && (
            <>
              <Badge variant="outline">
                <MapPin />
                {String(info.library_name || "Lokasi belum ditentukan")}
              </Badge>
              {info.location_code && <Badge variant="outline">Kode {String(info.location_code)}</Badge>}
            </>
          )
        }
        actions={
          <>
            <Button variant="outline" onClick={() => setLabelItems(null)}>
              <QrCode data-icon="inline-start" />
              Cetak label
            </Button>
            <KirMenu room={room} />
            {w.config.write && (
              <>
                <Button onClick={() => w.go({ view: "item-edit", room })}>
                  <Plus data-icon="inline-start" />
                  Tambah barang
                </Button>
                <Actions
                  label="Tindakan ruangan"
                  items={[
                    { label: "Ubah ruangan", icon: Pencil, run: () => w.go({ view: "room-edit", record: room }) },
                    {
                      label: "Hapus ruangan",
                      icon: Trash2,
                      destructive: true,
                      run: () => info && setDeleteRoom(info),
                    },
                  ]}
                />
              </>
            )}
          </>
        }
      />
      <Tabs value={tab} onValueChange={(v) => w.go({ ...w.route, tab: v === "items" ? undefined : v, item: undefined }, true)}>
        <TabsList variant="line" className="w-full justify-start border-b">
          {Object.entries(roomTabs).map(([value, label]) => (
            <TabsTrigger key={value} value={value} className="flex-none">
              {label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>
      {tab === "areas" && <RoomAreasTab room={room} />}
      {tab === "plans" && <RoomPlansTab room={room} />}
      {tab === "items" && (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <StatCard label="Semua barang" value={total} icon={Boxes} active={!condition} onClick={() => filter("")} />
            <StatCard
              label="Baik"
              value={Number(counts.B || 0)}
              icon={CircleCheck}
              tone="success"
              active={condition === "B"}
              onClick={() => filter(condition === "B" ? "" : "B")}
            />
            <StatCard
              label="Kurang baik"
              value={Number(counts.KB || 0)}
              icon={CircleAlert}
              tone="warning"
              active={condition === "KB"}
              onClick={() => filter(condition === "KB" ? "" : "KB")}
            />
            <StatCard
              label="Rusak berat"
              value={Number(counts.RB || 0)}
              icon={CircleX}
              tone="destructive"
              active={condition === "RB"}
              onClick={() => filter(condition === "RB" ? "" : "RB")}
            />
          </div>
          <SearchBox placeholder="Cari nama, kode, atau merk barang…" />
          {pickedCount > 0 && (
            <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2">
              <span className="text-sm font-medium">{pickedCount} barang dipilih</span>
              <Button
                size="sm"
                className="ml-auto"
                onClick={() => setLabelItems(Object.entries(picked).map(([id, name]) => ({ id, name })))}
              >
                <QrCode data-icon="inline-start" />
                Cetak label terpilih
              </Button>
              {w.config.write && (
                <Button size="sm" variant="outline" onClick={() => setCategorize(true)}>
                  <Tags data-icon="inline-start" />
                  Beri kategori
                </Button>
              )}
              <Button size="sm" variant="ghost" onClick={() => setPicked({})}>
                <X data-icon="inline-start" />
                Batalkan pilihan
              </Button>
            </div>
          )}
          <ErrorBox message={error} />
          {loading && !data ? (
            <Loading />
          ) : data && !data.rows.length ? (
            <Blank
              icon={Package}
              title={w.route.q || condition ? "Barang tidak ditemukan" : "Belum ada barang"}
              description={
                w.route.q || condition
                  ? "Ubah kata kunci atau pilih kondisi lain."
                  : "Tambahkan barang pertama di ruangan ini."
              }
            >
              {w.config.write && !w.route.q && !condition && (
                <Button onClick={() => w.go({ view: "item-edit", room })}>
                  <Plus data-icon="inline-start" />
                  Tambah barang
                </Button>
              )}
            </Blank>
          ) : (
            data && (
              <div className="overflow-hidden rounded-xl border">
                <Table>
                  <TableHeader className="bg-muted/50">
                    <TableRow>
                      <TableHead className="w-10">
                        <Checkbox
                          aria-label="Pilih semua barang di halaman ini"
                          checked={allOnPage}
                          onCheckedChange={(on) =>
                            setPicked((p) => {
                              const next = { ...p };
                              for (const r of data.rows) {
                                if (on) next[String(r.id)] = String(r.item_name);
                                else delete next[String(r.id)];
                              }
                              return next;
                            })
                          }
                        />
                      </TableHead>
                      <TableHead className="w-14">
                        <span className="sr-only">Foto</span>
                      </TableHead>
                      <TableHead>Barang</TableHead>
                      <TableHead className="hidden md:table-cell">Merk / model</TableHead>
                      <TableHead>Kondisi</TableHead>
                      <TableHead className="hidden sm:table-cell">Jumlah / register</TableHead>
                      <TableHead className="w-12">
                        <span className="sr-only">Tindakan</span>
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {data.rows.map((r) => (
                      <TableRow
                        key={String(r.id)}
                        className="cursor-pointer"
                        data-state={picked[String(r.id)] !== undefined ? "selected" : undefined}
                        onClick={() => openItem(r.id)}
                      >
                        <TableCell onClick={(e) => e.stopPropagation()}>
                          <Checkbox
                            aria-label={`Pilih ${r.item_name}`}
                            checked={picked[String(r.id)] !== undefined}
                            onCheckedChange={() => toggle(String(r.id), String(r.item_name))}
                          />
                        </TableCell>
                        <TableCell>
                          <div className="flex size-10 items-center justify-center overflow-hidden rounded-md border bg-muted">
                            {r.photo_url ? (
                              <img src={String(r.photo_url)} alt="" loading="lazy" className="size-full object-cover" />
                            ) : (
                              <ImageOff className="size-4 text-muted-foreground" />
                            )}
                          </div>
                        </TableCell>
                        <TableCell className="max-w-64 whitespace-normal">
                          <button
                            type="button"
                            className="text-left font-medium hover:underline"
                            onClick={(e) => {
                              e.stopPropagation();
                              openItem(r.id);
                            }}
                          >
                            {r.item_name}
                          </button>
                          <p className="text-xs text-muted-foreground tabular-nums">{r.item_code || "Tanpa kode"}</p>
                        </TableCell>
                        <TableCell className="hidden text-muted-foreground md:table-cell">{r.brand_model || "—"}</TableCell>
                        <TableCell>
                          <Condition value={r.item_condition} />
                        </TableCell>
                        <TableCell className="hidden sm:table-cell">{r.quantity_register || "—"}</TableCell>
                        <TableCell>
                          {w.config.write && (
                            <Actions
                              items={[
                                {
                                  label: "Ubah",
                                  icon: Pencil,
                                  run: () => w.go({ view: "item-edit", record: String(r.id), room }),
                                },
                                { label: "Hapus", icon: Trash2, destructive: true, run: () => setSelected(r) },
                              ]}
                            />
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )
          )}
          {data && <Pager {...data} onChange={(page) => w.go({ ...w.route, page }, true)} />}
        </>
      )}
      <ItemSheet
        record={w.route.item ? String(w.route.item) : undefined}
        onClose={() => w.go({ ...w.route, item: undefined }, true)}
        onDelete={(r) => setSelected(r)}
      />
      <DeleteDialog
        kind="item"
        target={selected}
        onClose={() => setSelected(undefined)}
        onDeleted={() => {
          setSelected(undefined);
          w.go({ ...w.route, item: undefined }, true);
          w.refresh();
        }}
      />
      <CategorizeDialog
        open={categorize}
        ids={Object.keys(picked)}
        onClose={() => setCategorize(false)}
        onDone={() => {
          setCategorize(false);
          setPicked({});
          w.refresh();
        }}
      />
      <LabelDialog
        open={labelItems !== undefined}
        onOpenChange={(open) => !open && setLabelItems(undefined)}
        room={room}
        items={labelItems || undefined}
        total={Object.values(counts).reduce((n, v) => n + Number(v), 0)}
      />
      <DeleteDialog
        kind="room"
        target={deleteRoom}
        onClose={() => setDeleteRoom(undefined)}
        onDeleted={() => {
          setDeleteRoom(undefined);
          w.go(home, true);
          w.refresh();
        }}
      />
    </>
  );
}

function CategorizeDialog({
  open,
  ids,
  onClose,
  onDone,
}: {
  open: boolean;
  ids: string[];
  onClose: () => void;
  onDone: () => void;
}) {
  const w = useWorkspace();
  const [category, setCategory] = useState("");
  const [type, setType] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  async function save() {
    setBusy(true);
    setError("");
    try {
      const reply = await w.mutate({ form_action: "categorize_items", itemID: ids, category, item_type: type }, undefined, true);
      toast.success(reply.message || "Kategori tersimpan.");
      setType("");
      onDone();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <Dialog open={open} onOpenChange={(o) => !o && !busy && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Beri kategori {ids.length} barang</DialogTitle>
          <DialogDescription>Kategori dan jenis dipakai untuk menghitung Rekap Sarpras.</DialogDescription>
        </DialogHeader>
        <ErrorBox message={error} />
        <CategoryFields
          category={category}
          type={type}
          onCategory={setCategory}
          onType={setType}
          categoryHint="Pilihan ini menggantikan kategori barang yang dipilih. Boleh lebih dari satu; tanpa pilihan, kategorinya dikosongkan."
          typeHint="Kosongkan untuk mempertahankan jenis masing-masing barang."
        />
        <DialogFooter>
          <Button variant="outline" disabled={busy} onClick={onClose}>
            Batal
          </Button>
          <Button disabled={busy} onClick={save}>
            {busy ? "Menyimpan…" : "Simpan"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function ItemSheet({
  record,
  onClose,
  onDelete,
}: {
  record?: string;
  onClose: () => void;
  onDelete: (r: Values) => void;
}) {
  return (
    <Sheet
      open={!!record}
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
    >
      <SheetContent className="w-full gap-0 sm:max-w-lg">{record && <ItemSheetBody record={record} onDelete={onDelete} />}</SheetContent>
    </Sheet>
  );
}

function ItemSheetBody({ record, onDelete }: { record: string; onDelete: (r: Values) => void }) {
  const w = useWorkspace();
  const { data, error } = useData<{ record: Values; photos: Photo[] }>("item", { record });
  const item = data?.record;
  const [label, setLabel] = useState(false);
  return (
    <>
      <SheetHeader className="border-b">
        <SheetTitle>{item ? String(item.item_name) : "Memuat barang…"}</SheetTitle>
        <SheetDescription className="flex items-center gap-2">
          {item && (
            <>
              <span className="tabular-nums">{String(item.item_code || "Tanpa kode")}</span>
              <Condition value={item.item_condition} />
            </>
          )}
        </SheetDescription>
      </SheetHeader>
      <div className="flex-1 overflow-y-auto p-4">
        <ErrorBox message={error} />
        {data ? <ItemDetails record={data.record} photos={data.photos} /> : !error && <Loading />}
      </div>
      {item && (
        <LabelDialog
          open={label}
          onOpenChange={setLabel}
          room={String(item.location_id)}
          items={[{ id: String(item.id), name: String(item.item_name) }]}
          total={1}
        />
      )}
      {item && w.config.write && (
        <SheetFooter className="flex-row flex-wrap border-t">
          <Button
            variant="outline"
            className="flex-1"
            onClick={() => w.go({ view: "report", room: String(item.location_id), item_id: String(item.id) })}
          >
            <TriangleAlert data-icon="inline-start" />
            Laporkan kerusakan
          </Button>
          <Button variant="outline" onClick={() => setLabel(true)}>
            <QrCode data-icon="inline-start" />
            Label
          </Button>
          <Button
            variant="outline"
            onClick={() => w.go({ view: "item-edit", record: String(item.id), room: String(item.location_id) })}
          >
            <Pencil data-icon="inline-start" />
            Ubah
          </Button>
          <Button variant="destructive" onClick={() => onDelete(item)}>
            <Trash2 data-icon="inline-start" />
            Hapus
          </Button>
        </SheetFooter>
      )}
    </>
  );
}

function ItemDetails({ record, photos }: { record: Values; photos: Photo[] }) {
  const w = useWorkspace();
  const fields: [string, unknown][] = [
    ["Ruangan", w.options.rooms.find((r) => String(r.id) === String(record.location_id))?.room_name],
    [
      "Kategori",
      [categoryCodes(record.category).map((code) => w.options.sarpras.categories[code]).filter(Boolean).join(", "), record.item_type]
        .filter(Boolean)
        .join(" · "),
    ],
    ["Merk / model", record.brand_model],
    ["Nomor seri", record.serial_number],
    ["Jumlah / register", record.quantity_register],
    ["Ukuran", record.item_size],
    ["Bahan", record.material],
    ["Tahun perolehan", record.acquisition_year],
    ["Harga perolehan", Number(record.acquisition_price) ? money(record.acquisition_price) : ""],
  ];
  return (
    <div className="flex flex-col gap-5">
      <section className="flex flex-col gap-2">
        <h3 className="text-sm font-medium">Foto ({photos.length})</h3>
        {photos.length ? (
          <Photos photos={photos} />
        ) : (
          <p className="text-sm text-muted-foreground">Belum ada foto barang.</p>
        )}
      </section>
      <Separator />
      <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
        {fields.map(([label, value]) => (
          <div key={label}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{String(value || "—")}</dd>
          </div>
        ))}
      </dl>
      {record.notes && (
        <>
          <Separator />
          <div>
            <p className="text-xs text-muted-foreground">Keterangan</p>
            <p className="text-sm whitespace-pre-wrap">{String(record.notes)}</p>
          </div>
        </>
      )}
    </div>
  );
}

export function InventoryForm() {
  const w = useWorkspace();
  return w.route.record ? <ExistingInventory /> : <InventoryEditor />;
}

function ExistingInventory() {
  const w = useWorkspace();
  const item = w.route.view.startsWith("item");
  const { data, error } = useData<{ record: Values; photos: Photo[] }>(item ? "item" : "room", {
    record: w.route.record,
  });
  if (error) return <ErrorBox message={error} />;
  if (!data) return <Loading />;
  if (w.route.view === "item-detail" || (item && !w.config.write))
    return (
      <>
        <PageHeader
          crumbs={[
            homeCrumb,
            {
              label: w.options.rooms.find((r) => String(r.id) === String(data.record.location_id))?.room_name || "Ruangan",
              route: { view: "inventory", room: String(data.record.location_id) },
            },
          ]}
          title={String(data.record.item_name)}
          meta={<Condition value={data.record.item_condition} />}
          actions={
            w.config.write && (
              <Button onClick={() => w.go({ ...w.route, view: "item-edit" })}>
                <Pencil data-icon="inline-start" />
                Ubah barang
              </Button>
            )
          }
        />
        <Card>
          <CardContent>
            <ItemDetails record={data.record} photos={data.photos} />
          </CardContent>
        </Card>
      </>
    );
  return <InventoryEditor record={data.record} photos={data.photos} />;
}

function InventoryEditor({ record, photos: initialPhotos = [] }: { record?: Values; photos?: Photo[] }) {
  const w = useWorkspace();
  const item = w.route.view.startsWith("item");
  const [values, setValues] = useState<Values>(
    record || {
      location_id: w.route.room || "",
      item_name: "",
      item_condition: "B",
      acquisition_price: "0",
      room_name: "",
      unit_name: "PERPUSTAKAAN",
      manager_title: "Pengurus Barang Inventaris",
    },
  );
  const [photos, setPhotos] = useState(initialPhotos);
  const [files, setFiles] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const lock = useRef(false);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const token = useRef(
    Array.from(crypto.getRandomValues(new Uint8Array(32)), (x) => x.toString(16).padStart(2, "0")).join(""),
  );
  const [confirmCode, setConfirmCode] = useState(false);
  const [removePhoto, setRemovePhoto] = useState<Photo>();
  const update = (key: string, value: string) => {
    setValues((v) => ({ ...v, [key]: value }));
    w.dirty(true);
  };
  const roomName = w.options.rooms.find((r) => String(r.id) === String(values.location_id))?.room_name;

  async function code() {
    setBusy(true);
    setError("");
    try {
      const response = await w.mutate(
        { form_action: "reserve_item_code", location_id: values.location_id, code_form_token: token.current },
        undefined,
        true,
      );
      update("item_code", response.code!);
      setConfirmCode(false);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function roomCode() {
    setBusy(true);
    setError("");
    try {
      const response = await w.mutate({ form_action: "suggest_location_code", slims_location_id: values.slims_location_id }, undefined, true);
      update("location_code", response.code!);
      setConfirmCode(false);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function save() {
    if (lock.current) return;
    const errors: Record<string, string> = {};
    if (item) {
      if (!values.item_name) errors.item_name = "Isi nama barang.";
      if (!values.location_id) errors.location_id = "Pilih ruangan.";
    } else if (!values.room_name) errors.room_name = "Isi nama ruangan.";
    setErrors(errors);
    if (Object.keys(errors).length) return;
    lock.current = true;
    setBusy(true);
    setError("");
    try {
      const body = new FormData();
      files.forEach((f) => body.append("item_photos[]", f));
      const reply = await w.mutate(
        {
          ...values,
          form_action: item ? "save_item" : "save_location",
          record_id: record?.id || 0,
          expected_updated_at: record?.updated_at,
          code_form_token: token.current,
        },
        body,
        true,
      );
      w.dirty(false);
      toast.success(item ? "Barang tersimpan." : "Ruangan tersimpan.");
      const target = item ? String(reply.location_id || values.location_id) : String(reply.record || record?.id || "");
      w.go(target ? { view: "inventory", room: target } : home, true);
      w.refresh();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      lock.current = false;
      setBusy(false);
    }
  }
  const text = (key: string, label: string, opts: { type?: string; required?: boolean; description?: string; placeholder?: string } = {}) => (
    <TextField
      key={key}
      label={label}
      value={values[key]}
      type={opts.type}
      required={opts.required}
      description={opts.description}
      placeholder={opts.placeholder}
      error={errors[key]}
      onChange={(v) => update(key, v)}
    />
  );
  // Trail follows where the record lives: Ruangan & Barang › <ruangan> › <halaman ini>.
  const parentRoom = item ? values.location_id && roomName : record && record.room_name;
  const parentId = item ? values.location_id : record?.id;
  const crumbs = [
    homeCrumb,
    ...(parentRoom ? [{ label: String(parentRoom), route: { view: "inventory", room: String(parentId) } }] : []),
  ];

  return (
    <>
      <PageHeader
        crumbs={crumbs}
        title={`${record ? "Ubah" : "Tambah"} ${item ? "barang" : "ruangan"}`}
        description={
          item
            ? "Hanya nama barang yang wajib. Lengkapi isian lain bila datanya tersedia."
            : "Identitas ruangan dan data yang dicetak pada Kartu Inventaris Ruangan (KIR)."
        }
      />
      <ErrorBox message={error} />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save();
        }}
      >
        <fieldset disabled={busy} className="flex min-w-0 flex-col gap-6">
          {item ? (
            <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
              <div className="flex flex-col gap-6">
                <Panel title="Informasi utama">
                  <FieldGroup>
                    <Choice
                      label="Ruangan"
                      required
                      value={values.location_id}
                      error={errors.location_id}
                      onChange={(v) => update("location_id", v)}
                      items={w.options.rooms.map((x) => ({ value: x.id, label: x.room_name }))}
                    />
                    {text("item_name", "Nama barang", { required: true, placeholder: "Contoh: Rak buku besi 5 susun" })}
                    <Field data-invalid={!!errors.item_code}>
                      <FieldLabel htmlFor="item-code">Kode barang</FieldLabel>
                      <div className="flex gap-2">
                        <Input
                          id="item-code"
                          className="tabular-nums"
                          value={String(values.item_code ?? "")}
                          aria-invalid={!!errors.item_code}
                          onChange={(e) => update("item_code", e.target.value)}
                        />
                        <Button
                          type="button"
                          variant="outline"
                          disabled={!values.location_id}
                          onClick={() => (values.item_code ? setConfirmCode(true) : code())}
                        >
                          <Wand2 data-icon="inline-start" />
                          Buat otomatis
                        </Button>
                      </div>
                      <FieldDescription>
                        {errors.item_code || "Kosongkan atau klik Buat otomatis untuk nomor berikutnya dari perpustakaan ruangan."}
                      </FieldDescription>
                    </Field>
                    <Field>
                      <FieldLabel>Kondisi barang</FieldLabel>
                      <ToggleGroup
                        type="single"
                        variant="outline"
                        value={String(values.item_condition || "B")}
                        onValueChange={(v) => v && update("item_condition", v)}
                        className="w-full"
                      >
                        {Object.entries(conditions).map(([value, label]) => (
                          <ToggleGroupItem key={value} value={value} className="flex-1">
                            {label}
                          </ToggleGroupItem>
                        ))}
                      </ToggleGroup>
                    </Field>
                    <CategoryFields
                      category={values.category}
                      type={values.item_type}
                      onCategory={(v) => update("category", v)}
                      onType={(v) => update("item_type", v)}
                    />
                    <FieldGroup className="grid sm:grid-cols-2">
                      {text("brand_model", "Merk / model")}
                      {text("quantity_register", "Jumlah / register")}
                    </FieldGroup>
                  </FieldGroup>
                </Panel>
                <Panel title="Spesifikasi dan perolehan" description="Opsional. Dicetak pada Kartu Inventaris Ruangan.">
                  <FieldGroup className="grid sm:grid-cols-2">
                    {text("serial_number", "Nomor seri pabrik")}
                    {text("item_size", "Ukuran")}
                    {text("material", "Bahan")}
                    {text("acquisition_year", "Tahun pembuatan / pembelian", { type: "number" })}
                    {text("acquisition_price", "Harga perolehan (Rp)", { type: "number" })}
                  </FieldGroup>
                  <div className="mt-5">
                    <TextField label="Keterangan" value={values.notes} onChange={(v) => update("notes", v)} multiline maxLength={5000} />
                  </div>
                </Panel>
              </div>
              <Panel title="Foto barang" description="Opsional, maksimal 5 foto." className="self-start">
                <FieldGroup>
                  {photos.length > 0 && <Photos photos={photos} size="sm" onToggle={(p) => setRemovePhoto(p)} />}
                  <Upload
                    label={photos.length ? "Tambah foto" : "Unggah foto"}
                    files={files}
                    count={photos.length}
                    onChange={(f) => {
                      setFiles(f);
                      w.dirty(true);
                    }}
                    hint="JPEG, PNG, atau WebP, masing-masing maksimal 2 MB."
                  />
                </FieldGroup>
              </Panel>
            </div>
          ) : (
            <div className="grid gap-6 lg:grid-cols-2">
              <Panel title="Identitas ruangan">
                <FieldGroup>
                  {text("room_name", "Nama ruangan", { required: true, placeholder: "Contoh: Ruang Baca Lantai 2" })}
                  <Choice
                    label="Lokasi perpustakaan"
                    value={values.slims_location_id}
                    onChange={(v) => update("slims_location_id", v)}
                    items={w.options.libraries.map((x) => ({ value: x.location_id, label: x.location_name }))}
                    description="Dipakai untuk pembuatan kode barang otomatis dan filter."
                  />
                  <Field data-invalid={!!errors.location_code}>
                    <FieldLabel htmlFor="room-code">Kode ruangan</FieldLabel>
                    <div className="flex gap-2">
                      <Input
                        id="room-code"
                        className="tabular-nums"
                        placeholder="Contoh: 00-RUANG-001"
                        value={String(values.location_code ?? "")}
                        onChange={(e) => update("location_code", e.target.value)}
                      />
                      <Button
                        type="button"
                        variant="outline"
                        disabled={!values.slims_location_id}
                        title={values.slims_location_id ? undefined : "Pilih lokasi perpustakaan dulu"}
                        onClick={() => (values.location_code ? setConfirmCode(true) : roomCode())}
                      >
                        <Wand2 data-icon="inline-start" />
                        Buat otomatis
                      </Button>
                    </div>
                    <FieldDescription>
                      Pola {"{kode lokasi}"}-RUANG-{"{nomor}"}, mis. 00-RUANG-001. Dicetak sebagai No. kode lokasi pada KIR.
                    </FieldDescription>
                  </Field>
                </FieldGroup>
              </Panel>
              <Panel title="Wilayah dan unit" description="Tercetak di kepala KIR.">
                <FieldGroup className="grid sm:grid-cols-2">
                  {text("province", "Provinsi")}
                  {text("regency_city", "Kabupaten / kota")}
                  {text("unit_name", "Unit")}
                  {text("work_unit", "Satuan kerja")}
                </FieldGroup>
              </Panel>
              <Panel
                title="Luas ruang"
                description="Dipakai di Rekap Sarpras. Area di dalam ruangan dan denahnya dicatat di halaman ruangan, pada tab Area dan Denah."
                className="lg:col-span-2"
              >
                <div className="sm:max-w-xs">{text("area_m2", "Luas (m²)", { type: "number", placeholder: "Contoh: 120" })}</div>
              </Panel>
              <Panel title="Penandatangan KIR" description="Tercetak di bagian tanda tangan kartu." className="lg:col-span-2">
                <FieldGroup>
                  <div className="sm:max-w-sm">{text("signature_city", "Kota penandatanganan")}</div>
                  <div className="grid gap-6 sm:grid-cols-2">
                    <FieldGroup>
                      <p className="text-sm font-medium">Yang mengetahui</p>
                      {text("knowing_title", "Jabatan")}
                      {text("knowing_name", "Nama")}
                      {text("knowing_identity", "NIP / identitas")}
                    </FieldGroup>
                    <FieldGroup>
                      <p className="text-sm font-medium">Pengurus barang</p>
                      {text("manager_title", "Jabatan")}
                      {text("manager_name", "Nama")}
                      {text("manager_identity", "NIP / identitas")}
                    </FieldGroup>
                  </div>
                </FieldGroup>
              </Panel>
            </div>
          )}
          <ActionBar status={busy ? "Menyimpan…" : undefined}>
            <Button type="button" variant="outline" onClick={w.back}>
              Batal
            </Button>
            <Button type="submit">{busy ? "Menyimpan…" : item ? "Simpan barang" : "Simpan ruangan"}</Button>
          </ActionBar>
        </fieldset>
      </form>
      <Dialog open={confirmCode} onOpenChange={setConfirmCode}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Ganti kode {item ? "barang" : "ruangan"}?</DialogTitle>
            <DialogDescription>
              Kode saat ini akan diganti dengan nomor berikutnya dari lokasi perpustakaan yang dipilih.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmCode(false)}>
              Batal
            </Button>
            <Button disabled={busy} onClick={item ? code : roomCode}>
              Buat kode baru
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <Dialog
        open={!!removePhoto}
        onOpenChange={(open) => {
          if (!open) setRemovePhoto(undefined);
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Hapus foto barang?</DialogTitle>
            <DialogDescription>Foto langsung dihapus. Isian barang lainnya tetap tersedia.</DialogDescription>
          </DialogHeader>
          {removePhoto?.url && <img src={removePhoto.url} alt="" className="max-h-48 rounded-lg border object-contain" />}
          <DialogFooter>
            <Button variant="outline" onClick={() => setRemovePhoto(undefined)}>
              Batal
            </Button>
            <Button
              variant="destructive"
              disabled={busy}
              onClick={async () => {
                if (!removePhoto) return;
                setBusy(true);
                try {
                  await w.mutate(
                    { form_action: "delete_photo", item_id: record?.id, photo_id: removePhoto.id },
                    undefined,
                    true,
                  );
                  setPhotos(photos.filter((p) => p.id !== removePhoto.id));
                  setRemovePhoto(undefined);
                  toast.success("Foto dihapus.");
                } catch (e) {
                  setError((e as Error).message);
                } finally {
                  setBusy(false);
                }
              }}
            >
              Hapus foto
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
