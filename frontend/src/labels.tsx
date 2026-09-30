import { useState } from "react";
import { QrCode, Printer } from "lucide-react";
import { Button } from "./components/ui/button";
import { Field, FieldLabel, FieldDescription } from "./components/ui/field";
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
import { url } from "./api";
import { previewPdf } from "./shared";

/** Keep in sync with LabelSheet::PRESETS. */
const presets = [
  { value: "a4-3x8", title: "A4 · 3×8", text: "24 label 64×34 mm", grid: [3, 8] },
  { value: "a4-2x7", title: "A4 · 2×7", text: "14 label 99×38 mm", grid: [2, 7] },
  { value: "thermal-50x30", title: "Stiker 50×30", text: "Printer label, 1 per halaman", grid: [1, 1] },
];

/**
 * Label print dialog. `items` limits printing to those items; without it every item in the room is printed.
 */
export function LabelDialog({
  open,
  onOpenChange,
  room,
  items,
  total,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  room: string;
  items?: { id: string; name: string }[];
  total: number;
}) {
  const { config } = useWorkspace();
  const [preset, setPreset] = useState("a4-3x8");
  const [start, setStart] = useState(1);
  const [target, setTarget] = useState("public");
  const p = presets.find((x) => x.value === preset)!;
  const [cols, rows] = p.grid;
  const perSheet = cols * rows;
  const count = items ? items.length : total;
  const sheets = perSheet > 1 ? Math.ceil((start - 1 + count) / perSheet) : count;
  const href = url(config.inventory, {
    workspace: "",
    action: "print_labels",
    location_id: room,
    ids: items?.map((i) => i.id).join(",") || undefined,
    preset,
    start: perSheet > 1 ? start : 1,
    target,
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <QrCode className="size-5" />
            Cetak label barang
          </DialogTitle>
          <DialogDescription>
            {items?.length === 1
              ? `Label untuk ${items[0].name}.`
              : items
                ? `Label untuk ${count} barang terpilih.`
                : `Label untuk semua ${count} barang di ruangan ini.`}{" "}

          </DialogDescription>
        </DialogHeader>
        <Field>
          <FieldLabel>Ukuran label</FieldLabel>
          <ToggleGroup
            type="single"
            variant="outline"
            className="grid w-full grid-cols-3"
            value={preset}
            onValueChange={(v) => {
              if (!v) return;
              setPreset(v);
              setStart(1);
            }}
          >
            {presets.map((x) => (
              <ToggleGroupItem key={x.value} value={x.value} className="h-auto flex-col gap-0 py-2">
                <span className="font-medium">{x.title}</span>
                <span className="text-xs font-normal text-muted-foreground">{x.text}</span>
              </ToggleGroupItem>
            ))}
          </ToggleGroup>
        </Field>
        <Field>
          <FieldLabel>Isi QR code</FieldLabel>
          <ToggleGroup
            type="single"
            variant="outline"
            className="grid w-full grid-cols-2"
            value={target}
            onValueChange={(v) => v && setTarget(v)}
          >
            <ToggleGroupItem value="public" className="h-auto flex-col items-start gap-0 px-3 py-2 text-left">
              <span className="font-medium">Halaman publik</span>
              <span className="text-xs font-normal whitespace-normal text-muted-foreground">
                Siapa pun dapat memindai dan melihat info barang, tanpa login.
              </span>
            </ToggleGroupItem>
            <ToggleGroupItem value="staff" className="h-auto flex-col items-start gap-0 px-3 py-2 text-left">
              <span className="font-medium">Khusus petugas</span>
              <span className="text-xs font-normal whitespace-normal text-muted-foreground">
                Membuka pengelolaan barang, wajib login SLiMS.
              </span>
            </ToggleGroupItem>
          </ToggleGroup>
        </Field>
        {perSheet > 1 && (
          <Field>
            <FieldLabel>Mulai dari label ke-{start}</FieldLabel>
            <FieldDescription>Klik kotak label pertama yang masih kosong pada lembar Anda.</FieldDescription>
            <div
              className="mx-auto grid w-40 gap-0.5 rounded-md border bg-muted/40 p-1.5"
              style={{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }}
              role="radiogroup"
              aria-label="Posisi label pertama"
            >
              {Array.from({ length: perSheet }, (_, i) => (
                <button
                  key={i}
                  type="button"
                  role="radio"
                  aria-checked={start === i + 1}
                  aria-label={`Label ke-${i + 1}`}
                  onClick={() => setStart(i + 1)}
                  className={cn(
                    "h-4 cursor-pointer rounded-[2px] border transition-colors",
                    i + 1 < start && "border-dashed bg-transparent",
                    i + 1 === start && "border-primary bg-primary",
                    i + 1 > start && "bg-background hover:bg-muted",
                  )}
                />
              ))}
            </div>
          </Field>
        )}
        <p className="text-sm text-muted-foreground">
          {count} label · {sheets} {perSheet > 1 ? "lembar" : "halaman"}. Cetak dengan skala 100% (tanpa "sesuaikan ke halaman").
        </p>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button
            onClick={() => {
              onOpenChange(false);
              previewPdf(config, href, `Label barang · ${p.title}`);
            }}
          >
            <Printer data-icon="inline-start" />
            Tampilkan label
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
