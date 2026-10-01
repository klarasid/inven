import { Alert, AlertDescription } from "./components/ui/alert";
import { Tabs, TabsList, TabsTrigger } from "./components/ui/tabs";
import { useWorkspace } from "./context";
import { PageHeader } from "./shared";
import { LetterheadManager } from "./letterheads";
import { DocumentNumbering } from "./documents";

const tabs = { letterheads: "Template kop", documents: "Nomor dokumen" } as const;

export function PrintSettingsPage() {
  const w = useWorkspace();
  const tab = String(w.route.tab || "") in tabs ? (w.route.tab as keyof typeof tabs) : "letterheads";
  return (
    <>
      <PageHeader
        title="Pengaturan Cetak"
        description="Kop institusi dan nomor dokumen yang dipakai setiap PDF: laporan periode, dokumen pemeriksaan, laporan kerusakan, dan Rekap Sarpras."
      />
      {!w.config.write && (
        <Alert>
          <AlertDescription>Hanya pengguna dengan hak tulis Stock Take yang bisa mengubah pengaturan cetak.</AlertDescription>
        </Alert>
      )}
      {/* Through the route, so leaving a tab with unsaved changes asks first. */}
      <Tabs value={tab} onValueChange={(v) => w.go({ view: "print-settings", tab: v }, true)}>
        <TabsList variant="line" className="w-full justify-start border-b">
          {Object.entries(tabs).map(([value, label]) => (
            <TabsTrigger key={value} value={value} className="flex-none">
              {label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>
      {tab === "letterheads" ? <LetterheadManager /> : <DocumentNumbering />}
    </>
  );
}
