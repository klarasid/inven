// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { HistoryImportPage } from './history-import'
import { WorkspaceContext, type ContextValue } from './context'

const summary={token:'preview-token',inspections:1,results:2,findings:1,actions:1,closed:0,review:1,open:0,rows:[{number:'HIST-001',date:'2025-08-15',room:'Ruang Baca',library:'Lokasi B',examiner:'Budi',results:2,actions:1,closed:0}]}
const context=(mutate=vi.fn()):ContextValue=>({config:{write:true,watch:'http://localhost/inspection.php',today:'2026-09-17'} as ContextValue['config'],options:{} as ContextValue['options'],route:{view:'history-import'},go:vi.fn(),back:vi.fn(),dirty:vi.fn(),refresh:vi.fn(),revision:0,mutate})
function mount(w:ContextValue){return render(<WorkspaceContext.Provider value={w}><HistoryImportPage/></WorkspaceContext.Provider>)}
afterEach(cleanup)
test('upload validates first, presents location and only commits after explicit save',async()=>{
 const mutate=vi.fn().mockResolvedValueOnce({ok:true,data:summary}).mockResolvedValueOnce({ok:true,data:{...summary,ids:[9]}})
 const w=context(mutate);mount(w)
 expect((screen.getByRole('link',{name:'Unduh template Excel'}) as HTMLAnchorElement).href).toContain('tab=history_template')
 const file=new File(['workbook'],'riwayat.xlsx')
 fireEvent.change(screen.getByLabelText('Berkas Excel (.xlsx)'),{target:{files:[file]}})
 fireEvent.click(screen.getByRole('button',{name:'Validasi berkas'}))
 await screen.findByText('Lokasi B')
 expect(mutate).toHaveBeenCalledTimes(1)
 expect(mutate.mock.calls[0][0]).toEqual({watch_action:'history_preview'})
 expect((mutate.mock.calls[0][1] as FormData).get('workbook')).toBe(file)
 expect(screen.getByText(/1 menunggu verifikasi/)).toBeTruthy()
 fireEvent.click(screen.getByRole('button',{name:'Simpan impor'}))
 await screen.findByText('Riwayat berhasil diimpor')
 expect(mutate.mock.calls[1][0]).toEqual({watch_action:'history_commit',token:'preview-token'})
 expect(w.dirty).toHaveBeenLastCalledWith(false)
 fireEvent.click(screen.getByRole('button',{name:'Lihat riwayat pemeriksaan'}))
 expect(w.go).toHaveBeenCalledWith({view:'tasks',kind:'history',owner:'all'})
})
test('changing the file invalidates preview and server validation errors are visible',async()=>{
 const mutate=vi.fn().mockResolvedValueOnce({ok:true,data:summary}).mockRejectedValueOnce(new Error('Pemeriksaan baris 2: barang di ruang lain.'))
 mount(context(mutate))
 const input=screen.getByLabelText('Berkas Excel (.xlsx)')
 fireEvent.change(input,{target:{files:[new File(['a'],'a.xlsx')]}})
 fireEvent.click(screen.getByRole('button',{name:'Validasi berkas'}));await screen.findByText('Lokasi B')
 fireEvent.change(input,{target:{files:[new File(['b'],'b.xlsx')]}})
 expect(screen.queryByRole('button',{name:'Simpan impor'})).toBeNull()
 fireEvent.click(screen.getByRole('button',{name:'Validasi berkas'}))
 await screen.findByText('Pemeriksaan baris 2: barang di ruang lain.')
 expect(screen.queryByRole('button',{name:'Simpan impor'})).toBeNull()
})
test('read-only access and invalid extensions cannot import',async()=>{
 const w=context();w.config.write=false;const view=mount(w)
 expect(screen.queryByLabelText('Berkas Excel (.xlsx)')).toBeNull()
 view.unmount();w.config.write=true;mount(w)
 fireEvent.change(screen.getByLabelText('Berkas Excel (.xlsx)'),{target:{files:[new File(['a'],'bad.csv')]}})
 fireEvent.click(screen.getByRole('button',{name:'Validasi berkas'}))
 await waitFor(()=>expect(screen.getByText('Gunakan Excel .xlsx maksimal 2 MB.')).toBeTruthy())
 expect(w.mutate).not.toHaveBeenCalled()
})
