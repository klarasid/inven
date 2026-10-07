// @vitest-environment jsdom
import { afterEach, expect, test, vi } from 'vitest'
import { SCREENSHOT_TARGET_BYTES, shrinkScreenshot } from './screenshot'

const big=(name='layar.png',type='image/png')=>new File([new Uint8Array(3*1024*1024)],name,{type})

/** A browser whose encoder answers each (type, quality) with a blob of the size given, and records the canvas sizes it drew. */
function browser(sizes:(type:string,quality:number,width:number)=>number,writesWebp=true){
 const drawn:number[][]=[]
 vi.stubGlobal('createImageBitmap',vi.fn().mockResolvedValue({width:3840,height:2160,close:vi.fn()}))
 vi.spyOn(HTMLCanvasElement.prototype,'getContext').mockReturnValue({fillRect:vi.fn(),drawImage:vi.fn(),fillStyle:''} as unknown as CanvasRenderingContext2D)
 vi.spyOn(HTMLCanvasElement.prototype,'toBlob').mockImplementation(function(this:HTMLCanvasElement,done:BlobCallback,type?:string,quality?:number){
  drawn.push([this.width,this.height])
  const made=type==='image/webp'&&!writesWebp?'image/png':String(type)
  done(new Blob([new Uint8Array(sizes(made,Number(quality),this.width))],{type:made}))
 })
 return drawn
}
afterEach(()=>{vi.restoreAllMocks();vi.unstubAllGlobals()})

test('a screenshot already small enough is sent as it is',async()=>{
 const small=new File([new Uint8Array(1000)],'kecil.png',{type:'image/png'})
 expect(await shrinkScreenshot(small)).toBe(small)
})

test('a large one is redrawn no longer than 1920 px as a WebP, at falling quality until it fits',async()=>{
 const drawn=browser((_,quality)=>quality>0.7?SCREENSHOT_TARGET_BYTES+1:200_000)
 const shrunk=await shrinkScreenshot(big())
 expect(shrunk.type).toBe('image/webp')
 expect(shrunk.name).toBe('layar.webp')
 expect(shrunk.size).toBe(200_000)
 expect(drawn[0]).toEqual([1920,1080])
})

test('it draws smaller when quality alone is not enough, and writes JPEG where the browser cannot write WebP',async()=>{
 const drawn=browser((_,__,width)=>width>1500?SCREENSHOT_TARGET_BYTES+1:300_000,false)
 const shrunk=await shrinkScreenshot(big('layar.webp','image/webp'))
 expect(shrunk.type).toBe('image/jpeg')
 expect(shrunk.name).toBe('layar.jpg')
 expect(shrunk.size).toBe(300_000)
 expect(drawn.at(-1)).toEqual([1440,810])
})

test('a browser that cannot redraw it gives it back as it was',async()=>{
 vi.stubGlobal('createImageBitmap',undefined)
 const file=big()
 expect(await shrinkScreenshot(file)).toBe(file)
})
