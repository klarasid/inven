// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { AreaDialog, RoomAreasTab, RoomPlansTab } from './room-areas'
import { WorkspaceContext, type ContextValue } from './context'

const sarpras={areaTypes:{baca:{label:'Area baca',group:'dasar'},diskusi:{label:'Ruang diskusi',group:'pendukung'},toilet:{label:'Toilet',group:'umum'}},areaGroups:{dasar:'Area layanan dasar',pendukung:'Area pendukung',umum:'Fasilitas umum'},categories:{},types:{}}
const details={maxAreaPhotos:3,maxPhotoBytes:2097152,areas:[{id:1,type:'baca',name:'',photos:[]},{id:2,type:'baca',name:'Pojok baca anak',photos:[]},{id:3,type:'toilet',name:'',photos:[{id:11,created_at:'2026-10-05 09:00:00',url:'http://localhost/photo?photo_id=11'},{id:12,created_at:'2026-10-05 09:00:00',url:'http://localhost/photo?photo_id=12'}]}],plans:[{id:7,title:'Lantai 1',mime:'image/png',created_at:'2026-10-02 09:00:00',url:'http://localhost/plan?plan_id=7'},{id:8,title:'Jalur evakuasi',mime:'application/pdf',created_at:'2026-10-02 09:00:00',url:'http://localhost/plan?plan_id=8'}],maxPlanBytes:5*1024*1024,maxPlans:10}
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

// The form as the list opens it for an area: from a menu, which jsdom cannot open.
const form=(w:ContextValue,area:object)=>{
 mount(w,<AreaDialog room="5" area={area as never} maxPhotos={3} maxPhotoBytes={2097152} onClose={vi.fn()} onSaved={w.refresh}/>)
 return screen.findByRole('dialog',{name:'Ubah area'})
}

test('the kinds of area are offered under the heading of their group',async()=>{
 // What the select needs to open that jsdom lacks.
 Element.prototype.hasPointerCapture??=()=>false
 Element.prototype.scrollIntoView??=()=>{}
 globalThis.ResizeObserver??=class{observe(){}unobserve(){}disconnect(){}} as unknown as typeof ResizeObserver
 const w=context()
 mount(w,<AreaDialog room="5" maxPhotos={3} maxPhotoBytes={2097152} onClose={vi.fn()} onSaved={vi.fn()}/>)
 fireEvent.keyDown(await screen.findByRole('combobox',{name:/Jenis area/}),{key:'ArrowDown'})
 const basic=(await screen.findByText('Area layanan dasar')).closest('[data-slot="select-group"]') as HTMLElement
 expect(within(basic).getByRole('option',{name:'Area baca'})).toBeTruthy()
 expect(within(basic).queryByRole('option',{name:'Toilet'})).toBeNull()
 const general=screen.getByText('Fasilitas umum').closest('[data-slot="select-group"]') as HTMLElement
 expect(within(general).getByRole('option',{name:'Toilet'})).toBeTruthy()
 // The group is the heading, no longer part of each kind's own text.
 expect(screen.queryByText(/Toilet ·/)).toBeNull()
})

test('a new area takes its photos too: they go to the id the server gave it',async()=>{
 Element.prototype.hasPointerCapture??=()=>false
 Element.prototype.scrollIntoView??=()=>{}
 globalThis.ResizeObserver??=class{observe(){}unobserve(){}disconnect(){}} as unknown as typeof ResizeObserver
 const w=context();(w.mutate as ReturnType<typeof vi.fn>).mockResolvedValue({ok:true,message:'Area tersimpan.',record:9})
 const saved=vi.fn()
 mount(w,<AreaDialog room="5" maxPhotos={3} maxPhotoBytes={2097152} onClose={vi.fn()} onSaved={saved}/>)
 fireEvent.keyDown(await screen.findByRole('combobox',{name:/Jenis area/}),{key:'ArrowDown'})
 fireEvent.keyDown(await screen.findByRole('option',{name:'Toilet'}),{key:'Enter'})
 const photo=new File(['a'],'toilet.jpg',{type:'image/jpeg'})
 fireEvent.change(screen.getByLabelText('Foto area'),{target:{files:[photo]}})
 fireEvent.click(screen.getByRole('button',{name:'Simpan'}))
 await waitFor(()=>expect(saved).toHaveBeenCalled())
 const calls=(w.mutate as ReturnType<typeof vi.fn>).mock.calls
 expect(calls.map(([values])=>values)).toEqual([{form_action:'save_area',location_id:'5',record_id:0,type:'toilet',name:''},{form_action:'upload_area_photo',location_id:'5',area_id:9}])
 expect((calls[1][1] as FormData).get('photo')).toBe(photo)
})

test('an area shows its photos in the list, and one opens in a popup on the page',async()=>{
 serve(details)
 mount(context(false),<RoomAreasTab room="5"/>)
 const photo=await screen.findByRole('button',{name:'Lihat foto 2 Toilet'})
 expect(within(card('Fasilitas umum')).getAllByRole('button',{name:/Lihat foto/})).toHaveLength(2)
 expect(within(card('Area layanan dasar')).queryByRole('button',{name:/Lihat foto/})).toBeNull()
 fireEvent.click(photo)
 const popup=await screen.findByRole('dialog',{name:'Foto Toilet'})
 expect(within(popup).getByRole('img',{name:'Foto Toilet'}).getAttribute('src')).toBe('http://localhost/photo?photo_id=12')
})

test('the area form takes photos and uploads them once the area is saved, up to what the area may still hold',async()=>{
 const w=context();(w.mutate as ReturnType<typeof vi.fn>).mockResolvedValue({ok:true,message:'Area tersimpan.',record:3})
 const dialog=await form(w,details.areas[2])
 // Two are kept already: they show in the form, and one more fits.
 expect(within(dialog).getAllByRole('img')).toHaveLength(2)
 const picker=within(dialog).getByLabelText('Foto area') as HTMLInputElement
 expect(picker.multiple).toBe(true)
 const one=new File(['a'],'toilet-1.jpg',{type:'image/jpeg'}),two=new File(['b'],'toilet-2.jpg',{type:'image/jpeg'})
 fireEvent.change(picker,{target:{files:[one,two]}})
 expect(within(dialog).getByText('Satu area memuat paling banyak 3 foto. Pilih paling banyak 1 foto lagi.')).toBeTruthy()
 fireEvent.click(within(dialog).getByRole('button',{name:'Simpan'}))
 expect(w.mutate).not.toHaveBeenCalled()
 fireEvent.change(picker,{target:{files:[new File(['x'],'catatan.pdf',{type:'application/pdf'})]}})
 expect(within(dialog).getByText('Gunakan foto JPEG, PNG, atau WebP maksimal 2 MB.')).toBeTruthy()
 fireEvent.change(picker,{target:{files:[one]}})
 fireEvent.click(within(dialog).getByRole('button',{name:'Simpan'}))
 await waitFor(()=>expect(w.mutate).toHaveBeenCalledTimes(2))
 const calls=(w.mutate as ReturnType<typeof vi.fn>).mock.calls
 expect(calls[0][0]).toEqual({form_action:'save_area',location_id:'5',record_id:3,type:'toilet',name:''})
 expect(calls[1][0]).toEqual({form_action:'upload_area_photo',location_id:'5',area_id:3})
 expect((calls[1][1] as FormData).get('photo')).toBe(one)
 await waitFor(()=>expect(w.refresh).toHaveBeenCalled())
})

test('a photo is removed from the area form, and before the migration the form says what to run',async()=>{
 const w=context();(w.mutate as ReturnType<typeof vi.fn>).mockResolvedValue({ok:true,message:'Foto area dihapus.'})
 const dialog=await form(w,details.areas[2])
 fireEvent.click(within(dialog).getByRole('button',{name:'Hapus foto 1'}))
 await waitFor(()=>expect(w.mutate).toHaveBeenCalledTimes(1))
 expect((w.mutate as ReturnType<typeof vi.fn>).mock.calls[0][0]).toEqual({form_action:'delete_area_photo',location_id:'5',record_id:11})
 await waitFor(()=>expect(w.refresh).toHaveBeenCalled())
 cleanup()
 const unmigrated=await form(context(),{id:3,type:'toilet',name:'',photos:null})
 expect(within(unmigrated).getByText(/Jalankan migrasi plugin hingga versi 19/)).toBeTruthy()
 expect(unmigrated.querySelector('input[type=file]')).toBeNull()
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
