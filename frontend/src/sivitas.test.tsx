// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { SivitasPage } from './sivitas'
import { WorkspaceContext, type ContextValue } from './context'

const locations=[{code:'00',name:'Kampus Tembalang'},{code:'04',name:'Kampus Blora'}]
const institution=(label:string,active:number,more:object={})=>({key:label.toLowerCase(),label,variants:[{value:label,members:active,active}],members:active,active,location:'',suggestion:null,...more})
const data=(more:object={})=>({
 counts:{total:120,unmapped:40,single:false,locations:[{...locations[0],count:80},{...locations[1],count:0}]},
 settings:{default:'',excluded:[4]},
 types:[{id:1,name:'Mahasiswa',active:110,location:'',excluded:false},{id:4,name:'Luar',active:3,location:'',excluded:true}],
 institutions:[institution('Keperawatan Blora',30,{suggestion:'04'}),institution('Gizi',10,{location:'00'})],
 similar:[{variants:[{value:'Terapi Gigi',members:20},{value:'Terapi Gigi ',members:2},{value:'Terapi Gigl',members:1}],suggestion:'Terapi Gigi'}],
 fixes:[{id:7,from:['Gizii'],to:'Gizi',count:3,created_at:'2026-10-01 09:00:00',undone_at:null,by:'Rina'}],
 locations,fix:true,write:true,csrf:'token',...more})
const pages={sarpras:'',software:'',facility:'',sivitas:'http://localhost/sivitas.php'}
const context=():ContextValue=>({config:{write:true,pages,today:'2026-10-03'} as ContextValue['config'],options:{} as ContextValue['options'],route:{view:'sivitas'},go:vi.fn(),back:vi.fn(),dirty:vi.fn(),refresh:vi.fn(),revision:0,mutate:vi.fn()})
const reply=(body:object)=>({ok:true,headers:{get:()=>'application/json'},json:async()=>body})
const serve=(page:object)=>{
 const fetch=vi.fn((_:string,init:RequestInit)=>Promise.resolve(init?.method==='POST'?reply({ok:true,message:'Tersimpan.'}):reply({ok:true,data:page})))
 vi.stubGlobal('fetch',fetch)
 return fetch
}
const posted=(fetch:ReturnType<typeof serve>)=>fetch.mock.calls.filter(([,init])=>init?.method==='POST').map(([,init])=>init.body as FormData)
function mount(){return render(<WorkspaceContext.Provider value={context()}><SivitasPage/></WorkspaceContext.Provider>)}
afterEach(()=>{cleanup();vi.unstubAllGlobals()})

test('shows sivitas per location and maps an Institusi by its suggestion',async()=>{
 const fetch=serve(data())
 mount()
 expect(await screen.findByText('Tidak dihitung ke lokasi mana pun')).toBeTruthy()
 expect(screen.getByText('40')).toBeTruthy()
 expect(screen.getByText('80')).toBeTruthy()
 fireEvent.click(screen.getByRole('button',{name:/Saran: Kampus Blora/}))
 await waitFor(()=>expect(posted(fetch)).toHaveLength(1))
 const body=posted(fetch)[0]
 expect([body.get('action'),body.get('basis'),body.get('values[0]'),body.get('location'),body.get('csrf')]).toEqual(['map','institution','Keperawatan Blora','04','token'])
})

test('leaves out a member type and saves which types count',async()=>{
 const fetch=serve(data())
 mount()
 const mahasiswa=await screen.findByText('Mahasiswa',{selector:'span'})
 fireEvent.click(within(mahasiswa.closest('label')!).getByRole('checkbox'))
 fireEvent.click(screen.getByRole('button',{name:'Simpan'}))
 await waitFor(()=>expect(posted(fetch)).toHaveLength(1))
 const body=posted(fetch)[0]
 expect([body.get('action'),body.get('excluded[0]'),body.get('excluded[1]')]).toEqual(['counting','4','1'])
})

test('merges mistyped spellings into the chosen one only after confirming',async()=>{
 const fetch=serve(data())
 mount()
 fireEvent.mouseDown(await screen.findByRole('tab',{name:/Perbaiki institusi/}))
 fireEvent.click(screen.getByRole('tab',{name:/Perbaiki institusi/}))
 fireEvent.click(await screen.findByRole('button',{name:'Gabungkan 3 anggota'}))
 const dialog=await screen.findByRole('dialog')
 expect(within(dialog).getByText(/Institusi 3 anggota di SLiMS diubah menjadi/)).toBeTruthy()
 expect(posted(fetch)).toHaveLength(0)
 fireEvent.click(within(dialog).getByRole('button',{name:'Gabungkan'}))
 await waitFor(()=>expect(posted(fetch)).toHaveLength(1))
 const body=posted(fetch)[0]
 expect([body.get('action'),body.get('from[0]'),body.get('from[1]'),body.get('to')]).toEqual(['merge','Terapi Gigi ','Terapi Gigl','Terapi Gigi'])
})

test('without Membership write access, Institusi cannot be rewritten',async()=>{
 serve(data({fix:false}))
 mount()
 fireEvent.mouseDown(await screen.findByRole('tab',{name:/Perbaiki institusi/}))
 fireEvent.click(screen.getByRole('tab',{name:/Perbaiki institusi/}))
 expect(await screen.findByText(/memerlukan hak tulis Keanggotaan/)).toBeTruthy()
 expect((screen.getByRole('button',{name:'Gabungkan 3 anggota'}) as HTMLButtonElement).disabled).toBe(true)
 expect(screen.queryByRole('button',{name:'Urungkan'})).toBeNull()
})

test('a library that is one unit needs no mapping',async()=>{
 serve(data({counts:{total:500,unmapped:0,single:true,locations:[]},institutions:[]}))
 mount()
 expect(await screen.findByText(/semua sivitas dihitung untuknya/)).toBeTruthy()
 expect(screen.queryByRole('tab',{name:'Institusi'})).toBeNull()
 expect(screen.queryByText('Tidak dihitung ke lokasi mana pun')).toBeNull()
})
