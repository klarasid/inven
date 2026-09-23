import { useRef, useState } from 'react'
import { Download, Upload } from 'lucide-react'
import { useWorkspace } from './context'
import { url, dateLabel } from './api'
import { Button } from './components/ui/button'
import { Input } from './components/ui/input'
import { Field, FieldLabel } from './components/ui/field'
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from './components/ui/table'
import { Heading, Panel, ErrorBox } from './shared'

type Summary = {inspections:number;results:number;findings:number;actions:number;closed:number;review:number;open:number;token?:string;ids?:number[];rows:{number:string;date:string;room:string;library:string;examiner:string;results:number;actions:number;closed:number}[]}
export function HistoryImportPage() {
  const w=useWorkspace()
  const [file,setFile]=useState<File>()
  const [preview,setPreview]=useState<Summary>()
  const [saved,setSaved]=useState<Summary>()
  const [error,setError]=useState('')
  const [busy,setBusy]=useState(false)
  const lock=useRef(false)
  async function run(commit:boolean) {
    if(lock.current)return
    lock.current=true;setBusy(true);setError('')
    try {
      if(commit) {
        const reply=await w.mutate({watch_action:'history_commit',token:preview?.token})
        setSaved(reply.data as Summary);setPreview(undefined);w.dirty(false);w.refresh()
      } else {
        if(!file)throw new Error('Pilih berkas Excel terlebih dahulu.')
        if(!file.name.toLowerCase().endsWith('.xlsx')||file.size>2*1024*1024)throw new Error('Gunakan Excel .xlsx maksimal 2 MB.')
        setPreview(undefined)
        const data=new FormData();data.append('workbook',file)
        const reply=await w.mutate({watch_action:'history_preview'},data)
        setPreview(reply.data as Summary)
      }
    } catch(e) {setError((e as Error).message)}
    finally {lock.current=false;setBusy(false)}
  }
  if(!w.config.write)return <ErrorBox message="Anda memerlukan hak tulis untuk mengimpor riwayat."/>
  return <>
    <Heading back title="Impor riwayat pekerjaan" description="Masukkan hasil pemeriksaan dan perbaikan atau pemeliharaan yang sudah dilaksanakan."/>
    <Panel title="1. Isi template Excel" description="Gunakan ID ruang dan petugas dari lembar referensi dalam template.">
      <Button variant="outline" asChild><a className="notAJAX" href={url(w.config.watch,{tab:'history_template'})} download><Download data-icon="inline-start"/>Unduh template Excel</a></Button>
      <p className="mt-4 text-sm text-muted-foreground">Isi lembar Pemeriksaan dan Tindak_lanjut. Hubungkan keduanya dengan nomor pemeriksaan dan nomor butir. Petunjuk dan contoh tersedia di dalam template.</p>
      <p className="mt-2 text-sm text-muted-foreground">Tanggal lama dipertahankan. Pekerjaan tanpa data verifikasi masuk antrean verifikasi; isi data verifikasi lama hanya jika tersedia. Foto tidak diimpor; referensi bukti arsip dapat dicantumkan.</p>
    </Panel>
    {!saved&&<Panel title="2. Unggah dan periksa" description="Maksimal 2 MB, 100 pemeriksaan, 500 butir dan 500 tindakan. Validasi belum menyimpan riwayat.">
      <fieldset disabled={busy} className="flex flex-col gap-4">
        <Field><FieldLabel htmlFor="history-workbook">Berkas Excel (.xlsx)</FieldLabel><Input id="history-workbook" type="file" accept=".xlsx" onChange={e=>{setFile(e.target.files?.[0]);setPreview(undefined);setError('');w.dirty(!!e.target.files?.length)}}/></Field>
        <Button className="self-start" disabled={!file||busy} onClick={()=>run(false)}><Upload data-icon="inline-start"/>{busy?'Memproses…':'Validasi berkas'}</Button>
      </fieldset>
    </Panel>}
    <ErrorBox message={error}/>
    {preview&&<Panel title="3. Periksa pratinjau lalu simpan" description="Seluruh data disimpan bersama. Nomor pemeriksaan yang sudah pernah diimpor akan ditolak.">
      <p>{preview.inspections} pemeriksaan · {preview.results} butir · {preview.actions} pekerjaan</p>
      <p className="my-3 text-sm">Tindak lanjut: {preview.closed} selesai dengan verifikasi historis, {preview.review} menunggu verifikasi, {preview.open} belum dikerjakan.</p>
      <Table><TableHeader><TableRow><TableHead>Nomor / tanggal</TableHead><TableHead>Ruang / lokasi</TableHead><TableHead>Pemeriksa</TableHead><TableHead>Butir / pekerjaan</TableHead></TableRow></TableHeader><TableBody>{preview.rows.map(row=><TableRow key={row.number}><TableCell>{row.number}<p className="text-xs text-muted-foreground">{dateLabel(row.date)}</p></TableCell><TableCell>{row.room}<p className="text-xs text-muted-foreground">{row.library}</p></TableCell><TableCell>{row.examiner}</TableCell><TableCell>{row.results} / {row.actions}</TableCell></TableRow>)}</TableBody></Table>
      <Button className="mt-4" disabled={busy} onClick={()=>run(true)}>{busy?'Menyimpan…':'Simpan impor'}</Button>
    </Panel>}
    {saved&&<Panel title="Riwayat berhasil diimpor">
      <p role="status">{saved.inspections} pemeriksaan dan {saved.actions} pekerjaan tersimpan.</p>
      <div className="mt-4 flex flex-wrap gap-3"><Button onClick={()=>w.go({view:'tasks',history:'1',owner:'all'})}>Lihat riwayat pemeriksaan</Button>{saved.review>0&&<Button variant="outline" onClick={()=>w.go({view:'tasks',kind:'review'})}>Lihat antrean verifikasi</Button>}<Button variant="outline" onClick={()=>{setSaved(undefined);setFile(undefined);setError('')}}>Impor berkas lain</Button></div>
    </Panel>}
  </>
}
