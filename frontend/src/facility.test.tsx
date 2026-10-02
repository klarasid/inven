// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { FacilityPage } from './facility'
import { WorkspaceContext, type ContextValue } from './context'

const places=[{code:'00',name:'Kampus Tembalang',rooms:5},{code:'10',name:'Kampus Tegal',rooms:1}]
const settings={sivitas:1200,designed:false,building_area:0,bandwidth_mbps:0,bandwidth_users:0,bandwidth_coverage:'all',bandwidth_date:'',evidence:null}
const data=(more:object={})=>({settings,coverage:{all:'Seluruh area layanan',partial:'Sebagian area layanan'},levels:{a:'Sangat baik',b:'Baik',c:'Cukup',d:'Kurang'},
 result:[{no:1,name:'Luas gedung dan ruang',value:'Belum diisi',level:null,checks:[{label:'Jumlah sivitas akademika diisi',ok:true}]},{no:6,name:'Jaringan internet',value:'Belum diisi',level:null,checks:[]}],
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
 const sivitas=await screen.findByLabelText('Jumlah sivitas akademika') as HTMLInputElement
 expect(fetch.mock.calls[0][0]).toBe('http://localhost/facility.php?library=10&format=json')
 expect(sivitas.value).toBe('1200')
 // The effect of the saved figures on the recap.
 expect(screen.getByText('Hasil di Rekap Sarpras')).toBeTruthy()
 expect(screen.getByText('Dihitung dari data yang tersimpan.')).toBeTruthy()
 expect(screen.getByText('Jumlah sivitas akademika diisi')).toBeTruthy()
 // Nothing to save until something changes; undoing the change takes the offer back.
 const save=screen.getByRole('button',{name:'Simpan'}) as HTMLButtonElement
 expect(save.disabled).toBe(true)
 fireEvent.change(sivitas,{target:{value:'1500'}})
 expect(save.disabled).toBe(false)
 expect(w.dirty).toHaveBeenLastCalledWith(true)
 expect(screen.getByText('Simpan perubahan untuk memperbarui hasil ini.')).toBeTruthy()
 fireEvent.click(screen.getByRole('button',{name:'Batalkan'}))
 expect(sivitas.value).toBe('1200')
 expect(save.disabled).toBe(true)
 expect(w.dirty).toHaveBeenLastCalledWith(false)
 // Saving goes to this location.
 fireEvent.change(sivitas,{target:{value:'1500'}})
 fireEvent.change(screen.getByLabelText('Luas gedung (m²)'),{target:{value:'900'}})
 expect(screen.getByText('Sekitar 0,6 m² per orang.')).toBeTruthy()
 fireEvent.click(save)
 await waitFor(()=>expect(fetch.mock.calls.some(([,init])=>init.method==='POST')).toBe(true))
 const [target,init]=fetch.mock.calls.find(([,init])=>init.method==='POST')!
 const body=init.body as FormData
 expect(target).toBe('http://localhost/facility.php')
 expect(Object.fromEntries(['action','library','csrf','sivitas','building_area','designed'].map(k=>[k,body.get(k)]))).toEqual({action:'settings',library:'10',csrf:'token',sivitas:'1500',building_area:'900',designed:''})
 // The recap that opens from here is this location's.
 fireEvent.click(screen.getByRole('button',{name:/Lihat Rekap Sarpras/}))
 expect(w.go).toHaveBeenLastCalledWith({view:'sarpras',library:'10'})
})

test('evidence is uploaded as soon as it is chosen',async()=>{
 const fetch=vi.fn((_:string,init:RequestInit)=>Promise.resolve(init.method==='POST'?reply({ok:true,message:'Bukti pengukuran tersimpan.'}):reply({ok:true,data:data()})))
 vi.stubGlobal('fetch',fetch)
 mount(context())
 await screen.findByRole('button',{name:'Unggah bukti'})
 const file=new File(['speedtest'],'speedtest.png',{type:'image/png'})
 fireEvent.change(screen.getByLabelText('Berkas bukti pengukuran'),{target:{files:[file]}})
 await waitFor(()=>expect(fetch.mock.calls.some(([,init])=>init.method==='POST')).toBe(true))
 const body=fetch.mock.calls.find(([,init])=>init.method==='POST')![1].body as FormData
 expect(body.get('action')).toBe('evidence')
 expect(body.get('library')).toBe('10')
 expect((body.get('evidence') as File).name).toBe('speedtest.png')
})

test('readers see the figures and their result but cannot change them',async()=>{
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:data({write:false,csrf:'',locations:[],location:null})})))
 mount(context({view:'facility'}))
 const sivitas=await screen.findByLabelText('Jumlah sivitas akademika') as HTMLInputElement
 expect(sivitas.closest('fieldset')!.disabled).toBe(true)
 expect(screen.queryByRole('button',{name:'Simpan'})).toBeNull()
 expect(screen.queryByRole('button',{name:'Unggah bukti'})).toBeNull()
 expect(screen.queryByRole('combobox',{name:'Lokasi perpustakaan'})).toBeNull()
 expect(screen.getByText('Hasil di Rekap Sarpras')).toBeTruthy()
})

test('picture evidence opens in a popup on the page; a PDF is left to the browser',async()=>{
 const evidence=(name:string,mime:string)=>data({settings:{...settings,evidence:{name,mime,uploaded_at:'2026-10-01 08:00:00'}}})
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:evidence('speedtest.png','image/png')})))
 mount(context())
 fireEvent.click(await screen.findByRole('button',{name:'Lihat'}))
 const popup=await screen.findByRole('dialog',{name:'speedtest.png'})
 expect(popup.querySelector('img')?.getAttribute('src')).toBe('http://localhost/facility.php?evidence=1&library=10')
 cleanup()
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:evidence('speedtest.pdf','application/pdf')})))
 mount(context())
 const link=await screen.findByRole('link',{name:'Lihat'})
 expect(link.getAttribute('href')).toBe('http://localhost/facility.php?evidence=1&library=10')
 expect(link.getAttribute('target')).toBe('_blank')
})
