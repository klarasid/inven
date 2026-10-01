import { useEffect, useState, type ReactNode } from "react";
import { toast } from "sonner";
import {
  CheckCircle2,
  CircleAlert,
  Copy,
  LogOut,
  Power,
  ShieldCheck,
  Smartphone,
  XCircle,
} from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Alert, AlertDescription, AlertTitle } from "./components/ui/alert";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "./components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "./components/ui/table";
import { useWorkspace } from "./context";
import { formData, request, url } from "./api";
import { Blank, ErrorBox, Loading, PageHeader, Panel } from "./shared";

/**
 * GET ?format=json on a page's own PHP file, and POST actions back to it: the file that mounted
 * the workspace, or the one given for a view that opens from other menus too.
 */
export function usePage<T>(endpoint?: string) {
  const { config } = useWorkspace();
  const page = endpoint ?? config.page!;
  const [data, setData] = useState<T>();
  const [error, setError] = useState("");
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    request<{ data: T }>(url(page, { format: "json" }), undefined, controller.signal)
      .then((r) => setData(r.data))
      .catch((e) => e.name !== "AbortError" && setError(e.message));
    return () => controller.abort();
  }, [page, revision]);
  const post = (values: Record<string, unknown>) => request(page, formData(values));
  return { data, error, reload: () => setRevision((n) => n + 1), post };
}

/** One confirmation dialog for actions that cut someone off. */
export function Confirm({
  open,
  title,
  description,
  action,
  busy,
  onCancel,
  onConfirm,
}: {
  open: boolean;
  title: string;
  description: ReactNode;
  action: string;
  busy: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  return (
    <Dialog open={open} onOpenChange={(o) => !o && !busy && onCancel()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" disabled={busy} onClick={onCancel}>
            Batal
          </Button>
          <Button variant="destructive" disabled={busy} onClick={onConfirm}>
            {action}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function StatusRow({ label, ok, yes, no, hint }: { label: string; ok: boolean | null; yes: string; no: string; hint?: string }) {
  return (
    <div className="flex flex-col gap-1 border-b py-3 last:border-b-0 sm:flex-row sm:items-start sm:gap-4">
      <span className="text-sm font-medium sm:w-48 sm:shrink-0">{label}</span>
      <div className="flex min-w-0 flex-col gap-1">
        {ok === null ? (
          <span className="text-sm text-muted-foreground">—</span>
        ) : (
          <Badge variant={ok ? "success" : "warning"} className="self-start">
            {ok ? <CheckCircle2 data-icon="inline-start" /> : <CircleAlert data-icon="inline-start" />}
            {ok ? yes : no}
          </Badge>
        )}
        {ok === false && hint && <p className="text-sm text-muted-foreground">{hint}</p>}
      </div>
    </div>
  );
}

const when = (value: string | null) =>
  value
    ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value.replace(" ", "T")))
    : "—";

type InvenSync = {
  connect: boolean;
  linked: boolean;
  licensed: boolean;
  enabled: boolean;
  sessions: { id: number; name: string; device: string; ip: string; created_at: string; last_used_at: string }[];
  problem: string;
  manage: boolean;
  csrf: string;
};

export function InvenSyncPage() {
  const { data, error, reload, post } = usePage<InvenSync>();
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState<{ kind: "disable" } | { kind: "revoke"; id: number; name: string } | null>(null);

  async function act(values: Record<string, unknown>) {
    if (!data) return;
    setBusy(true);
    try {
      const reply = await post({ ...values, csrf: data.csrf });
      toast.success(reply.message);
      setConfirm(null);
      reload();
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const header = (
    <PageHeader
      title="Aplikasi InvenSync"
      description="Klaras InvenSync adalah aplikasi HP untuk mencatat barang, memeriksa ruangan, dan menjalankan stock opname langsung ke SLiMS ini. Petugas masuk dengan akun SLiMS masing-masing dan hanya bisa melakukan yang diizinkan hak Stock Take mereka."
      meta={
        data && (
          <Badge variant={data.enabled ? "success" : "secondary"}>
            {data.enabled ? "Diizinkan" : "Belum diizinkan"}
          </Badge>
        )
      }
      actions={
        data?.connect &&
        data.manage &&
        (data.enabled ? (
          <Button variant="outline" disabled={busy} onClick={() => setConfirm({ kind: "disable" })}>
            <Power data-icon="inline-start" />
            Matikan aplikasi
          </Button>
        ) : (
          <Button disabled={busy} onClick={() => act({ action: "enable" })}>
            <ShieldCheck data-icon="inline-start" />
            Izinkan aplikasi
          </Button>
        ))
      }
    />
  );
  if (error)
    return (
      <>
        {header}
        <ErrorBox message={error} />
      </>
    );
  if (!data)
    return (
      <>
        {header}
        <Loading />
      </>
    );

  return (
    <>
      {header}
      <ErrorBox message={data.problem} />
      {data.connect && !data.manage && (
        <Alert>
          <AlertDescription>Hanya pengguna dengan hak tulis System yang bisa mengubah izin dan mencabut sesi.</AlertDescription>
        </Alert>
      )}
      <Panel title="Kesiapan" description="Aplikasi bisa dipakai setelah keempat syarat ini terpenuhi. Plugin tetap bisa dipakai penuh dari SLiMS tanpa aplikasi.">
        <StatusRow
          label="SLiMS Connect"
          ok={data.connect}
          yes="Terpasang"
          no="Belum terpasang"
          hint="Pasang SLiMS Connect (PHP 8.1 atau lebih baru) untuk memakai aplikasi."
        />
        <StatusRow
          label="Tautan ke Klaras Panel"
          ok={data.connect ? data.linked : null}
          yes="Tertaut"
          no="Belum tertaut"
          hint="Daftarkan perpustakaan di Klaras Panel, lalu isi API key di System → SLiMS Connect."
        />
        <StatusRow
          label="Paket Klaras Panel"
          ok={data.linked ? data.licensed : null}
          yes="Mencakup Klaras InvenSync"
          no="Belum mencakup Klaras InvenSync"
        />
        <StatusRow label="Izin aplikasi" ok={data.enabled} yes="Diizinkan" no="Belum diizinkan" />
      </Panel>
      {data.connect && (
        <Panel title="Perangkat yang masuk" description="Sesi aplikasi petugas yang masih aktif.">
          {data.sessions.length === 0 ? (
            <Blank icon={Smartphone} title="Belum ada perangkat" description="Belum ada petugas yang masuk ke aplikasi." />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Petugas</TableHead>
                  <TableHead>Perangkat</TableHead>
                  <TableHead>Masuk</TableHead>
                  <TableHead>Terakhir aktif</TableHead>
                  {data.manage && <TableHead className="w-0" />}
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.sessions.map((s) => (
                  <TableRow key={s.id}>
                    <TableCell className="font-medium">{s.name}</TableCell>
                    <TableCell>
                      <div>{s.device || "—"}</div>
                      <div className="text-xs text-muted-foreground">{s.ip}</div>
                    </TableCell>
                    <TableCell>{when(s.created_at)}</TableCell>
                    <TableCell>{when(s.last_used_at)}</TableCell>
                    {data.manage && (
                      <TableCell>
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-destructive"
                          disabled={busy}
                          onClick={() => setConfirm({ kind: "revoke", id: s.id, name: s.name })}
                        >
                          <LogOut data-icon="inline-start" />
                          Cabut sesi
                        </Button>
                      </TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Panel>
      )}
      <Confirm
        open={confirm?.kind === "disable"}
        title="Matikan aplikasi InvenSync?"
        description="Semua petugas langsung tidak bisa memakainya, dan perubahan yang belum terkirim tertahan di HP mereka."
        action="Matikan aplikasi"
        busy={busy}
        onCancel={() => setConfirm(null)}
        onConfirm={() => act({ action: "disable" })}
      />
      <Confirm
        open={confirm?.kind === "revoke"}
        title="Cabut sesi ini?"
        description={
          <>
            Perangkat {confirm?.kind === "revoke" && <b>{confirm.name}</b>} harus masuk lagi, dan perubahan yang belum
            terkirim tertahan di HP.
          </>
        }
        action="Cabut sesi"
        busy={busy}
        onCancel={() => setConfirm(null)}
        onConfirm={() => confirm?.kind === "revoke" && act({ action: "revoke", session: confirm.id })}
      />
    </>
  );
}

type Privacy = {
  enabled: boolean;
  last_sent: string | null;
  last_result: string;
  endpoint: string;
  install_id: string;
  report: unknown;
  manage: boolean;
  csrf: string;
};
const results: Record<string, string> = {
  ok: "Terkirim",
  failed: "Gagal terkirim, dicoba lagi dalam satu jam",
  off: "Dimatikan",
};

function InfoRow({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
  return (
    <div className="flex flex-col gap-1 border-b py-3 last:border-b-0 sm:flex-row sm:items-start sm:gap-4">
      <span className="text-sm font-medium sm:w-48 sm:shrink-0">{label}</span>
      <div className="flex min-w-0 flex-col gap-1 text-sm">
        {children}
        {hint && <p className="text-muted-foreground">{hint}</p>}
      </div>
    </div>
  );
}

export function PrivacyPage() {
  const { data, error, reload, post } = usePage<Privacy>();
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const json = data ? JSON.stringify(data.report, null, 2) : "";

  async function toggle(enable: boolean) {
    if (!data) return;
    setBusy(true);
    try {
      const reply = await post({ action: enable ? "enable" : "disable", csrf: data.csrf });
      toast.success(reply.message);
      setConfirm(false);
      reload();
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const header = (
    <PageHeader
      title="Data pemakaian"
      description="Sekali sehari, Klaras Inven mengirim ringkasan pemakaian ke Klaras agar plugin ini bisa terus dirawat: versi mana yang dipakai, fitur apa yang berguna, dan galat apa yang perlu diperbaiki."
      meta={data && <Badge variant={data.enabled ? "success" : "secondary"}>{data.enabled ? "Pengiriman aktif" : "Dimatikan"}</Badge>}
      actions={
        data?.manage &&
        (data.enabled ? (
          <Button variant="outline" disabled={busy} onClick={() => setConfirm(true)}>
            <XCircle data-icon="inline-start" />
            Matikan pengiriman
          </Button>
        ) : (
          <Button disabled={busy} onClick={() => toggle(true)}>
            <Power data-icon="inline-start" />
            Aktifkan kembali
          </Button>
        ))
      }
    />
  );
  if (error)
    return (
      <>
        {header}
        <ErrorBox message={error} />
      </>
    );
  if (!data)
    return (
      <>
        {header}
        <Loading />
      </>
    );

  return (
    <>
      {header}
      <Alert>
        <ShieldCheck />
        <AlertTitle>Yang tidak pernah dikirim</AlertTitle>
        <AlertDescription>
          Isi inventaris, nama dan kode barang, data anggota, dan data petugas. Yang dikirim hanya nama perpustakaan,
          alamat SLiMS, versi perangkat lunak, jumlah (ruangan, barang, pemeriksaan, temuan, stock opname), pemakaian
          fitur, dan galat teknis yang sudah dibersihkan dari isinya.
        </AlertDescription>
      </Alert>
      {!data.manage && (
        <Alert>
          <AlertDescription>Hanya pengguna dengan hak tulis System yang bisa mematikan atau mengaktifkan pengiriman.</AlertDescription>
        </Alert>
      )}
      <Panel title="Status pengiriman">
        <InfoRow label="Terakhir dikirim">
          {data.last_sent ? (
            <span>
              {when(data.last_sent)} · {results[data.last_result] ?? data.last_result}
            </span>
          ) : (
            <span className="text-muted-foreground">Belum pernah</span>
          )}
        </InfoRow>
        <InfoRow label="Tujuan">
          <code className="break-all rounded bg-muted px-1.5 py-0.5 font-mono text-xs">{data.endpoint}</code>
        </InfoRow>
        <InfoRow label="ID instalasi" hint="Acak, dibuat di SLiMS ini. Tidak terkait dengan orang mana pun.">
          <code className="break-all rounded bg-muted px-1.5 py-0.5 font-mono text-xs">{data.install_id}</code>
        </InfoRow>
      </Panel>
      <Panel
        title="Data yang dikirim"
        description="Persis seperti di bawah ini, dalam format JSON."
        action={
          <Button
            size="sm"
            variant="outline"
            onClick={() =>
              navigator.clipboard
                .writeText(json)
                .then(() => toast.success("JSON disalin."))
                .catch(() => toast.error("Gagal menyalin."))
            }
          >
            <Copy data-icon="inline-start" />
            Salin
          </Button>
        }
      >
        <pre className="max-h-[480px] overflow-auto rounded-lg border bg-muted/40 p-4 font-mono text-xs leading-relaxed">{json}</pre>
      </Panel>
      <Confirm
        open={confirm}
        title="Matikan pengiriman data pemakaian?"
        description="Klaras akan diberi tahu sekali, lalu menghapus nama dan alamat perpustakaan ini dari datanya."
        action="Matikan pengiriman"
        busy={busy}
        onCancel={() => setConfirm(false)}
        onConfirm={() => toggle(false)}
      />
    </>
  );
}
