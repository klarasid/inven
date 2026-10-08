import { useState } from "react";
import { Field, FieldLabel, FieldDescription } from "./components/ui/field";
import { ToggleGroup, ToggleGroupItem } from "./components/ui/toggle-group";
import { Checkbox } from "./components/ui/checkbox";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from "./components/ui/dialog";
import { useWorkspace } from "./context";
import { url } from "./api";
import { Pdf } from "./shared";

const groupings = [
  { value: "category", title: "Kategori", text: "Perabot, komputer, sarana keamanan, dan lainnya." },
  { value: "area", title: "Area", text: "Area koleksi, area baca, gazebo, dan lainnya." },
];
const photoChoices = [
  { value: "first", title: "Satu foto", text: "Foto pertama tiap barang." },
  { value: "all", title: "Semua foto", text: "Termasuk foto pemanfaatannya." },
];

/** Where SLiMS prints one of the lists or sheets (documents.php), in the style picked from the print menu. */
export const printAddress = (inventory: string, action: string, params: Record<string, unknown> = {}) => (style: string) =>
  url(inventory, { workspace: "", action, ...params, style });

/** Daftar area dan fasilitas: which group of areas, then the print menu for its style. */
export function AreaListDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const w = useWorkspace();
  const [group, setGroup] = useState("all");
  const library = String(w.route.library || "");
  const groups = [["all", "Semua"], ...Object.entries(w.options.sarpras.areaGroups)];
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Cetak daftar area dan fasilitas</DialogTitle>
          <DialogDescription>
            {library
              ? "Area di ruangan lokasi perpustakaan yang sedang disaring, dengan luas ruangan dan fotonya."
              : "Area di semua ruangan, dengan luas ruangan dan fotonya. Untuk satu lokasi, saring dulu daftar ruangan."}
          </DialogDescription>
        </DialogHeader>
        <Field>
          <FieldLabel>Kelompok area</FieldLabel>
          <ToggleGroup
            type="single"
            variant="outline"
            className="grid w-full grid-cols-2"
            value={group}
            onValueChange={(v) => v && setGroup(v)}
          >
            {groups.map(([value, label]) => (
              <ToggleGroupItem key={value} value={value} className="h-auto px-3 py-2">
                {label}
              </ToggleGroupItem>
            ))}
          </ToggleGroup>
        </Field>
        <DialogFooter>
          <Pdf
            label="Cetak PDF"
            href={printAddress(w.config.inventory, "print_areas", { group: group === "all" ? "" : group, library })}
          />
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** Daftar inventaris berfoto: what it lists and how, then the print menu for its style. */
export function CatalogDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const w = useWorkspace();
  const [group, setGroup] = useState("category");
  const [photos, setPhotos] = useState("first");
  const [categories, setCategories] = useState<string[]>([]);
  const library = String(w.route.library || "");
  const target = printAddress(w.config.inventory, "print_catalog", { group, photos, categories: categories.join(","), library });
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Cetak daftar inventaris berfoto</DialogTitle>
          <DialogDescription>
            {library
              ? "Barang di lokasi perpustakaan yang sedang disaring, masing-masing dengan fotonya."
              : "Barang di semua lokasi perpustakaan, masing-masing dengan fotonya. Untuk satu lokasi, saring dulu daftar ruangan."}
          </DialogDescription>
        </DialogHeader>
        <Field>
          <FieldLabel>Kelompokkan menurut</FieldLabel>
          <ToggleGroup
            type="single"
            variant="outline"
            className="grid w-full grid-cols-2"
            value={group}
            onValueChange={(v) => v && setGroup(v)}
          >
            {groupings.map((x) => (
              <ToggleGroupItem key={x.value} value={x.value} className="h-auto flex-col items-start gap-0 px-3 py-2 text-left">
                <span className="font-medium">{x.title}</span>
                <span className="text-xs font-normal whitespace-normal text-muted-foreground">{x.text}</span>
              </ToggleGroupItem>
            ))}
          </ToggleGroup>
        </Field>
        <Field>
          <FieldLabel>Foto</FieldLabel>
          <ToggleGroup
            type="single"
            variant="outline"
            className="grid w-full grid-cols-2"
            value={photos}
            onValueChange={(v) => v && setPhotos(v)}
          >
            {photoChoices.map((x) => (
              <ToggleGroupItem key={x.value} value={x.value} className="h-auto flex-col items-start gap-0 px-3 py-2 text-left">
                <span className="font-medium">{x.title}</span>
                <span className="text-xs font-normal whitespace-normal text-muted-foreground">{x.text}</span>
              </ToggleGroupItem>
            ))}
          </ToggleGroup>
        </Field>
        <Field>
          <FieldLabel>Kategori</FieldLabel>
          <div className="grid gap-2 sm:grid-cols-2">
            {Object.entries(w.options.sarpras.categories).map(([code, label]) => (
              <label key={code} className="flex items-center gap-2 text-sm">
                <Checkbox
                  checked={categories.includes(code)}
                  onCheckedChange={(on) => setCategories((chosen) => (on ? [...chosen, code] : chosen.filter((c) => c !== code)))}
                />
                {label}
              </label>
            ))}
          </div>
          <FieldDescription>
            Kosongkan untuk semua barang. Satu dokumen memuat paling banyak 500 barang dan 500 foto; pilih kategori bila lebih.
          </FieldDescription>
        </Field>
        <DialogFooter>
          <Pdf label="Cetak PDF" href={target} />
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
