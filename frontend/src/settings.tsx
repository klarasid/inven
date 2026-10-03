import { useEffect, useState, type ReactNode } from "react";
import { toast } from "sonner";
import {
  CheckCircle2,
  CircleAlert,
  LogOut,
  Power,
  ShieldCheck,
  Smartphone,
} from "lucide-react";
import { Button } from "./components/ui/button";
import { Badge } from "./components/ui/badge";
import { Alert, AlertDescription } from "./components/ui/alert";
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
 * the workspace, or the one given for a view that opens from other menus too. `params` narrow
 * what the page is about (a library location) and go with every request; what was loaded for
 * other params is never shown.
 */
export function usePage<T>(endpoint?: string, params: Record<string, string> = {}) {
  const { config } = useWorkspace();
  const page = endpoint ?? config.page!;
  const scope = `${page} ${JSON.stringify(params)}`;
  const [loaded, setLoaded] = useState<{ scope: string; data?: T; error?: string }>();
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    request<{ data: T }>(url(page, { ...params, format: "json" }), undefined, controller.signal)
      .then((r) => setLoaded({ scope, data: r.data }))
      .catch((e) => e.name !== "AbortError" && setLoaded((was) => ({ scope, data: was?.scope === scope ? was.data : undefined, error: e.message })));
    return () => controller.abort();
  }, [scope, revision]);
  const current = loaded?.scope === scope ? loaded : undefined;
  const post = (values: Record<string, unknown>) => request(page, formData({ ...params, ...values }));
  return { data: current?.data, error: current?.error ?? "", reload: () => setRevision((n) => n + 1), post };
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
  agents: boolean;
  sessions: { id: number; name: string; device: string; ip: string; created_at: string; last_used_at: string; agent: boolean }[];
  problem: string;
  manage: boolean;
  csrf: string;
};

export function InvenSyncPage() {
  const { data, error, reload, post } = usePage<InvenSync>();
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState<{ kind: "disable" } | { kind: "agents" } | { kind: "revoke"; id: number; name: string } | null>(null);

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
        <Panel
          title="Agent AI"
          description="Aplikasi AI seperti Claude dapat membantu petugas membuat jadwal, menulis laporan kerusakan, dan merangkum laporan. Petugas menghubungkannya lewat Klaras Panel dan menyetujuinya di SLiMS ini; aplikasi itu bekerja atas nama petugas tersebut, dengan hak Stock Take-nya."
          action={
            data.manage &&
            (data.agents ? (
              <Button variant="outline" disabled={busy} onClick={() => setConfirm({ kind: "agents" })}>
                <Power data-icon="inline-start" />
                Matikan agent AI
              </Button>
            ) : (
              <Button disabled={busy || !data.enabled} onClick={() => act({ action: "agents_enable" })}>
                <ShieldCheck data-icon="inline-start" />
                Izinkan agent AI
              </Button>
            ))
          }
        >
          <StatusRow
            label="Izin agent AI"
            ok={data.agents}
            yes="Diizinkan"
            no="Belum diizinkan"
            hint={data.enabled ? "Administrator dengan hak tulis System dapat mengizinkannya di sini." : "Izinkan aplikasi InvenSync lebih dulu."}
          />
        </Panel>
      )}
      {data.connect && (
        <Panel title="Perangkat yang masuk" description="Sesi aplikasi petugas dan agent AI yang masih aktif.">
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
                      <div className="flex flex-wrap items-center gap-2">
                        {s.device || "—"}
                        {s.agent && <Badge variant="secondary">Agent AI</Badge>}
                      </div>
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
        open={confirm?.kind === "agents"}
        title="Matikan agent AI?"
        description="Semua aplikasi AI yang tersambung langsung terputus dan sesinya dicabut. Petugas harus mengizinkannya lagi setelah agent AI dinyalakan kembali."
        action="Matikan agent AI"
        busy={busy}
        onCancel={() => setConfirm(null)}
        onConfirm={() => act({ action: "agents_disable" })}
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
