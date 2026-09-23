import type { Options } from './types'

export function roomLabel(room: Options['rooms'][number] | undefined, libraries: Options['libraries']): string {
  if (!room) return ''
  const code = room.slims_location_id || ''
  const location = libraries.find(library => library.location_id === code)?.location_name || 'Lokasi belum ditentukan'
  return `${room.room_name} — ${location}${code ? ` (${code})` : ''}`
}
