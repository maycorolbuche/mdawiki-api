import { imageSize } from 'image-size'

export const maxBytes = 5_000_000
export const formats: Record<string, string> = { jpg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp' }

export function readImage(bytes: Uint8Array) {
  if (bytes.length > maxBytes) return null
  try {
    const { type, width, height } = imageSize(bytes)
    const extension = type ?? ''
    if (!Object.hasOwn(formats, extension) || !width || !height || width * height > 20_000_000) return null
    return { extension, width, height, size_bytes: bytes.length }
  } catch {
    return null
  }
}

export function decodeUpload(data: unknown) {
  if (typeof data !== 'string' || data.length > Math.ceil(maxBytes / 3) * 4 + 32) return null
  const encoded = /^data:image\/(?:png|jpeg|gif|webp);base64,([a-z0-9+/]+={0,2})$/i.exec(data)?.[1]
  if (!encoded) return null
  try {
    return Uint8Array.from(atob(encoded), character => character.charCodeAt(0))
  } catch {
    return null
  }
}

