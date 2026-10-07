import { useEffect, useRef, useState } from "react";
import { Maximize, Minimize, RefreshCw } from "lucide-react";
import { toast } from "sonner";
import { Button } from "./components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "./components/ui/dialog";
import { Toaster } from "./components/ui/sonner";
import { Badge } from "./components/ui/badge";
import { WorkspaceContext, mutation } from "./context";
import { read } from "./api";
import { Loading, ErrorBox } from "./shared";
import { Tasks, InspectionPage, FindingPage } from "./tasks";
import { SetupList, TemplatePage, SchedulePage } from "./setup";
import { InventoryList, InventoryForm } from "./inventory";
import { Reports } from "./reports";
import { HistoryImportPage } from "./history-import";
import { UpdateNotice } from "./updates";
import { FeedbackButton } from "./feedback";
import { InvenLogo } from "./logo";
import { ReportPage } from "./report";
import { InvenSyncPage } from "./settings";
import { SarprasPage } from "./sarpras";
import { SoftwarePage } from "./software";
import { FacilityPage } from "./facility";
import { SivitasPage } from "./sivitas";
import { PrintSettingsPage } from "./print-settings";
import type { Config, Options, Route } from "./types";

export function initialRoute(config: Config): Route {
  const q = config.query;
  const base: Route = { view: config.view };
  if (q.tab === "inspection" || q.tab === "finding") return { ...base, view: q.tab, record: q.record };
  if (q.tab === "findings") return { view: "tasks", kind: "findings" };
  if (q.tab === "inspections") return { view: "tasks" };
  if (q.tab === "template" || (q.tab === "setup" && q.template_id)) return { view: "template-edit", record: q.template_id };
  if (q.tab === "setup") return { view: q.panel === "templates" ? "checklists" : "schedules" };
  if (q.tab === "schedule")
    return { view: "schedule-edit", replaces_id: q.replaces_id, room: q.location_id, template_id: q.template_id };
  if (q.tab === "new")
    return { view: "new-inspection", parent_id: q.parent_id, room: q.location_id, template_id: q.template_id };
  if (q.action === "view_location") return { view: "inventory", room: q.location_id };
  if (q.action === "view_photos") return { view: "inventory", room: q.location_id, item: q.record_id };
  if (q.action === "edit_item" || q.action === "add_item")
    return { view: "item-edit", record: q.record_id, room: q.location_id };
  if (q.action === "edit_location" || q.action === "add_location") return { view: "room-edit", record: q.record_id };
  return base;
}

function fallback(view: string): Route {
  if (view.startsWith("item") || view === "room-edit") return { view: "inventory" };
  if (view.startsWith("template")) return { view: "checklists" };
  if (view.startsWith("schedule")) return { view: "schedules" };
  return { view: "tasks" };
}

export function App({ config, host }: { config: Config; host: HTMLElement }) {
  const [options, setOptions] = useState<Options>();
  const [error, setError] = useState("");
  const [route, setRoute] = useState<Route>(() => initialRoute(config));
  const [revision, setRevision] = useState(0);
  const stack = useRef<Route[]>([]);
  const isDirty = useRef(false);
  const pending = useRef<(() => void) | null>(null);
  const [confirm, setConfirm] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const syncLock = useRef(false);
  const [fullscreen, setFullscreen] = useState(false);
  const canFullscreen = typeof host.requestFullscreen === "function";

  useEffect(() => {
    const onChange = () => setFullscreen(document.fullscreenElement === host);
    document.addEventListener("fullscreenchange", onChange);
    return () => document.removeEventListener("fullscreenchange", onChange);
  }, [host]);
  const toggleFullscreen = () => {
    if (document.fullscreenElement === host) document.exitFullscreen();
    else host.requestFullscreen().catch(() => {});
  };
  const guard = (fn: () => void) => {
    if (isDirty.current) {
      pending.current = fn;
      setConfirm(true);
    } else fn();
  };
  function go(next: Route, replace = false) {
    guard(() => {
      if (!replace) stack.current.push(route);
      setRoute(next);
      if (!replace) host.scrollIntoView({ block: "start" });
    });
  }
  function back() {
    guard(() => setRoute(stack.current.pop() || fallback(route.view)));
  }

  useEffect(() => {
    const controller = new AbortController();
    read<Options>(config, "options", {}, controller.signal)
      .then(setOptions)
      .catch((e) => {
        if (e.name !== "AbortError") setError(e.message);
      });
    return () => controller.abort();
  }, [config, revision]);

  useEffect(() => {
    const unload = (e: BeforeUnloadEvent) => {
      if (isDirty.current) {
        e.preventDefault();
        e.returnValue = "";
      }
    };
    const click = (e: MouseEvent) => {
      if (!isDirty.current || e.composedPath().includes(host)) return;
      const target = e.target instanceof Element ? e.target.closest<HTMLAnchorElement>("a[href]") : null;
      if (!target || target.target === "_blank") return;
      e.preventDefault();
      e.stopImmediatePropagation();
      pending.current = () => target.click();
      setConfirm(true);
    };
    window.addEventListener("beforeunload", unload);
    document.addEventListener("click", click, true);
    return () => {
      window.removeEventListener("beforeunload", unload);
      document.removeEventListener("click", click, true);
    };
  }, [host]);

  useEffect(() => {
    if (!options || !config.write || route.view !== "tasks" || syncLock.current) return;
    let stopped = false;
    syncLock.current = true;
    setSyncing(true);
    (async () => {
      let more = true,
        changed = false;
      try {
        while (more && !stopped) {
          const r = await mutation(config, options, { watch_action: "sync" });
          more = !!r.more;
          changed = changed || !!r.generated;
        }
        if (changed && !stopped && !isDirty.current) setRevision((n) => n + 1);
      } catch (e) {
        if (!stopped) toast.error(`Jadwal belum tersinkron: ${(e as Error).message}`);
      } finally {
        syncLock.current = false;
        if (!stopped) setSyncing(false);
      }
    })();
    return () => {
      stopped = true;
    };
  }, [options?.csrf, config, route.view]);

  if (error)
    return (
      <div className="flex flex-col gap-4 p-6">
        <ErrorBox message={error} />
        <Button
          variant="outline"
          className="self-start"
          onClick={() => {
            setError("");
            setRevision((n) => n + 1);
          }}
        >
          Coba lagi
        </Button>
      </div>
    );
  if (!options)
    return (
      <div className="p-6">
        <Loading />
      </div>
    );

  let page;
  switch (route.view) {
    case "history-import":
      page = <HistoryImportPage />;
      break;
    case "report":
      page = <ReportPage />;
      break;
    case "tasks":
      page = <Tasks />;
      break;
    case "inspection":
      page = <InspectionPage />;
      break;
    case "finding":
      page = <FindingPage />;
      break;
    case "inventory":
      page = <InventoryList />;
      break;
    case "item-edit":
    case "item-detail":
    case "room-edit":
      page = <InventoryForm />;
      break;
    case "schedules":
    case "checklists":
      page = <SetupList />;
      break;
    case "template-edit":
    case "template-detail":
      page = <TemplatePage />;
      break;
    case "schedule-edit":
    case "schedule-detail":
    case "new-inspection":
      page = <SchedulePage />;
      break;
    case "reports":
      page = <Reports />;
      break;
    case "invensync":
      page = <InvenSyncPage />;
      break;
    case "sarpras":
      page = <SarprasPage />;
      break;
    case "software":
      page = <SoftwarePage />;
      break;
    case "facility":
      page = <FacilityPage />;
      break;
    case "sivitas":
      page = <SivitasPage />;
      break;
    case "print-settings":
      page = <PrintSettingsPage />;
      break;
    default:
      page = <ErrorBox message="Halaman tidak ditemukan." />;
  }
  const me = options.users.find((u) => Number(u.user_id) === config.uid)?.realname;

  return (
    <WorkspaceContext.Provider
      value={{
        config,
        options,
        route,
        go,
        back,
        dirty: (v) => {
          isDirty.current = v;
        },
        revision,
        refresh: () => setRevision((n) => n + 1),
        mutate: (values, files, inventory) => mutation(config, options, values, files, inventory),
      }}
    >
      <div className="min-h-[70vh] bg-background font-sans text-foreground">
        <div className="flex items-center justify-between gap-2 border-b px-4 py-2.5 md:px-8">
          <span className="flex items-center gap-2 text-sm font-medium">
            <InvenLogo className="size-6 shrink-0" />
            Klaras Inven
          </span>
          <div className="flex items-center gap-2">
            {syncing && (
              <span role="status" className="flex items-center gap-1 text-xs text-muted-foreground">
                <RefreshCw className="size-3 animate-spin" />
                Menyinkronkan jadwal…
              </span>
            )}
            <UpdateNotice />
            <FeedbackButton />
            {config.write ? (
              <span className="text-xs text-muted-foreground">{me}</span>
            ) : (
              <Badge variant="outline">Hanya baca</Badge>
            )}
            {canFullscreen && (
              <Button
                variant="ghost"
                size="icon-sm"
                className="md:hidden"
                onClick={toggleFullscreen}
                aria-label={fullscreen ? "Keluar layar penuh" : "Layar penuh"}
              >
                {fullscreen ? <Minimize /> : <Maximize />}
              </Button>
            )}
          </div>
        </div>
        <main
          className="mx-auto flex max-w-6xl flex-col gap-6 p-4 md:p-8"
          key={`${route.view}:${route.record || ""}:${route.replaces_id || ""}:${route.parent_id || ""}`}
        >
          {page}
        </main>
      </div>
      <Dialog open={confirm} onOpenChange={setConfirm}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Isian belum tersimpan</DialogTitle>
            <DialogDescription>
              Kembali ke formulir untuk menyimpan, atau tinggalkan perubahan yang belum tersimpan.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirm(false)}>
              Kembali ke formulir
            </Button>
            <Button
              variant="destructive"
              onClick={() => {
                isDirty.current = false;
                setConfirm(false);
                const fn = pending.current;
                pending.current = null;
                fn?.();
              }}
            >
              Tinggalkan perubahan
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <Toaster position="bottom-right" />
    </WorkspaceContext.Provider>
  );
}
