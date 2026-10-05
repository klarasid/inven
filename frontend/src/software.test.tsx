// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { SoftwarePage } from './software'
import { WorkspaceContext, type ContextValue } from './context'

const app=(more:object={})=>({id:3,name:'Windows 11 Pro',version:'23H2',purpose:'Sistem operasi',licence:'komersial',licence_ref:'OEM',valid_until:null,installs:12,notes:null,files:[],...more})
const data=(software:object[],more:object={})=>({software,licences:{komersial:'Komersial (berbayar)',open_source:'Open source'},files:{available:true,max:5,max_bytes:5242880},write:true,csrf:'token',...more})
const pages={sarpras:'http://localhost/sarpras.php',software:'http://localhost/software.php',facility:'http://localhost/facility.php'}
const context=():ContextValue=>({config:{write:true,pages,today:'2026-10-06'} as ContextValue['config'],options:{} as ContextValue['options'],route:{view:'software'},go:vi.fn(),back:vi.fn(),dirty:vi.fn(),refresh:vi.fn(),revision:0,mutate:vi.fn()})
function mount(){return render(<WorkspaceContext.Provider value={context()}><SoftwarePage/></WorkspaceContext.Provider>)}
const reply=(body:object)=>({ok:true,headers:{get:()=>'application/json'},json:async()=>body})
const posted=(fetch:ReturnType<typeof vi.fn>)=>fetch.mock.calls.find(([,init])=>init?.method==='POST')![1].body as FormData
afterEach(()=>{cleanup();vi.unstubAllGlobals()})

test('licence files of an application are counted in its row, opened and uploaded from its dialog, and removed after confirming',async()=>{
 const files=[{id:7,title:'Stiker COA',mime:'image/png',created_at:'2026-10-01 08:00:00'},{id:8,title:'Faktur',mime:'application/pdf',created_at:'2026-10-01 08:00:00'}]
 const fetch=vi.fn((_:string,init?:RequestInit)=>Promise.resolve(init?.method==='POST'?reply({ok:true,message:'Bukti lisensi tersimpan.'}):reply({ok:true,data:data([app({files}),app({id:4,name:'SLiMS',licence:'open_source'})])})))
 vi.stubGlobal('fetch',fetch)
 mount()
 const open=await screen.findByRole('button',{name:'Bukti lisensi Windows 11 Pro'})
 expect(open.textContent).toBe('2')
 expect(screen.getByRole('button',{name:'Bukti lisensi SLiMS'}).textContent).toBe('0')
 fireEvent.click(open)
 const dialog=await screen.findByRole('dialog',{name:'Bukti lisensi Windows 11 Pro'})
 expect(screen.getByText('Faktur')).toBeTruthy()
 // A picture opens in a popup on the page.
 fireEvent.click(screen.getByRole('button',{name:'Lihat Stiker COA'}))
 const popup=await screen.findByRole('dialog',{name:'Stiker COA'})
 expect(popup.querySelector('img')?.getAttribute('src')).toBe('http://localhost/software.php?licence_file=7')
 fireEvent.keyDown(popup,{key:'Escape'})
 // Uploading: only a PDF or a picture is offered to the server, for this application.
 const upload=screen.getByRole('button',{name:'Unggah'}) as HTMLButtonElement
 expect(upload.disabled).toBe(true)
 fireEvent.change(dialog.querySelector('input[type=file]')!,{target:{files:[new File(['x'],'kunci.txt',{type:'text/plain'})]}})
 expect(screen.getByText('Gunakan PDF, JPEG, PNG, atau WebP maksimal 5 MB.')).toBeTruthy()
 expect(upload.disabled).toBe(true)
 fireEvent.change(dialog.querySelector('input[type=file]')!,{target:{files:[new File(['x'],'sertifikat.pdf',{type:'application/pdf'})]}})
 fireEvent.change(screen.getByLabelText('Judul'),{target:{value:'Sertifikat lisensi'}})
 fireEvent.click(upload)
 await waitFor(()=>expect(fetch.mock.calls.some(([,init])=>init?.method==='POST')).toBe(true))
 const body=posted(fetch)
 expect(Object.fromEntries(['action','software_id','title','csrf'].map(k=>[k,body.get(k)]))).toEqual({action:'licence_upload',software_id:'3',title:'Sertifikat lisensi',csrf:'token'})
 expect((body.get('file') as File).name).toBe('sertifikat.pdf')
 // Removing asks first.
 fetch.mockClear()
 fireEvent.click(await screen.findByRole('button',{name:'Hapus Faktur'}))
 fireEvent.click(await screen.findByRole('button',{name:'Hapus bukti'}))
 await waitFor(()=>expect(fetch.mock.calls.some(([,init])=>init?.method==='POST')).toBe(true))
 const removed=posted(fetch)
 expect([removed.get('action'),removed.get('software_id'),removed.get('record_id')]).toEqual(['licence_delete','3','8'])
})

test('readers open licence files but cannot add or remove them',async()=>{
 const files=[{id:8,title:'Faktur',mime:'application/pdf',created_at:'2026-10-01 08:00:00'}]
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:data([app({files})],{write:false,csrf:''})})))
 mount()
 fireEvent.click(await screen.findByRole('button',{name:'Bukti lisensi Windows 11 Pro'}))
 const view=await screen.findByRole('button',{name:'Lihat Faktur'}) as HTMLButtonElement
 expect(view.disabled).toBe(false)
 expect(screen.queryByRole('button',{name:'Unggah'})).toBeNull()
 expect(screen.queryByRole('button',{name:'Hapus Faktur'})).toBeNull()
})

test('an application at its limit takes no more files; before the migration the dialog says what to run',async()=>{
 const files=[1,2,3,4,5].map(id=>({id,title:`Bukti ${id}`,mime:'image/png',created_at:'2026-10-01 08:00:00'}))
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:data([app({files}),app({id:4,name:'SLiMS',files:null})])})))
 mount()
 fireEvent.click(await screen.findByRole('button',{name:'Bukti lisensi Windows 11 Pro'}))
 expect(await screen.findByText(/paling banyak 5 berkas/)).toBeTruthy()
 expect(screen.queryByRole('button',{name:'Unggah'})).toBeNull()
 // The dialog's own close control and the footer button both read "Tutup".
 fireEvent.click(screen.getAllByRole('button',{name:'Tutup'})[0])
 fireEvent.click(await screen.findByRole('button',{name:'Bukti lisensi SLiMS'}))
 expect(await screen.findByText(/Jalankan migrasi plugin hingga versi 16/)).toBeTruthy()
 expect(screen.queryByRole('button',{name:'Unggah'})).toBeNull()
 // The form offers no file field either, for a new application too.
 cleanup()
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:data([],{files:{available:false,max:5,max_bytes:5242880}})})))
 mount()
 fireEvent.click((await screen.findAllByRole('button',{name:'Tambah aplikasi'}))[0])
 await screen.findByRole('dialog',{name:'Tambah aplikasi'})
 expect(screen.getByText(/Jalankan migrasi plugin hingga versi 16/)).toBeTruthy()
 expect(document.querySelector('input[type=file]')).toBeNull()
})

test('the application form takes licence files and uploads them once the application is saved',async()=>{
 const posts=()=>fetch.mock.calls.filter(([,init])=>init?.method==='POST').map(([,init])=>init!.body as FormData)
 const fetch=vi.fn((_:string,init?:RequestInit)=>Promise.resolve(init?.method==='POST'?reply({ok:true,message:'Aplikasi tersimpan.',record:9}):reply({ok:true,data:data([app()])})))
 vi.stubGlobal('fetch',fetch)
 mount()
 // A new application: its files go to the id the server gave it.
 fireEvent.click(await screen.findByRole('button',{name:'Tambah aplikasi'}))
 const form=await screen.findByRole('dialog',{name:'Tambah aplikasi'})
 fireEvent.change(screen.getByLabelText(/Nama aplikasi/),{target:{value:'Zotero'}})
 const picker=screen.getByLabelText('Berkas bukti lisensi') as HTMLInputElement
 expect(picker.multiple).toBe(true)
 fireEvent.change(picker,{target:{files:[new File(['a'],'lisensi.png',{type:'image/png'}),new File(['b'],'faktur.pdf',{type:'application/pdf'})]}})
 fireEvent.click(screen.getByRole('button',{name:'Simpan'}))
 await waitFor(()=>expect(posts().length).toBe(3))
 expect(posts().map(body=>[body.get('action'),body.get('software_id'),(body.get('file') as File|null)?.name??null])).toEqual([['software',null,null],['licence_upload','9','lisensi.png'],['licence_upload','9','faktur.pdf']])
 expect(posts()[0].get('name')).toBe('Zotero')
 await waitFor(()=>expect(form.isConnected).toBe(false))
})

test('the form shows an application\'s licence files, and refuses a choice the server would refuse',async()=>{
 const files=[1,2,3,4].map(id=>({id,title:`Bukti ${id}`,mime:'image/png',created_at:'2026-10-01 08:00:00'}))
 const fetch=vi.fn((_:string,init?:RequestInit)=>Promise.resolve(init?.method==='POST'?reply({ok:true,message:'Aplikasi tersimpan.',record:3}):reply({ok:true,data:data([app({files})])})))
 vi.stubGlobal('fetch',fetch)
 mount()
 fireEvent.click(await screen.findByRole('button',{name:'Ubah Windows 11 Pro'}))
 await screen.findByRole('dialog',{name:'Ubah aplikasi'})
 expect(screen.getByRole('button',{name:'Lihat Bukti 4'})).toBeTruthy()
 expect(screen.getByRole('button',{name:'Hapus Bukti 1'})).toBeTruthy()
 const picker=screen.getByLabelText('Berkas bukti lisensi')
 // Four are kept already: one more fits, two do not.
 fireEvent.change(picker,{target:{files:[new File(['a'],'a.png',{type:'image/png'}),new File(['b'],'b.png',{type:'image/png'})]}})
 expect(screen.getByText('Satu aplikasi memuat paling banyak 5 berkas. Pilih paling banyak 1 berkas lagi.')).toBeTruthy()
 fireEvent.change(picker,{target:{files:[new File(['a'],'catatan.txt',{type:'text/plain'})]}})
 expect(screen.getByText('Gunakan PDF, JPEG, PNG, atau WebP maksimal 5 MB.')).toBeTruthy()
 fireEvent.click(screen.getByRole('button',{name:'Simpan'}))
 expect(fetch.mock.calls.some(([,init])=>init?.method==='POST')).toBe(false)
})
