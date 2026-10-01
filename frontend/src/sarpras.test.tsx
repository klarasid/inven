// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { SarprasPage } from './sarpras'
import { WorkspaceContext, type ContextValue } from './context'

const levels={a:'Sangat baik',b:'Baik',c:'Cukup',d:'Kurang'}
const aspect=(no:number,name:string,level:string|null,sources:string[],more:object={})=>({no,section:'Perangkat TI dan multimedia',name,value:`Capaian ${no}`,level,basis:'',checks:[],rows:[],columns:[],fix:`Saran ${no}`,sources,...more})
const recap={generated_at:'2026-10-01 08:00:00',aspects:[aspect(1,'Luas gedung dan ruang','d',['inventory','facility']),aspect(8,'Lisensi perangkat lunak',null,['software']),aspect(11,'Pemeriksaan rutin dan tindak lanjut','a',['schedules'])],counts:{rooms:2,items:5,uncategorized:0,unclassified_rooms:0,no_area:0}}
// A library whose rooms have no location: one unit, as before locations were told apart.
const single={mode:'location',recap,location:null,locations:[],unassigned:0,levels}
const places=[{code:'00',name:'Kampus Tembalang',rooms:5},{code:'10',name:'Kampus Tegal',rooms:1}]
const overview={mode:'overview',levels,unassigned:2,locations:[{...places[0],summary:{a:4,b:2,c:1,d:1,empty:3}},{...places[1],summary:{a:0,b:1,c:0,d:2,empty:8}}],
 recap:{generated_at:'2026-10-01 08:00:00',aspects:[aspect(6,'Jaringan internet','c',[],{value:'Rata-rata 2 lokasi',rows:[['Kampus Tegal','Kurang','1 Mbps/orang'],['Kampus Tembalang','Baik','4 Mbps/orang']],columns:['Lokasi','Kondisi','Capaian'],targets:[{code:'10',name:'Kampus Tegal'}]})]}}
const tegal={mode:'location',recap,location:places[1],locations:places,unassigned:0,levels}
const pages={sarpras:'http://localhost/sarpras.php',software:'http://localhost/software.php',facility:'http://localhost/facility.php'}
const context=(route:ContextValue['route']={view:'sarpras'}):ContextValue=>({config:{write:true,pages,today:'2026-10-01'} as ContextValue['config'],options:{} as ContextValue['options'],route,go:vi.fn(),back:vi.fn(),dirty:vi.fn(),refresh:vi.fn(),revision:0,mutate:vi.fn()})
function mount(w:ContextValue){return render(<WorkspaceContext.Provider value={w}><SarprasPage/></WorkspaceContext.Provider>)}
const serve=(data:object)=>{const fetch=vi.fn().mockResolvedValue({ok:true,headers:{get:()=>'application/json'},json:async()=>({ok:true,data})});vi.stubGlobal('fetch',fetch);return fetch}
const row=(name:string)=>screen.getByRole('button',{name:new RegExp(name)})
afterEach(()=>{cleanup();vi.unstubAllGlobals()})

test('the recap is an overview: it reads its own page, offers no input, and links each aspect to where its data is entered',async()=>{
 const fetch=serve(single)
 const w=context();mount(w)
 await screen.findByText('Luas gedung dan ruang')
 expect(fetch).toHaveBeenCalledTimes(1)
 expect(fetch.mock.calls[0][0]).toBe('http://localhost/sarpras.php?format=json')
 expect(fetch.mock.calls[0][1].method).toBe('GET')
 expect(screen.queryByRole('tab')).toBeNull()
 expect(screen.queryByRole('textbox')).toBeNull()
 expect(screen.queryByRole('spinbutton')).toBeNull()
 expect(screen.queryByRole('button',{name:/Simpan|Tambah|Unggah|Hapus/})).toBeNull()
 // One unit: nothing to choose between and nothing to compare.
 expect(screen.queryByRole('combobox',{name:'Lokasi perpustakaan'})).toBeNull()
 expect(screen.queryByText('Perbandingan lokasi')).toBeNull()
 // The headline counts what needs a look, and the meter what is already fine.
 expect(screen.getByRole('heading',{name:'1 aspek perlu perhatian'})).toBeTruthy()
 expect(screen.getByRole('meter',{name:'Aspek yang sudah baik'}).getAttribute('aria-valuetext')).toBe('1 dari 3 aspek')
 // Suggestions stay folded until an aspect is opened.
 expect(screen.queryByRole('button',{name:'Buka Gedung & Jaringan'})).toBeNull()
 fireEvent.click(row('Luas gedung dan ruang'))
 fireEvent.click(screen.getByRole('button',{name:'Buka Gedung & Jaringan'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'facility'})
 fireEvent.click(screen.getByRole('button',{name:'Buka Ruangan & Barang'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'inventory'})
 fireEvent.click(row('Lisensi perangkat lunak'))
 fireEvent.click(screen.getByRole('button',{name:'Buka Perangkat Lunak'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'software'})
 // An aspect already at its best level needs no fixing, so it offers no link.
 fireEvent.click(row('Pemeriksaan rutin dan tindak lanjut'))
 expect(screen.queryByRole('button',{name:'Buka Jadwal'})).toBeNull()
})

test('the filter narrows the list to one state and back',async()=>{
 serve(single)
 mount(context())
 await screen.findByText('Luas gedung dan ruang')
 fireEvent.click(screen.getByRole('radio',{name:/Belum ada data/}))
 expect(screen.queryByText('Luas gedung dan ruang')).toBeNull()
 expect(screen.getByText('Lisensi perangkat lunak')).toBeTruthy()
 // Choosing the active filter again shows everything.
 fireEvent.click(screen.getByRole('radio',{name:/Belum ada data/}))
 expect(screen.getByText('Luas gedung dan ruang')).toBeTruthy()
})

test('with several locations the recap opens on all of them: compared, then combined',async()=>{
 const fetch=serve(overview)
 const w=context();mount(w)
 await screen.findByText('Perbandingan lokasi')
 expect(fetch.mock.calls[0][0]).toBe('http://localhost/sarpras.php?format=json')
 expect(screen.getByRole('combobox',{name:'Lokasi perpustakaan'})).toBeTruthy()
 expect(screen.getByRole('meter',{name:'Aspek yang sudah baik di Kampus Tembalang'}).getAttribute('aria-valuetext')).toBe('6 dari 11 aspek')
 expect(screen.getByText('2 ruangan belum ditetapkan lokasinya')).toBeTruthy()
 // A location opens its own recap.
 fireEvent.click(screen.getByRole('button',{name:'Kampus Tegal'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'sarpras',library:'10'})
 // A combined aspect lists its locations and points at the weakest.
 fireEvent.click(row('Jaringan internet'))
 expect(screen.getByText('1 Mbps/orang')).toBeTruthy()
 fireEvent.click(screen.getByRole('button',{name:'Buka Kampus Tegal'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'sarpras',library:'10'})
})

test('a location\'s recap is asked for by its code and carries the location to where data is entered',async()=>{
 const fetch=serve(tegal)
 const w=context({view:'sarpras',library:'10'});mount(w)
 await screen.findByText('Luas gedung dan ruang')
 expect(fetch.mock.calls[0][0]).toBe('http://localhost/sarpras.php?library=10&format=json')
 expect(screen.queryByText('Perbandingan lokasi')).toBeNull()
 fireEvent.click(screen.getByRole('button',{name:'Semua lokasi'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'sarpras'})
 fireEvent.click(row('Luas gedung dan ruang'))
 fireEvent.click(screen.getByRole('button',{name:'Buka Gedung & Jaringan'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'facility',library:'10'})
})

test('with no rooms yet the recap says where to start',async()=>{
 serve({mode:'empty',locations:[],unassigned:0,levels})
 const w=context();mount(w)
 await screen.findByText('Belum ada ruangan')
 fireEvent.click(screen.getByRole('button',{name:'Buka Ruangan & Barang'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'inventory'})
})
