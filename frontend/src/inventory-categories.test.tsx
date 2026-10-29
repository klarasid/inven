// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { CategoryFields, InventoryList } from './inventory'
import { WorkspaceContext, type ContextValue } from './context'

const sarpras={areaTypes:{},areaGroups:{},categories:{komputer:'Komputer',multimedia:'Perangkat multimedia',keamanan:'Sarana keamanan dan keselamatan'},types:{komputer:['PC','Laptop'],multimedia:['Proyektor','Laptop'],keamanan:['APAR']}}
const context={options:{sarpras}} as unknown as ContextValue
function mount(category:string,onCategory=vi.fn()){
 const view=render(<WorkspaceContext.Provider value={context}><CategoryFields category={category} type="" onCategory={onCategory} onType={vi.fn()}/></WorkspaceContext.Provider>)
 return {onCategory,view}
}
const box=(name:string)=>screen.getByRole('checkbox',{name})
const checked=(name:string)=>box(name).getAttribute('aria-checked')==='true'
afterEach(()=>{cleanup();vi.unstubAllGlobals()})

test('an item shows every category it has, and a second one is added beside the first',()=>{
 const {onCategory}=mount('komputer')
 expect(checked('Komputer')).toBe(true)
 expect(checked('Perangkat multimedia')).toBe(false)
 fireEvent.click(box('Perangkat multimedia'))
 expect(onCategory).toHaveBeenCalledWith('komputer,multimedia')
})

test('unticking a category keeps the others, and unticking the last leaves none',()=>{
 const both=mount('komputer,multimedia')
 expect(checked('Komputer')&&checked('Perangkat multimedia')).toBe(true)
 fireEvent.click(box('Komputer'))
 expect(both.onCategory).toHaveBeenCalledWith('multimedia')
 cleanup()
 const one=mount('multimedia')
 fireEvent.click(box('Perangkat multimedia'))
 expect(one.onCategory).toHaveBeenCalledWith('')
})

test('types are suggested for every chosen category, each once',()=>{
 const {view}=mount('komputer,multimedia')
 const suggestions=Array.from(view.container.querySelectorAll('datalist option'),(option)=>option.getAttribute('value'))
 expect(suggestions).toEqual(['PC','Laptop','Proyektor'])
})

const items={rows:[
 {id:1,item_name:'PC OPAC',item_code:'INV-1',item_condition:'B',category:'komputer,multimedia',item_type:'Kiosk OPAC'},
 {id:2,item_name:'Meja baca',item_code:'INV-2',item_condition:'B',category:null,item_type:''},
],total:2,page:1,pages:1,room:{id:5,room_name:'Ruang baca'},conditions:{B:'2'},uncategorized:1}
const room=(route:object,data:object=items)=>{
 const fetch=vi.fn().mockResolvedValue({ok:true,headers:{get:()=>'application/json'},json:async()=>({ok:true,data})})
 vi.stubGlobal('fetch',fetch)
 const w={config:{write:true,api:'http://localhost/api'},options:{sarpras,rooms:[{id:5,room_name:'Ruang baca',slims_location_id:'L1'}],libraries:[{location_id:'L1',location_name:'Perpustakaan Pusat'}]},route:{view:'inventory',room:5,...route},go:vi.fn(),back:vi.fn(),dirty:vi.fn(),refresh:vi.fn(),revision:0,mutate:vi.fn()} as unknown as ContextValue
 render(<WorkspaceContext.Provider value={w}><InventoryList/></WorkspaceContext.Provider>)
 return {fetch,w}
}

test("the items of a room show their categories, and those without one say so",async()=>{
 room({})
 const row=(await screen.findByText('PC OPAC')).closest('tr') as HTMLElement
 expect(row.textContent).toContain('Komputer')
 expect(row.textContent).toContain('Perangkat multimedia')
 expect(row.textContent).toContain('Kiosk OPAC')
 const uncategorized=screen.getByText('Meja baca').closest('tr') as HTMLElement
 // Once beside the name for a narrow screen, once in its column.
 expect(within(uncategorized).getAllByText('Belum berkategori')).toHaveLength(2)
 expect(screen.getByRole('columnheader',{name:'Kategori'})).toBeTruthy()
})

test('the items without a category are listed on their own, and the list says when none is left',async()=>{
 const {w}=room({})
 fireEvent.click(await screen.findByRole('button',{name:'Belum berkategori (1)'}))
 expect(w.go).toHaveBeenCalledWith({view:'inventory',room:5,category:'none',page:1,item:undefined},true)
 cleanup()
 const filtered=room({category:'none'},{...items,rows:[],total:0,uncategorized:0})
 expect(await screen.findByText('Semua barang sudah berkategori')).toBeTruthy()
 expect(String(filtered.fetch.mock.calls[0][0])).toContain('category=none')
 // The filter stays at hand to be turned off.
 expect(screen.getByRole('button',{name:'Belum berkategori (0)'}).getAttribute('aria-pressed')).toBe('true')
})

test("a room's trail names its library location, which opens the room list filtered to it",async()=>{
 const {w}=room({})
 await screen.findByText('PC OPAC')
 const trail=screen.getByRole('navigation',{name:'breadcrumb'})
 expect(trail.textContent).toContain('Ruangan & Barang')
 fireEvent.click(within(trail).getByRole('button',{name:'Perpustakaan Pusat'}))
 expect(w.go).toHaveBeenCalledWith({view:'inventory',library:'L1'})
})

test('the room list can be shown as a table, and the choice is remembered',async()=>{
 localStorage.removeItem('inventory.roomLayout')
 const rooms={rows:[{id:5,room_name:'Ruang baca',location_code:'00-RUANG-001',library_name:'Perpustakaan Pusat',area_m2:'120',item_count:'12',damaged_count:'3'}],total:1,page:1,pages:1}
 const {w}=room({room:undefined},rooms)
 expect(await screen.findByText('Ruang baca')).toBeTruthy()
 expect(screen.queryByRole('table')).toBeNull()
 fireEvent.click(screen.getByRole('radio',{name:'Tampilan tabel'}))
 const row=(await screen.findByText('Ruang baca')).closest('tr') as HTMLElement
 expect(row.textContent).toContain('00-RUANG-001')
 expect(row.textContent).toContain('120 m²')
 expect(row.textContent).toContain('12')
 expect(localStorage.getItem('inventory.roomLayout')).toBe('table')
 fireEvent.click(row)
 expect(w.go).toHaveBeenCalledWith({view:'inventory',room:'5'})
 cleanup()
 room({room:undefined},rooms)
 expect((await screen.findByText('Ruang baca')).closest('tr')).toBeTruthy()
 localStorage.removeItem('inventory.roomLayout')
})

test('the print menu offers the areas with the floor area of their rooms, without photos',async()=>{
 const rooms={rows:[{id:5,room_name:'Ruang baca',location_code:'',library_name:'Perpustakaan Pusat',area_m2:'120',item_count:'12',damaged_count:'0'}],total:1,page:1,pages:1}
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue({ok:true,headers:{get:()=>'application/json'},json:async()=>({ok:true,data:rooms})}))
 const w={config:{write:true,api:'http://localhost/api',inventory:'http://localhost/inventory'},options:{sarpras,rooms:[],libraries:[{location_id:'L1',location_name:'Perpustakaan Pusat'}]},route:{view:'inventory',library:'L1'},go:vi.fn(),back:vi.fn(),dirty:vi.fn(),refresh:vi.fn(),revision:0,mutate:vi.fn()} as unknown as ContextValue
 render(<WorkspaceContext.Provider value={w}><InventoryList/></WorkspaceContext.Provider>)
 await screen.findByText('Ruang baca')
 fireEvent.keyDown(screen.getByRole('button',{name:/Cetak daftar/}),{key:'Enter'})
 fireEvent.click(screen.getByRole('menuitem',{name:/Daftar area dan luas ruangan/}))
 const dialog=screen.getByRole('dialog')
 expect(within(dialog).getByText('Cetak daftar area dan luas ruangan')).toBeTruthy()
 expect(within(dialog).getByText(/lokasi perpustakaan yang sedang disaring/).textContent).toContain('Tanpa foto')
 expect(within(dialog).getByText('Cetak PDF')).toBeTruthy()
},15000)
