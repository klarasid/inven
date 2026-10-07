import { useEffect, useId, useState } from "react";
import { toast } from "sonner";
import { ExternalLink, MessageSquareText } from "lucide-react";
import { Badge } from "./components/ui/badge";
import { Button } from "./components/ui/button";
import { Checkbox } from "./components/ui/checkbox";
import { Field, FieldContent, FieldDescription, FieldError, FieldGroup, FieldLabel } from "./components/ui/field";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "./components/ui/sheet";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "./components/ui/tabs";
import { Textarea } from "./components/ui/textarea";
import { ToggleGroup, ToggleGroupItem } from "./components/ui/toggle-group";
import { useWorkspace } from "./context";
import { dateLabel, read } from "./api";
import { ErrorBox } from "./shared";

type Piece = {
  id: number;
  kind: { key: string; label: string };
  message: string;
  page: string;
  author: string;
  contact: boolean;
  status: { key: string; label: string };
  issue_url: string | null;
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
  const [contact, setContact] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const length = message.trim().length;
  const tooShort = length > 0 && length < MIN;

  async function send() {
    if (length < MIN) {
      setError(`Tulis masukan Anda, paling sedikit ${MIN} karakter.`);
      return;
    }
    setBusy(true);
    setError("");
    try {
      const reply = (await w.mutate({ watch_action: "feedback_submit", kind, message, page: w.route.view, contact: contact ? "1" : "" })) as { message?: string; data?: Piece };
      toast.success(reply.message || "Masukan terkirim. Terima kasih.");
      setMessage("");
      if (reply.data) onSent(reply.data);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <fieldset disabled={busy} className="min-w-0">
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
        <Field data-invalid={tooShort}>
          <FieldLabel htmlFor={messageId}>Masukan Anda</FieldLabel>
          <Textarea
            id={messageId}
            rows={6}
            maxLength={MAX}
            value={message}
            placeholder={kind === "bug" ? "Apa yang Anda lakukan, apa yang terjadi, dan apa yang seharusnya terjadi?" : "Tulis masukan Anda"}
            onChange={(e) => {
              setMessage(e.target.value);
              setError("");
            }}
          />
          {tooShort ? <FieldError>Paling sedikit {MIN} karakter.</FieldError> : <FieldDescription>Jangan sertakan kata sandi atau data pribadi anggota.</FieldDescription>}
        </Field>
        <Field orientation="horizontal" className="items-start rounded-xl border p-3">
          <Checkbox id={contactId} checked={contact} onCheckedChange={(checked) => setContact(checked === true)} />
          <FieldContent>
            <FieldLabel htmlFor={contactId}>Boleh dihubungi</FieldLabel>
            <FieldDescription>
              {data.contact.email
                ? `Nama dan email Anda ikut terkirim: ${data.contact.name} · ${data.contact.email}.`
                : `Nama Anda ikut terkirim: ${data.contact.name || "nama petugas"}. Email Anda belum diisi di SLiMS.`}
            </FieldDescription>
          </FieldContent>
        </Field>
        <p className="text-xs text-muted-foreground">
          Ikut terkirim: nama dan alamat perpustakaan, halaman yang sedang dibuka, serta versi Klaras Inven, SLiMS, dan PHP. Untuk masalah mendesak, hubungi tim dukungan Klaras secara langsung.
        </p>
        <div>
          <Button onClick={send} disabled={busy || length < MIN}>
            {busy ? "Mengirim…" : "Kirim masukan"}
          </Button>
        </div>
      </FieldGroup>
    </fieldset>
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
          <p className="line-clamp-4 text-sm whitespace-pre-wrap">{piece.message}</p>
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
