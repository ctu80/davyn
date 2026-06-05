import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'

// The SPA is served behind Caddy under /app/* and talks to the existing
// PHP API at /api/*. base '/app/' makes all emitted asset URLs absolute
// under /app/ so Caddy's strip_prefix file_server can resolve them.
export default defineConfig({
  base: '/app/',
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: { '@': path.resolve(__dirname, 'src') },
  },
  server: {
    proxy: {
      '/api': 'http://localhost:8080',
      '/dav': 'http://localhost:8080',
      '/login': 'http://localhost:8080',
      '/logout': 'http://localhost:8080',
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
})
