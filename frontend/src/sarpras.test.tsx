// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { SarprasPage } from './sarpras'
import { WorkspaceContext, type ContextValue } from './context'

const aspect=(no:number,title:string,level:string|null,sources:string[])=>({no,section:'Perangkat TI dan multimedia',title,value:'—',level,basis:'',checks:[],rows:[],columns:[],fix:`Saran ${no}`,sources})
const data={recap:{generated_at:'2026-10-01 08:00:00',aspects:[aspect(1,'Luas gedung','d',['inventory','facility']),aspect(8,'Legalitas perangkat lunak',null,['software']),aspect(11,'Pengawasan berkala','a',['schedules'])],summary:{a:1,b:0,c:0,d:1,empty:1},counts:{rooms:2,items:5,uncategorized:0,unclassified_rooms:0,no_area:0}},levels:{a:'Sangat baik',b:'Baik',c:'Cukup',d:'Kurang'}}
const pages={sarpras:'http://localhost/sarpras.php',software:'http://localhost/software.php',facility:'http://localhost/facility.php'}
const context=():ContextValue=>({config:{write:true,pages,today:'2026-10-01'} as ContextValue['config'],options:{} as ContextValue['options'],route:{view:'sarpras'},go:vi.fn(),back:vi.fn(),dirty:vi.fn(),refresh:vi.fn(),revision:0,mutate:vi.fn()})
function mount(w:ContextValue){return render(<WorkspaceContext.Provider value={w}><SarprasPage/></WorkspaceContext.Provider>)}
afterEach(()=>{cleanup();vi.unstubAllGlobals()})

test('the recap is an overview: it reads its own page, offers no input, and links each aspect to where its data is entered',async()=>{
 const fetch=vi.fn().mockResolvedValue({ok:true,headers:{get:()=>'application/json'},json:async()=>({ok:true,data})})
 vi.stubGlobal('fetch',fetch)
 const w=context();mount(w)
 await screen.findByText('Luas gedung')
 expect(fetch).toHaveBeenCalledTimes(1)
 expect(fetch.mock.calls[0][0]).toBe('http://localhost/sarpras.php?format=json')
 expect(fetch.mock.calls[0][1].method).toBe('GET')
 expect(screen.queryByRole('tab')).toBeNull()
 expect(screen.queryByRole('textbox')).toBeNull()
 expect(screen.queryByRole('spinbutton')).toBeNull()
 expect(screen.queryByText('Data pendukung')).toBeNull()
 expect(screen.queryByRole('button',{name:/Simpan|Tambah|Unggah|Hapus/})).toBeNull()
 fireEvent.click(screen.getByRole('button',{name:'Buka Gedung & Jaringan'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'facility'})
 fireEvent.click(screen.getByRole('button',{name:'Buka Perangkat Lunak'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'software'})
 fireEvent.click(screen.getByRole('button',{name:'Buka Ruangan & Barang'}))
 expect(w.go).toHaveBeenLastCalledWith({view:'inventory'})
 // An aspect already at its best level needs no fixing, so it offers no link.
 expect(screen.queryByRole('button',{name:'Buka Jadwal'})).toBeNull()
})
