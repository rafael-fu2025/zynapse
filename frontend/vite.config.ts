import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

const apiProxyTarget = process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8080';
const apiProxyPrefix = process.env.VITE_API_PROXY_PREFIX ?? '';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  optimizeDeps: {
    // Pre-bundle everything the React.lazy pages import. Without this,
    // the dev server discovers these deps on first navigation, re-runs
    // the optimizer and FORCE-RELOADS the page ("optimized dependencies
    // changed. reloading") — which drops the in-memory access token.
    include: [
      'react',
      'react-dom',
      'react-router-dom',
      '@tanstack/react-query',
      '@tanstack/react-table',
      'axios',
      'zod',
      'zustand',
      'sonner',
      'react-hook-form',
      '@hookform/resolvers/zod',
      'html5-qrcode',
      'qrcode.react',
      'date-fns',
      'date-fns-tz',
      'react-day-picker',
      'lucide-react',
      'clsx',
      'tailwind-merge',
      'class-variance-authority',
      '@radix-ui/react-checkbox',
      '@radix-ui/react-dialog',
      '@radix-ui/react-dropdown-menu',
      '@radix-ui/react-label',
      '@radix-ui/react-popover',
      '@radix-ui/react-select',
      '@radix-ui/react-separator',
      '@radix-ui/react-slot',
      '@radix-ui/react-tabs',
      '@radix-ui/react-tooltip',
    ],
  },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      // Dev proxy defaults to `php spark serve` on 8080 so `npm run dev`
      // works out of the box. dev-fast.ps1 sets VITE_API_PROXY_TARGET to
      // 8091 to match its own backend; override either way as needed.
      '/api': {
        target: apiProxyTarget,
        changeOrigin: false,
        secure: false,
        rewrite: (requestPath) => apiProxyPrefix + requestPath,
      },
    },
  },
  build: {
    sourcemap: false,
    target: 'es2022',
  },
});
