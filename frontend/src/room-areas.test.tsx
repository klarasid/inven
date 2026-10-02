// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { RoomAreasTab, RoomPlansTab } from './room-areas'
import { WorkspaceContext, type ContextValue } from './context'

const sarpras={areaTypes:{baca:{label:'Area baca',group:'dasar'},diskusi:{label:'Ruang diskusi',group:'pendukung'},toilet:{label:'Toilet',group:'umum'}},areaGroups:{dasar:'Area layanan dasar',pendukung:'Area pendukung',umum:'Fasilitas umum'},categories:{},types:{}}
const details={areas:[{id:1,type:'baca',name:''},{id:2,type:'baca',name:'Pojok baca anak'},{id:3,type:'toilet',name:''}],plans:[{id:7,title:'Lantai 1',mime:'image/png',created_at:'2026-10-02 09:00:00',url:'http://localhost/plan?plan_id=7'},{id:8,title:'Jalur evakuasi',mime:'application/pdf',created_at:'2026-10-02 09:00:00',url:'http://localhost/plan?plan_id=8'}],maxPlanBytes:5*1024*1024,maxPlans:10}
const context=(write=true):ContextValue=>({config:{write,api:'http://localhost/api'} as ContextValue['config'],options:{sarpras} as unknown as ContextValue['options'],route:{view:'inventory',room:5},go:vi.fn(),back:vi.fn(),dirty:vi.fn(),refresh:vi.fn(),revision:0,mutate:vi.fn().mockResolvedValue({ok:true})})
const serve=(data:object)=>{const fetch=vi.fn().mockResolvedValue({ok:true,headers:{get:()=>'application/json'},json:async()=>({ok:true,data})});vi.stubGlobal('fetch',fetch);return fetch}
const mount=(w:ContextValue,tab:React.ReactNode)=>render(<WorkspaceContext.Provider value={w}>{tab}</WorkspaceContext.Provider>)
const card=(title:string)=>screen.getByText(title).closest('[data-slot="card"]') as HTMLElement
afterEach(()=>{cleanup();vi.unstubAllGlobals()})

test('the Area tab reads the room and lists its areas under their group',async()=>{
 const fetch=serve(details)
 mount(context(),<RoomAreasTab room="5"/>)
 await screen.findByText('Pojok baca anak')
 expect(String(fetch.mock.calls[0][0])).toBe('http://localhost/api?room=5&resource=areas')
 expect(within(card('Area layanan dasar')).getAllByText('Area baca')).toHaveLength(2)
 expect(within(card('Fasilitas umum')).getByText('Toilet')).toBeTruthy()
 expect(within(card('Area pendukung')).getByText('Belum ada.')).toBeTruthy()
 expect(screen.getByRole('button',{name:'Tambah area'})).toBeTruthy()
})

test('a room without areas says what to record, and a reader cannot add any',async()=>{
 serve({...details,areas:[]})
 mount(context(false),<RoomAreasTab room="5"/>)
 await screen.findByText('Belum ada area')
 expect(screen.queryByRole('button',{name:'Tambah area'})).toBeNull()
})

test('an area is not saved without its kind',async()=>{
 serve(details)
 const w=context();mount(w,<RoomAreasTab room="5"/>)
 fireEvent.click(await screen.findByRole('button',{name:'Tambah area'}))
 fireEvent.click(screen.getByRole('button',{name:'Simpan'}))
 expect(await screen.findByText('Pilih jenis area.')).toBeTruthy()
 expect(w.mutate).not.toHaveBeenCalled()
})

test('the Denah tab shows pictures as pictures and PDFs as documents, and a picture opens in a popup on the page',async()=>{
 serve(details)
 mount(context(),<RoomPlansTab room="5"/>)
 const picture=await screen.findByRole('img',{name:'Lantai 1'})
 expect(picture.getAttribute('src')).toBe('http://localhost/plan?plan_id=7')
 expect(screen.queryByRole('img',{name:'Jalur evakuasi'})).toBeNull()
 expect(screen.getByRole('button',{name:'Buka Jalur evakuasi'})).toBeTruthy()
 // No link leaves the page until the popup is open.
 expect(screen.queryByRole('link')).toBeNull()
 expect(screen.queryByRole('dialog')).toBeNull()
 fireEvent.click(screen.getByRole('button',{name:'Buka Lantai 1'}))
 const popup=await screen.findByRole('dialog',{name:'Lantai 1'})
 expect(within(popup).getByRole('img',{name:'Lantai 1'}).getAttribute('src')).toBe('http://localhost/plan?plan_id=7')
 expect(within(popup).getByRole('link',{name:'Buka ukuran penuh'}).getAttribute('href')).toBe('http://localhost/plan?plan_id=7')
 expect(screen.getByText('2 dari 10 denah. Hanya petugas yang masuk ke SLiMS yang dapat membukanya.')).toBeTruthy()
})

test('a room that already holds the most plans offers no upload',async()=>{
 serve({...details,maxPlans:2})
 mount(context(),<RoomPlansTab room="5"/>)
 await screen.findByText('Lantai 1')
 expect(screen.queryByRole('button',{name:'Unggah denah'})).toBeNull()
})

test('a plan is uploaded with its title, and a file of the wrong kind is refused before sending',async()=>{
 serve({...details,plans:[]})
 const w=context();mount(w,<RoomPlansTab room="5"/>)
 fireEvent.click(await screen.findByRole('button',{name:'Unggah denah'}))
 const input=screen.getByLabelText('Berkas denah')
 fireEvent.change(input,{target:{files:[new File(['x'],'denah.html',{type:'text/html'})]}})
 expect(screen.getByText('Gunakan PDF, JPEG, PNG, atau WebP maksimal 5 MB.')).toBeTruthy()
 expect((screen.getByRole('button',{name:'Unggah'}) as HTMLButtonElement).disabled).toBe(true)
 const plan=new File(['%PDF-1.4'],'denah-lantai-1.pdf',{type:'application/pdf'})
 fireEvent.change(input,{target:{files:[plan]}})
 fireEvent.change(screen.getByLabelText('Judul'),{target:{value:'Lantai 1'}})
 fireEvent.click(screen.getByRole('button',{name:'Unggah'}))
 await waitFor(()=>expect(w.mutate).toHaveBeenCalledTimes(1))
 const [values,files,inventory]=(w.mutate as ReturnType<typeof vi.fn>).mock.calls[0]
 expect(values).toEqual({form_action:'upload_plan',location_id:'5',title:'Lantai 1'})
 expect((files as FormData).get('plan')).toBe(plan)
 expect(inventory).toBe(true)
 await waitFor(()=>expect(w.refresh).toHaveBeenCalled())
})
