// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { Photos } from './shared'

afterEach(cleanup)
const photos=[{id:1,url:'http://localhost/photo?id=1'},{id:2,url:'http://localhost/photo?id=2'},{id:3,url:null}]

test('a photo opens in a popup on the page instead of another tab',async()=>{
 render(<Photos photos={photos}/>)
 // Nothing leads away from the page until a photo is open.
 expect(screen.queryByRole('link')).toBeNull()
 expect(screen.queryByRole('dialog')).toBeNull()
 expect(screen.getByText('Foto lama tidak dapat dibaca.')).toBeTruthy()
 fireEvent.click(screen.getByRole('button',{name:'Lihat foto 2'}))
 const popup=await screen.findByRole('dialog',{name:'Foto'})
 expect(within(popup).getByRole('img',{name:'Foto'}).getAttribute('src')).toBe('http://localhost/photo?id=2')
 expect(within(popup).getByRole('link',{name:'Buka ukuran penuh'}).getAttribute('href')).toBe('http://localhost/photo?id=2')
})

test('marking a photo for removal does not open it',()=>{
 const toggle=vi.fn()
 render(<Photos photos={photos} onToggle={toggle}/>)
 fireEvent.click(screen.getAllByRole('button',{name:'Hapus foto'})[0])
 expect(toggle).toHaveBeenCalledWith(photos[0])
 expect(screen.queryByRole('dialog')).toBeNull()
})
