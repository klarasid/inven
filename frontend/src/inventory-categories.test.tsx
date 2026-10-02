// @vitest-environment jsdom
import React from 'react'
import { afterEach, expect, test, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { CategoryFields } from './inventory'
import { WorkspaceContext, type ContextValue } from './context'

const sarpras={areaTypes:{},areaGroups:{},categories:{komputer:'Komputer',multimedia:'Perangkat multimedia',keamanan:'Sarana keamanan dan keselamatan'},types:{komputer:['PC','Laptop'],multimedia:['Proyektor','Laptop'],keamanan:['APAR']}}
const context={options:{sarpras}} as unknown as ContextValue
function mount(category:string,onCategory=vi.fn()){
 const view=render(<WorkspaceContext.Provider value={context}><CategoryFields category={category} type="" onCategory={onCategory} onType={vi.fn()}/></WorkspaceContext.Provider>)
 return {onCategory,view}
}
const box=(name:string)=>screen.getByRole('checkbox',{name})
const checked=(name:string)=>box(name).getAttribute('aria-checked')==='true'
afterEach(cleanup)

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
