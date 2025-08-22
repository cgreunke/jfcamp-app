// Standard same-origin. Per Env überschreibbar, wenn FE auf anderer Domain läuft.
export const API_BASE =
  (import.meta.env.VITE_API_BASE ? import.meta.env.VITE_API_BASE.replace(/\/$/, '') : '')
