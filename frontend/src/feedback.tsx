import { useEffect, useId, useMemo, useRef, useState } from "react";
import { toast } from "sonner";
import { ExternalLink, ImagePlus, Info, MessageSquareText, Paperclip, X } from "lucide-react";
import { Badge } from "./components/ui/badge";
import { Button } from "./components/ui/button";
import { Checkbox } from "./components/ui/checkbox";
import { Field, FieldError, FieldGroup, FieldLabel } from "./components/ui/field";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "./components/ui/sheet";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "./components/ui/tabs";
import { Textarea } from "./components/ui/textarea";
import { ToggleGroup, ToggleGroupItem } from "./components/ui/toggle-group";
import { useWorkspace } from "./context";
import { dateLabel, read } from "./api";
import { ErrorBox } from "./shared";
import { SCREENSHOT_MAX_INPUT_BYTES, SCREENSHOT_TARGET_BYTES, SCREENSHOT_TYPES, shrinkScreenshot } from "./screenshot";

type Piece = {
  id: number;
  kind: { key: string; label: string };
  message: string;
  page: string;
  author: string;
  contact: boolean;
  status: { key: string; label: string };
  issue_url: string | null;
  /** Names of the screenshots sent with it, and whether Klaras has them. */
  screenshots?: { name: string; state: string }[];
  /** Sent, and the thread still takes answers (migration 22). */
  can_reply: boolean;
  created_at: string;
  replies: {
    message: string;
    kind: string;
    replied_at: string;
    unread: boolean;
    /** Written by this librarian, not by Klaras. */
    from_sender: boolean;
    /** Their answer, not sent yet. */
    pending: boolean;
  }[];
};
type FeedbackData = {
  /** False until the plugin's migration 20 has run. */
  available: boolean;
  feedback: Piece[];
  unread: number;
  kinds: Record<string, string>;
  /** What "Boleh dihubungi" would send. */
  contact: { name: string; email: string };
  /** How many screenshots a piece may carry; 0 until migration 23 has run. */
  screenshots: number;
};

const statusTone: Record<string, "outline" | "info" | "success" | "warning" | "secondary"> = {
  pending: "warning",
  new: "outline",
  reviewing: "info",
  awaiting: "warning",
  planned: "info",
  done: "success",
  declined: "secondary",
};
const MIN = 10;
const MAX = 5000;
/** One part of a problem report, as Klaras Panel takes it. */
const PART_MAX = 1500;
const SCREENSHOT_STATES: Record<string, string> = { pending: "menunggu terkirim", sent: "terkirim", refused: "tidak terkirim" };

/**
 * Feedback to Klaras, from every page: a button in the header that opens a panel to write it and
 * to read what Klaras answered. Open to every staff member who can open the plugin.
 */
export function FeedbackButton() {
  const w = useWorkspace();
  const [open, setOpen] = useState(false);
  const [tab, setTab] = useState("send");
  const [data, setData] = useState<FeedbackData>();
  const [error, setError] = useState("");

  const load = (refresh = false) =>
    read<FeedbackData>(w.config, "feedback", refresh ? { refresh: 1 } : {})
      .then((loaded) => {
        setData(loaded);
        setError("");
        return loaded;
      })
      .catch((e) => {
        setError((e as Error).message);
        return undefined;
      });

  // Once on the first page, for the dot that says Klaras has replied.
  useEffect(() => {
    load();
  }, []);

  // Opening the history asks Klaras for news, then counts its replies as read.
  async function showHistory() {
    setTab("history");
    const loaded = await load(true);
    if (loaded?.available && loaded.unread > 0) {
      await w.mutate({ watch_action: "feedback_seen" }).catch(() => {});
      setData({ ...loaded, unread: 0 });
    }
  }

  const unread = data?.unread ?? 0;
  return (
    <>
      <Button variant="ghost" size="sm" className="relative" onClick={() => setOpen(true)} aria-label={unread ? `Masukan, ${unread} balasan baru` : "Masukan"}>
        <MessageSquareText data-icon="inline-start" />
        <span className="hidden sm:inline">Masukan</span>
        {unread > 0 && <span className="absolute top-1 right-1 size-2 rounded-full bg-primary" aria-hidden />}
      </Button>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent className="flex w-full flex-col gap-0 sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Masukan untuk Klaras</SheetTitle>
            <SheetDescription>Ceritakan masalah, usul fitur, atau pertanyaan tentang Klaras Inven. Balasan kami muncul di Riwayat.</SheetDescription>
          </SheetHeader>
          <div className="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 pb-4">
            <ErrorBox message={error} />
            {data && !data.available ? (
              <p className="text-sm text-muted-foreground">Jalankan migrasi plugin hingga versi 20 di System → Plugins untuk mengirim masukan.</p>
            ) : (
              <Tabs value={tab} onValueChange={(next) => (next === "history" ? showHistory() : setTab(next))}>
                <TabsList className="w-full">
                  <TabsTrigger value="send">Kirim masukan</TabsTrigger>
                  <TabsTrigger value="history">
                    Riwayat
                    {unread > 0 && <Badge className="ml-1.5">{unread}</Badge>}
                  </TabsTrigger>
                </TabsList>
                <TabsContent value="send" className="pt-4">
                  {data && (
                    <FeedbackForm
                      data={data}
                      onSent={(piece) => {
                        setData({ ...data, feedback: [piece, ...data.feedback] });
                        setTab("history");
                      }}
                    />
                  )}
                </TabsContent>
                <TabsContent value="history" className="pt-4">
                  {data && <FeedbackHistory pieces={data.feedback} onAnswered={(piece) => setData({ ...data, feedback: data.feedback.map((other) => (other.id === piece.id ? piece : other)) })} />}
                </TabsContent>
              </Tabs>
            )}
          </div>
        </SheetContent>
      </Sheet>
    </>
  );
}

function FeedbackForm({ data, onSent }: { data: FeedbackData; onSent: (piece: Piece) => void }) {
  const w = useWorkspace();
  const messageId = useId();
  const contactId = useId();
  const [kind, setKind] = useState("bug");
  const [message, setMessage] = useState("");
  // A problem in three parts: what was done, what happened, what should have happened.
  const [parts, setParts] = useState({ did: "", happened: "", expected: "" });
  const screenshots = useScreenshots(data.screenshots);
  const [contact, setContact] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const problem = kind === "bug";
  const length = message.trim().length;
  const tooShort = !problem && length > 0 && length < MIN;
  const ready = problem ? parts.did.trim().length >= 3 && parts.happened.trim().length >= MIN : length >= MIN;

  async function send() {
    if (!ready) {
      setError(problem ? "Ceritakan apa yang Anda lakukan dan apa yang terjadi." : `Tulis masukan Anda, paling sedikit ${MIN} karakter.`);
      return;
    }
    setBusy(true);
    setError("");
    try {
      const files = new FormData();
      screenshots.files.forEach((file) => files.append("screenshots[]", file, file.name));
      const text = problem ? parts : { message };
      const reply = (await w.mutate({ watch_action: "feedback_submit", kind, ...text, page: w.route.view, contact: contact ? "1" : "" }, screenshots.files.length ? files : undefined)) as { message?: string; data?: Piece };
      toast.success(reply.message || "Masukan terkirim. Terima kasih.");
      setMessage("");
      setParts({ did: "", happened: "", expected: "" });
      screenshots.clear();
      if (reply.data) onSent(reply.data);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const part = (name: keyof typeof parts) => (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    setParts({ ...parts, [name]: e.target.value });
    setError("");
  };

  return (
    <fieldset
      disabled={busy}
      className="min-w-0"
      onPaste={(e) => {
        // A screenshot pasted anywhere in the form is attached.
        const pasted = Array.from(e.clipboardData.files).filter((file) => SCREENSHOT_TYPES.includes(file.type));
        if (data.screenshots > 0 && pasted.length > 0) {
          e.preventDefault();
          void screenshots.add(pasted);
        }
      }}
    >
      <FieldGroup>
        <ErrorBox message={error} />
        <Field>
          <FieldLabel>Jenis masukan</FieldLabel>
          <ToggleGroup type="single" variant="outline" value={kind} onValueChange={(next) => next && setKind(next)} className="flex-wrap">
            {Object.entries(data.kinds).map(([value, label]) => (
              <ToggleGroupItem key={value} value={value} aria-label={label}>
                {label}
              </ToggleGroupItem>
            ))}
          </ToggleGroup>
        </Field>
        {problem ? (
          <>
            <PartField label="Apa yang Anda lakukan?" placeholder="Contoh: Saya membuka Ruang Baca, lalu menekan Cetak KIR." value={parts.did} onChange={part("did")} />
            <PartField label="Apa yang terjadi?" placeholder="Contoh: Halaman kosong dan KIR tidak terunduh." value={parts.happened} onChange={part("happened")} />
            <PartField label="Apa yang seharusnya terjadi? (opsional)" placeholder="Contoh: KIR terunduh sebagai PDF." value={parts.expected} onChange={part("expected")} />
          </>
        ) : (
          <Field data-invalid={tooShort}>
            <FieldLabel htmlFor={messageId}>Masukan Anda</FieldLabel>
            <Textarea
              id={messageId}
              rows={6}
              maxLength={MAX}
              value={message}
              placeholder="Tulis masukan Anda"
              onChange={(e) => {
                setMessage(e.target.value);
                setError("");
              }}
            />
            {tooShort && <FieldError>Paling sedikit {MIN} karakter.</FieldError>}
          </Field>
        )}
        {data.screenshots > 0 && <ScreenshotPicker screenshots={screenshots} />}
        {/* relative: the hint spans this row, so it never reaches past the panel's edge. */}
        <Field orientation="horizontal" className="relative items-center gap-2">
          <Checkbox id={contactId} checked={contact} onCheckedChange={(checked) => setContact(checked === true)} />
          <FieldLabel htmlFor={contactId} className="flex-none">
            Boleh dihubungi
          </FieldLabel>
          <Hint label="Apa yang ikut terkirim bila boleh dihubungi">
            {data.contact.email
              ? `Nama dan email Anda ikut terkirim: ${data.contact.name} · ${data.contact.email}.`
              : `Nama Anda ikut terkirim: ${data.contact.name || "nama petugas"}. Email Anda belum diisi di SLiMS.`}
          </Hint>
        </Field>
        <div className="relative flex items-center gap-2">
          <Button onClick={send} disabled={busy || !ready || screenshots.shrinking}>
            {busy ? "Mengirim…" : "Kirim masukan"}
          </Button>
          <Hint label="Apa yang ikut terkirim">
            Ikut terkirim: nama dan alamat perpustakaan, halaman yang sedang dibuka, serta versi Klaras Inven, SLiMS, dan PHP. Jangan sertakan kata sandi atau data pribadi anggota. Untuk masalah mendesak, hubungi tim dukungan Klaras secara langsung.
          </Hint>
        </div>
      </FieldGroup>
    </fieldset>
  );
}

/**
 * A small explanation behind an info icon, shown while the pointer rests on it or it has focus.
 * Plain CSS: a tap shows it too, on phones (sticky hover on iOS, focus on Android). The bubble is
 * placed against the nearest positioned ancestor, which the caller makes the row it explains,
 * so it is as wide as that row and never pushes the panel into scrolling sideways.
 */
function Hint({ label, children }: { label: string; children: React.ReactNode }) {
  const id = useId();
  return (
    <span className="group inline-flex">
      <button type="button" aria-label={label} aria-describedby={id} className="inline-flex size-5 items-center justify-center rounded-full text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
        <Info className="size-3.5" />
      </button>
      <span
        id={id}
        role="tooltip"
        className="pointer-events-none invisible absolute inset-x-0 bottom-full z-50 mb-1.5 rounded-lg bg-popover p-2 text-xs text-popover-foreground opacity-0 shadow-md ring-1 ring-foreground/10 transition-opacity group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100"
      >
        {children}
      </span>
    </span>
  );
}

/** One part of a problem report. */
function PartField({ label, placeholder, value, onChange }: { label: string; placeholder: string; value: string; onChange: (e: React.ChangeEvent<HTMLTextAreaElement>) => void }) {
  const id = useId();
  return (
    <Field>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <Textarea id={id} rows={2} maxLength={PART_MAX} value={value} placeholder={placeholder} onChange={onChange} />
    </Field>
  );
}

/**
 * Up to $max screenshots, chosen or pasted: each shrunk to at most 500 KB before it is kept
 * (shrinkScreenshot), and any that cannot be refused with the reason.
 */
function useScreenshots(max: number) {
  const [files, setFiles] = useState<File[]>([]);
  const [refusal, setRefusal] = useState("");
  const [shrinking, setShrinking] = useState(false);

  async function add(chosen: File[]) {
    const images = chosen.filter((file) => SCREENSHOT_TYPES.includes(file.type) && file.size <= SCREENSHOT_MAX_INPUT_BYTES);
    setShrinking(true);
    const shrunk = await Promise.all(images.map(shrinkScreenshot));
    setShrinking(false);
    const fitting = shrunk.filter((file) => file.size <= SCREENSHOT_TARGET_BYTES);
    setRefusal(
      images.length < chosen.length
        ? "Lampirkan gambar PNG, JPG, atau WebP."
        : fitting.length < images.length
          ? "Gambar ini tidak dapat dikecilkan hingga 500 KB. Potong bagian yang perlu saja."
          : files.length + fitting.length > max
            ? `Lampirkan paling banyak ${max} tangkapan layar.`
            : "",
    );
    setFiles((current) => [...current, ...fitting].slice(0, max));
  }

  return {
    files,
    refusal,
    shrinking,
    max,
    add,
    remove: (index: number) => setFiles((current) => current.filter((_, n) => n !== index)),
    clear: () => {
      setFiles([]);
      setRefusal("");
    },
  };
}

/** The screenshots of a piece, previewed before they are sent and each removable. */
function ScreenshotPicker({ screenshots }: { screenshots: ReturnType<typeof useScreenshots> }) {
  const { files, max } = screenshots;
  const input = useRef<HTMLInputElement>(null);
  const previews = useMemo(() => files.map((file) => URL.createObjectURL(file)), [files]);
  useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);

  return (
    <Field>
      <div className="relative flex items-center gap-1">
        <FieldLabel>Tangkapan layar (opsional)</FieldLabel>
        <Hint label="Tentang tangkapan layar">
          Paling banyak {max} gambar. Gambar besar dikecilkan otomatis hingga 500 KB. Anda juga bisa menempelkannya (Ctrl+V). Tutupi data anggota yang tidak perlu terlihat.
        </Hint>
      </div>
      {files.length > 0 && (
        <ul className="grid grid-cols-3 gap-2">
          {files.map((file, n) => (
            <li key={previews[n]} className="relative overflow-hidden rounded-lg border">
              <img src={previews[n]} alt={file.name} className="aspect-video w-full object-cover" />
              <Button type="button" size="icon" variant="secondary" className="absolute top-1 right-1 size-6" aria-label={`Hapus ${file.name}`} onClick={() => screenshots.remove(n)}>
                <X className="size-3.5" />
              </Button>
            </li>
          ))}
        </ul>
      )}
      <input
        ref={input}
        type="file"
        accept={SCREENSHOT_TYPES.join(",")}
        multiple
        hidden
        aria-label="Pilih tangkapan layar"
        onChange={(e) => {
          void screenshots.add(Array.from(e.target.files ?? []));
          e.target.value = "";
        }}
      />
      {files.length < max && (
        <div>
          <Button type="button" size="sm" variant="outline" disabled={screenshots.shrinking} onClick={() => input.current?.click()}>
            <ImagePlus data-icon="inline-start" />
            {screenshots.shrinking ? "Mengecilkan gambar…" : "Tambah tangkapan layar"}
          </Button>
        </div>
      )}
      {screenshots.refusal && <FieldError>{screenshots.refusal}</FieldError>}
    </Field>
  );
}

function FeedbackHistory({ pieces, onAnswered }: { pieces: Piece[]; onAnswered: (piece: Piece) => void }) {
  // One answer box open at a time.
  const [answering, setAnswering] = useState<number | null>(null);
  if (pieces.length === 0) return <p className="text-sm text-muted-foreground">Anda belum mengirim masukan.</p>;
  return (
    <ul className="flex flex-col gap-3">
      {pieces.map((piece) => (
        <li key={piece.id} className="flex flex-col gap-2 rounded-xl border p-3">
          <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
            <Badge variant="outline">{piece.kind.label}</Badge>
            <Badge variant={statusTone[piece.status.key] ?? "outline"}>{piece.status.label}</Badge>
            <span>{dateLabel(piece.created_at)}</span>
          </div>
          <p className="line-clamp-6 text-sm whitespace-pre-wrap">{piece.message}</p>
          {piece.screenshots && piece.screenshots.length > 0 && (
            <ul className="flex flex-col gap-0.5 text-xs text-muted-foreground">
              {piece.screenshots.map((screenshot, n) => (
                <li key={n} className="inline-flex items-center gap-1">
                  <Paperclip className="size-3" />
                  {screenshot.name} · {SCREENSHOT_STATES[screenshot.state] ?? screenshot.state}
                </li>
              ))}
            </ul>
          )}
          {piece.issue_url && (
            <a href={piece.issue_url} target="_blank" rel="noopener noreferrer" className="notAJAX inline-flex w-fit items-center gap-1 text-xs font-medium text-primary underline-offset-4 hover:underline">
              Lihat tindak lanjutnya di GitHub
              <ExternalLink className="size-3" />
            </a>
          )}
          {piece.replies.length > 0 && (
            <ol className="flex flex-col gap-2 border-l-2 pl-3">
              {piece.replies.map((reply, n) => (
                <li key={n} className="text-sm">
                  <p className="text-xs text-muted-foreground">
                    {reply.from_sender ? "Anda" : "Klaras"} · {dateLabel(reply.replied_at)}
                    {reply.unread && <Badge className="ml-1.5">Baru</Badge>}
                    {reply.pending && <Badge variant="warning" className="ml-1.5">Menunggu terkirim</Badge>}
                    {reply.kind === "refused" && <Badge variant="secondary" className="ml-1.5">Tidak terkirim: percakapan sudah ditutup</Badge>}
                  </p>
                  <p className="whitespace-pre-wrap">{reply.message}</p>
                </li>
              ))}
            </ol>
          )}
          {piece.can_reply &&
            (answering === piece.id ? (
              <AnswerForm
                piece={piece}
                onDone={(updated) => {
                  setAnswering(null);
                  if (updated) onAnswered(updated);
                }}
              />
            ) : (
              <div>
                <Button size="sm" variant={piece.status.key === "awaiting" ? "default" : "outline"} onClick={() => setAnswering(piece.id)}>
                  Balas
                </Button>
              </div>
            ))}
        </li>
      ))}
    </ul>
  );
}

/** The librarian's answer in a thread: usually the detail Klaras asked for. */
function AnswerForm({ piece, onDone }: { piece: Piece; onDone: (updated?: Piece) => void }) {
  const w = useWorkspace();
  const id = useId();
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function send() {
    setBusy(true);
    setError("");
    try {
      const reply = (await w.mutate({ watch_action: "feedback_reply", feedback_id: String(piece.id), message })) as { message?: string; data?: Piece };
      toast.success(reply.message || "Balasan terkirim.");
      onDone(reply.data);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <fieldset disabled={busy} className="flex min-w-0 flex-col gap-2">
      <ErrorBox message={error} />
      <label htmlFor={id} className="sr-only">
        Balasan Anda
      </label>
      <Textarea id={id} rows={3} maxLength={MAX} value={message} placeholder="Tulis balasan Anda" onChange={(e) => setMessage(e.target.value)} autoFocus />
      <div className="flex gap-2">
        <Button size="sm" onClick={send} disabled={busy || message.trim().length < 2}>
          {busy ? "Mengirim…" : "Kirim balasan"}
        </Button>
        <Button size="sm" variant="ghost" onClick={() => onDone()}>
          Batal
        </Button>
      </div>
    </fieldset>
  );
}
