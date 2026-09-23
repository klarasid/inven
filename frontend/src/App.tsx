import { useEffect, useRef, useState } from 'react'
import { ClipboardCheck, Building2, CalendarDays, ListChecks, ChartNoAxesCombined, Maximize, Minimize } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from './components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from './components/ui/dialog'
import { Toaster } from './components/ui/sonner'
import { Separator } from './components/ui/separator'
import { WorkspaceContext, mutation } from './context'
import { read } from './api'
import { Loading, ErrorBox } from './shared'
import { Tasks, InspectionPage, FindingPage } from './tasks'
import { SetupList, TemplatePage, SchedulePage } from './setup'
import { InventoryList, InventoryForm } from './inventory'
import { Reports } from './reports'
import { HistoryImportPage } from './history-import'
import type { Config, Options, Route } from './types'
export function initialRoute(config:Config):Route {
 const q=config.query;const base:Route={view:config.view};
 if(q.tab==='inspection'||q.tab==='finding')return {...base,view:q.tab,record:q.record}
 if(q.tab==='findings')return {view:'tasks',kind:'findings'}
 if(q.tab==='inspections')return {view:'tasks'}
 if(q.tab==='template'||q.tab==='setup'&&q.template_id)return {view:'template-edit',record:q.template_id}
 if(q.tab==='setup')return {view:q.panel==='templates'?'checklists':'schedules'}
 if(q.tab==='schedule')return {view:'schedule-edit',replaces_id:q.replaces_id,room:q.location_id,template_id:q.template_id}
 if(q.tab==='new')return {view:'new-inspection',parent_id:q.parent_id,room:q.location_id,template_id:q.template_id}
 if(q.action==='view_location')return {view:'inventory',room:q.location_id}
 if(q.action==='edit_item'||q.action==='add_item'||q.action==='view_photos')return {view:q.action==='view_photos'?'item-detail':'item-edit',record:q.record_id,room:q.location_id}
 if(q.action==='edit_location'||q.action==='add_location')return {view:'room-edit',record:q.record_id}
 return base
}
const menus=[{view:'tasks',label:'Tugas',icon:ClipboardCheck},{view:'inventory',label:'Ruangan & Barang',icon:Building2},{view:'schedules',label:'Jadwal',icon:CalendarDays},{view:'checklists',label:'Checklist',icon:ListChecks},{view:'reports',label:'Laporan',icon:ChartNoAxesCombined}]
export function App({config,host}:{config:Config;host:HTMLElement}){
 const [options,setOptions]=useState<Options>();const [error,setError]=useState('');const [route,setRoute]=useState<Route>(()=>initialRoute(config));const [revision,setRevision]=useState(0);const stack=useRef<Route[]>([]);const isDirty=useRef(false);const pending=useRef<(()=>void)|null>(null);const [confirm,setConfirm]=useState(false);const [syncing,setSyncing]=useState(false);const syncLock=useRef(false);const [fullscreen,setFullscreen]=useState(false);const canFullscreen=typeof host.requestFullscreen==='function'
 useEffect(()=>{const onChange=()=>setFullscreen(document.fullscreenElement===host);document.addEventListener('fullscreenchange',onChange);return()=>document.removeEventListener('fullscreenchange',onChange)},[host])
 const toggleFullscreen=()=>{if(document.fullscreenElement===host)document.exitFullscreen();else host.requestFullscreen().catch(()=>{})}
 const guard=(fn:()=>void)=>{if(isDirty.current){pending.current=fn;setConfirm(true)}else fn()}
 function go(next:Route,replace=false){guard(()=>{if(!replace)stack.current.push(route);setRoute(next);host.scrollIntoView({block:'start'});})}
 function back(){guard(()=>{setRoute(stack.current.pop()||{view:route.view.startsWith('item')||route.view==='room-edit'?'inventory':route.view.startsWith('template')?'checklists':route.view.startsWith('schedule')?'schedules':'tasks'})})}
 useEffect(()=>{const controller=new AbortController();read<Options>(config,'options',{},controller.signal).then(setOptions).catch(e=>{if(e.name!=='AbortError')setError(e.message)});return()=>controller.abort()},[config,revision])
 useEffect(()=>{
  const unload=(e:BeforeUnloadEvent)=>{if(isDirty.current){e.preventDefault();e.returnValue=''}}
  const click=(e:MouseEvent)=>{if(!isDirty.current||e.composedPath().includes(host))return;const target=e.target instanceof Element?e.target.closest<HTMLAnchorElement>('a[href]'):null;if(!target||target.target==='_blank')return;e.preventDefault();e.stopImmediatePropagation();pending.current=()=>target.click();setConfirm(true)}
  window.addEventListener('beforeunload',unload);document.addEventListener('click',click,true);return()=>{window.removeEventListener('beforeunload',unload);document.removeEventListener('click',click,true)}
 },[host])
 useEffect(()=>{
  if(!options||!config.write||route.view!=='tasks'||syncLock.current)return
  let stopped=false;syncLock.current=true;setSyncing(true)
  ;(async()=>{let more=true,changed=false;try{while(more&&!stopped){const r=await mutation(config,options,{watch_action:'sync'});more=!!r.more;changed=changed||!!r.generated}if(changed&&!stopped&&!isDirty.current)setRevision(n=>n+1)}catch(e){if(!stopped)toast.error(`Jadwal belum tersinkron: ${(e as Error).message}`)}finally{syncLock.current=false;if(!stopped)setSyncing(false)}})()
  return()=>{stopped=true}
 },[options?.csrf,config,route.view])
 const active=route.view.startsWith('item')||route.view==='room-edit'?'inventory':route.view.startsWith('template')?'checklists':route.view.startsWith('schedule')?'schedules':['inspection','finding','new-inspection','history-import'].includes(route.view)?'tasks':route.view
 if(error)return <div className="p-6"><ErrorBox message={error}/><Button variant="outline" onClick={()=>{setError('');setRevision(n=>n+1)}}>Coba lagi</Button></div>
 if(!options)return <div className="p-6"><Loading/></div>
 let page
 switch(route.view){case 'history-import':page=<HistoryImportPage/>;break;case 'tasks':page=<Tasks/>;break;case 'inspection':page=<InspectionPage/>;break;case 'finding':page=<FindingPage/>;break;case 'inventory':page=<InventoryList/>;break;case 'item-edit':case 'item-detail':case 'room-edit':page=<InventoryForm/>;break;case 'schedules':case 'checklists':page=<SetupList/>;break;case 'template-edit':case 'template-detail':page=<TemplatePage/>;break;case 'schedule-edit':case 'schedule-detail':case 'new-inspection':page=<SchedulePage/>;break;case 'reports':page=<Reports/>;break;default:page=<ErrorBox message="Halaman tidak ditemukan."/>}
 return <WorkspaceContext.Provider value={{config,options,route,go,back,dirty:v=>{isDirty.current=v},revision,refresh:()=>setRevision(n=>n+1),mutate:(values,files,inventory)=>mutation(config,options,values,files,inventory)}}><div className="min-h-[70vh] bg-background text-foreground font-sans"><div className="flex flex-col gap-4 border-b px-4 py-5 md:px-8"><div className="flex items-center justify-between gap-2"><span className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">Inventaris perpustakaan</span><div className="flex items-center gap-2"><span className="text-xs text-muted-foreground">{!config.write?'Akses baca':options.users.find(u=>Number(u.user_id)===config.uid)?.realname}</span>{canFullscreen&&<Button variant="outline" size="icon-sm" className="md:hidden" onClick={toggleFullscreen} aria-label={fullscreen?'Keluar layar penuh':'Layar penuh'}>{fullscreen?<Minimize/>:<Maximize/>}</Button>}</div></div><nav aria-label="Inventaris barang" className="flex gap-1 overflow-x-auto">{menus.map(({view,label,icon:Icon})=><Button key={view} variant={active===view?'secondary':'ghost'} onClick={()=>go({view})} aria-current={active===view?'page':undefined}><Icon data-icon="inline-start"/>{label}</Button>)}</nav></div><main className="mx-auto flex max-w-6xl flex-col gap-6 p-4 md:p-8" key={`${route.view}:${route.record||''}:${route.replaces_id||''}:${route.parent_id||''}`}>{syncing&&<p role="status" className="text-xs text-muted-foreground">Memperbarui tugas dari jadwal…</p>}{page}</main><Separator/><footer className="px-8 py-4 text-xs text-muted-foreground">Inventaris Barang · SLiMS</footer></div><Dialog open={confirm} onOpenChange={setConfirm}><DialogContent><DialogHeader><DialogTitle>Isian belum tersimpan</DialogTitle><DialogDescription>Kembali ke formulir untuk menyimpan, atau tinggalkan perubahan yang belum tersimpan.</DialogDescription></DialogHeader><DialogFooter><Button variant="outline" onClick={()=>setConfirm(false)}>Kembali ke formulir</Button><Button onClick={()=>{isDirty.current=false;setConfirm(false);const fn=pending.current;pending.current=null;fn?.()}}>Tinggalkan perubahan</Button></DialogFooter></DialogContent></Dialog><Toaster position="bottom-right"/></WorkspaceContext.Provider>
}
