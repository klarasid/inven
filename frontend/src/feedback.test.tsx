// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { FeedbackButton } from './feedback'
import { WorkspaceContext, type ContextValue } from './context'

const kinds={bug:'Masalah',idea:'Saran',question:'Pertanyaan',praise:'Apresiasi'}
const answered={id:4,kind:{key:'bug',label:'Masalah'},message:'Tombol cetak KIR tidak merespons.',page:'inventory',author:'Rina Wulandari',contact:false,status:{key:'planned',label:'Direncanakan'},issue_url:'https://github.com/klarasid/inven/issues/57',created_at:'2026-10-06 09:00:00',replies:[{message:'Kami tindak lanjuti di issue #57.',kind:'issue_linked',replied_at:'2026-10-07 09:00:00',unread:true}]}
const data=(more:object={})=>({available:true,feedback:[answered],unread:1,kinds,contact:{name:'Rina Wulandari',email:'rina@example.sch.id'},...more})
const context=(mutate=vi.fn()):ContextValue=>({config:{write:false,api:'http://localhost/api',watch:'http://localhost/watch'} as ContextValue['config'],options:{} as ContextValue['options'],route:{view:'inventory'},go:vi.fn(),back:vi.fn(),dirty:vi.fn(),refresh:vi.fn(),revision:0,mutate})
const reply=(body:object)=>({ok:true,headers:{get:()=>'application/json'},json:async()=>body})
function mount(w:ContextValue){return render(<WorkspaceContext.Provider value={w}><FeedbackButton/></WorkspaceContext.Provider>)}
afterEach(()=>{cleanup();vi.unstubAllGlobals()})

test('a reader sends feedback of a kind, with the page it was written on, and only allows contact when ticked',async()=>{
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:data({feedback:[],unread:0})})))
 const mutate=vi.fn().mockResolvedValue({ok:true,message:'Masukan terkirim. Terima kasih.',data:{...answered,id:5,status:{key:'new',label:'Diterima'},replies:[],issue_url:null}})
 mount(context(mutate))
 fireEvent.click(await screen.findByRole('button',{name:'Masukan'}))
 const panel=await screen.findByRole('dialog',{name:'Masukan untuk Klaras'})
 // What would be sent as the contact is shown before it is allowed.
 expect(within(panel).getByText('Nama dan email Anda ikut terkirim: Rina Wulandari · rina@example.sch.id.')).toBeTruthy()
 const send=within(panel).getByRole('button',{name:'Kirim masukan'}) as HTMLButtonElement
 const message=within(panel).getByLabelText('Masukan Anda')
 fireEvent.change(message,{target:{value:'Pendek'}})
 expect(within(panel).getByText('Paling sedikit 10 karakter.')).toBeTruthy()
 expect(send.disabled).toBe(true)
 fireEvent.click(within(panel).getByRole('radio',{name:'Saran'}))
 fireEvent.change(message,{target:{value:'Mohon tambahkan ekspor Excel untuk KIR.'}})
 fireEvent.click(send)
 await waitFor(()=>expect(mutate).toHaveBeenCalledTimes(1))
 expect(mutate.mock.calls[0][0]).toEqual({watch_action:'feedback_submit',kind:'idea',message:'Mohon tambahkan ekspor Excel untuk KIR.',page:'inventory',contact:''})
 // What was sent shows in the history at once.
 expect(await within(panel).findByText('Diterima')).toBeTruthy()
 fireEvent.mouseDown(within(panel).getByRole('tab',{name:/Kirim masukan/}))
 fireEvent.click(within(panel).getByRole('checkbox',{name:'Boleh dihubungi'}))
 fireEvent.change(within(panel).getByLabelText('Masukan Anda'),{target:{value:'Satu lagi, tolong hubungi saya.'}})
 fireEvent.click(within(panel).getByRole('button',{name:'Kirim masukan'}))
 await waitFor(()=>expect(mutate).toHaveBeenCalledTimes(2))
 expect(mutate.mock.calls[1][0].contact).toBe('1')
})

test('a reply from Klaras shows as a dot on the button, and opening the history asks for news and marks it read',async()=>{
 const fetch=vi.fn().mockResolvedValue(reply({ok:true,data:data()}))
 vi.stubGlobal('fetch',fetch)
 const mutate=vi.fn().mockResolvedValue({ok:true})
 mount(context(mutate))
 fireEvent.click(await screen.findByRole('button',{name:'Masukan, 1 balasan baru'}))
 const panel=await screen.findByRole('dialog',{name:'Masukan untuk Klaras'})
 fireEvent.mouseDown(within(panel).getByRole('tab',{name:/Riwayat/}))
 expect(await within(panel).findByText('Kami tindak lanjuti di issue #57.')).toBeTruthy()
 expect(within(panel).getByRole('link',{name:/Lihat tindak lanjutnya di GitHub/}).getAttribute('href')).toBe('https://github.com/klarasid/inven/issues/57')
 expect(String(fetch.mock.calls.at(-1)![0])).toContain('refresh=1')
 await waitFor(()=>expect(mutate).toHaveBeenCalledWith({watch_action:'feedback_seen'}))
 // Back on the page, the dot is gone.
 fireEvent.keyDown(panel,{key:'Escape'})
 expect(await screen.findByRole('button',{name:'Masukan'})).toBeTruthy()
})

test('before the migration the panel says what to run instead of offering a form',async()=>{
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(reply({ok:true,data:data({available:false,feedback:[],unread:0})})))
 mount(context())
 fireEvent.click(await screen.findByRole('button',{name:'Masukan'}))
 expect(await screen.findByText(/Jalankan migrasi plugin hingga versi 20/)).toBeTruthy()
 expect(screen.queryByRole('button',{name:'Kirim masukan'})).toBeNull()
})
