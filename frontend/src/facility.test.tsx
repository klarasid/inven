// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { FacilityPage } from './facility'
import { WorkspaceContext, type ContextValue } from './context'

const places=[{code:'00',name:'Kampus Tembalang',rooms:5},{code:'10',name:'Kampus Tegal',rooms:1}]
const settings={designed:false,building_area:0,bandwidth_mbps:0,bandwidth_users:0,bandwidth_coverage:'all',bandwidth_date:''}
const support={documents:[],topics:{jaringan:'Jaringan internet',keamanan:'Keamanan dan keselamatan'},kinds:{speedtest:{label:'Hasil uji kecepatan',topic:'jaringan',room:true},isp:{label:'Layanan ISP',topic:'jaringan',room:false},pos:{label:'POS tanggap darurat dan sistem keamanan',topic:'keamanan',room:false}},rooms:[{id:4,name:'Ruang baca'}],max_bytes:5242880,max:100}
const data=(more:object={})=>({settings,support,sivitas:{count:1200,single:false,unmapped:30},coverage:{all:'Seluruh area layanan',partial:'Sebagian area layanan'},levels:{a:'Sangat baik',b:'Baik',c:'Cukup',d:'Kurang'},
 result:[{no:1,name:'Luas gedung dan ruang',value:'Belum diisi',level:null,checks:[{label:'Ada sivitas (anggota aktif) yang dilayani',ok:true}]},{no:6,name:'Jaringan internet',value:'Belum diisi',level:null,checks:[]}],
 locations:places,location:places[1],write:true,csrf:'token',...more})
const pages={sarpras:'http://localhost/sarpras.php',software:'http://localhost/software.php',facility:'http://localhost/facility.php'}
const context=(route:ContextValue['route']={view:'facility',library:'10'}):ContextValue=>({config:{write:true,pages,today:'2026-10-02'} as ContextValue['config'],options:{} as ContextValue['options'],route,go:vi.fn(),back:vi.fn(),dirty:vi.fn(),refresh:vi.fn(),revision:0,mutate:vi.fn()})
function mount(w:ContextValue){return render(<WorkspaceContext.Provider value={w}><FacilityPage/></WorkspaceContext.Provider>)}
const reply=(body:object)=>({ok:true,headers:{get:()=>'application/json'},json:async()=>body})
afterEach(()=>{cleanup();vi.unstubAllGlobals()})

test('figures are kept per location: saved only once changed, and what they amount to is shown beside them',async()=>{
 const fetch=vi.fn((_:string,init:RequestInit)=>Promise.resolve(init.method==='POST'?reply({ok:true,message:'Data gedung dan jaringan tersimpan.'}):reply({ok:true,data:data()})))
 vi.stubGlobal('fetch',fetch)
 const w=context();mount(w)
 const area=await screen.findByLabelText('Luas gedung (m²)') as HTMLInputElement
 expect(fetch.mock.calls[0][0]).toBe('http://localhost/facility.php?library=10&format=json')
 // Sivitas is counted from members, not typed in here.
 expect(screen.getByText('1.200 orang')).toBeTruthy()
 expect(screen.getByText(/30 anggota belum dipetakan/)).toBeTruthy()
 // The effect of the saved figures on the recap.
 expect(screen.getByText('Hasil di Rekap Sarpras')).toBeTruthy()
 expect(screen.getByText('Dihitung dari data yang tersimpan.')).toBeTruthy()
 expect(screen.getByText('Ada sivitas (anggota aktif) yang dilayani')).toBeTruthy()
 // Nothing to save until something changes; undoing the change takes the offer back.
 const save=screen.getByRole('button',{name:'Simpan'}) as HTMLButtonElement
 expect(save.disabled).toBe(true)
 fireEvent.change(area,{target:{value:'500'}})
 expect(save.disabled).toBe(false)
 expect(w.dirty).toHaveBeenLastCalledWith(true)
 expect(screen.getByText('Simpan perubahan untuk memperbarui hasil ini.')).toBeTruthy()
 fireEvent.click(screen.getByRole('button',{name:'Batalkan'}))
 expect(area.value).toBe('')
 expect(save.disabled).toBe(true)
 expect(w.dirty).toHaveBeenLastCalledWith(false)
 // Saving goes to this location.
 fireEvent.change(area,{target:{value:'900'}})
 expect(screen.getByText('Sekitar 0,75 m² per orang.')).toBeTruthy()
 fireEvent.click(save)
 await waitFor(()=>expect(fetch.mock.calls.some(([,init])=>init.method==='POST')).toBe(true))
 const [target,init]=fetch.mock.calls.find(([,init])=>init.method==='POST')!
 const body=init.body as FormData
 expect(target).toBe('http://localhost/facility.php')
 expect(Object.fromEntries(['action','library','csrf','sivitas','building_area','designed'].map(k=>[k,body.get(k)]))).toEqual({action:'settings',library:'10',csrf:'token',sivitas:null,building_area:'900',designed:''})
 fireEvent.click(screen.getByRole('button',{name:'Atur'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'sivitas'})
 // The recap that opens from here is this location's.
 fireEvent.click(screen.getByRole('button',{name:/Lihat Rekap Sarpras/}))
 expect(w.go).toHaveBeenLastCalledWith({view:'sarpras',library:'10'})
})

test('the network figures point to the supporting documents for their evidence, and take no file themselves',async()=>{
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:data()})))
 mount(context())
 await screen.findByLabelText('Bandwidth total (Mbps)')
 expect(screen.getByText(/Unggah hasil uji kecepatannya di Dokumen pendukung/)).toBeTruthy()
 expect(screen.queryByRole('button',{name:'Unggah bukti'})).toBeNull()
 expect(document.querySelector('input[type=file]')).toBeNull()
})

test('readers see the figures and their result but cannot change them',async()=>{
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:data({write:false,csrf:'',locations:[],location:null})})))
 mount(context({view:'facility'}))
 const area=await screen.findByLabelText('Luas gedung (m²)') as HTMLInputElement
 expect(area.closest('fieldset')!.disabled).toBe(true)
 expect(screen.queryByRole('button',{name:'Simpan'})).toBeNull()
 expect(screen.queryByRole('combobox',{name:'Lokasi perpustakaan'})).toBeNull()
 expect(screen.getByText('Hasil di Rekap Sarpras')).toBeTruthy()
})

test('supporting documents are listed by topic with their kind and room, added from a dialog, and removed after confirming',async()=>{
 const documents=[{id:7,kind:'speedtest',topic:'jaringan',title:'Uji kecepatan ruang baca',mime:'image/png',created_at:'2026-10-01 08:00:00',room:{id:4,name:'Ruang baca'}},{id:8,kind:'isp',topic:'jaringan',title:'Tagihan ISP',mime:'application/pdf',created_at:'2026-10-01 08:00:00',room:null},{id:9,kind:'pos',topic:'keamanan',title:'POS kebakaran',mime:'application/pdf',created_at:'2026-10-01 08:00:00',room:null}]
 const fetch=vi.fn((_:string,init:RequestInit)=>Promise.resolve(init.method==='POST'?reply({ok:true,message:'Dokumen pendukung tersimpan.'}):reply({ok:true,data:data({support:{...support,documents}})})))
 vi.stubGlobal('fetch',fetch)
 mount(context())
 expect(await screen.findByText(/Hasil uji kecepatan · Ruang baca · Diunggah/)).toBeTruthy()
 expect(screen.getByText(/^Layanan ISP · Diunggah/)).toBeTruthy()
 // Security and safety documents stand under their own heading.
 expect(screen.getByRole('heading',{name:'Jaringan internet',level:3}).parentElement!.textContent).toContain('Tagihan ISP')
 const safety=screen.getByRole('heading',{name:'Keamanan dan keselamatan',level:3}).parentElement!
 expect(safety.textContent).toContain('POS kebakaran')
 expect(safety.textContent).not.toContain('Tagihan ISP')
 // A picture opens in a popup on the page.
 fireEvent.click(screen.getByRole('button',{name:'Lihat Uji kecepatan ruang baca'}))
 const popup=await screen.findByRole('dialog',{name:'Uji kecepatan ruang baca'})
 expect(popup.querySelector('img')?.getAttribute('src')).toBe('http://localhost/facility.php?document=7&library=10')
 fireEvent.keyDown(popup,{key:'Escape'})
 // Adding: a speed test unless another kind is chosen, for this location.
 fireEvent.click(screen.getByRole('button',{name:'Tambah dokumen'}))
 const dialog=await screen.findByRole('dialog',{name:'Tambah dokumen pendukung'})
 const upload=screen.getByRole('button',{name:'Unggah'}) as HTMLButtonElement
 expect(upload.disabled).toBe(true)
 fireEvent.change(dialog.querySelector('input[type=file]')!,{target:{files:[new File(['x'],'catatan.txt',{type:'text/plain'})]}})
 expect(screen.getByText('Gunakan PDF, JPEG, PNG, atau WebP maksimal 5 MB.')).toBeTruthy()
 expect(upload.disabled).toBe(true)
 fireEvent.change(dialog.querySelector('input[type=file]')!,{target:{files:[new File(['x'],'speedtest.png',{type:'image/png'})]}})
 fireEvent.change(screen.getByLabelText('Judul'),{target:{value:'Uji lantai 2'}})
 fireEvent.click(upload)
 await waitFor(()=>expect(fetch.mock.calls.some(([,init])=>init.method==='POST')).toBe(true))
 const body=fetch.mock.calls.find(([,init])=>init.method==='POST')![1].body as FormData
 expect(Object.fromEntries(['action','kind','room_id','title','library','csrf'].map(k=>[k,body.get(k)]))).toEqual({action:'document_upload',kind:'speedtest',room_id:'',title:'Uji lantai 2',library:'10',csrf:'token'})
 expect((body.get('document') as File).name).toBe('speedtest.png')
 // Removing asks first.
 fetch.mockClear()
 fireEvent.click(await screen.findByRole('button',{name:'Hapus Tagihan ISP'}))
 fireEvent.click(await screen.findByRole('button',{name:'Hapus dokumen'}))
 await waitFor(()=>expect(fetch.mock.calls.some(([,init])=>init.method==='POST')).toBe(true))
 const removed=fetch.mock.calls.find(([,init])=>init.method==='POST')![1].body as FormData
 expect([removed.get('action'),removed.get('record_id'),removed.get('library')]).toEqual(['document_delete','8','10'])
})

test('readers open supporting documents but cannot add or remove them; before the migration the page says what to run',async()=>{
 const documents=[{id:8,kind:'isp',topic:'jaringan',title:'Tagihan ISP',mime:'application/pdf',created_at:'2026-10-01 08:00:00',room:null}]
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:data({write:false,csrf:'',support:{...support,documents}})})))
 mount(context())
 const view=await screen.findByRole('button',{name:'Lihat Tagihan ISP'}) as HTMLButtonElement
 expect(view.disabled).toBe(false)
 expect(screen.queryByRole('button',{name:'Tambah dokumen'})).toBeNull()
 expect(screen.queryByRole('button',{name:'Hapus Tagihan ISP'})).toBeNull()
 cleanup()
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:data({support:{...support,documents:null}})})))
 mount(context())
 expect(await screen.findByText(/Jalankan migrasi plugin hingga versi 17/)).toBeTruthy()
 expect(screen.queryByRole('button',{name:'Tambah dokumen'})).toBeNull()
})
