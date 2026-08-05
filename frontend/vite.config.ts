import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

const apiProxyTarget = process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8090';
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
    // Crawl every lazy page on dev-server boot so Vite pre-bundles
    // their deps up-front instead of discovering them on first
    // navigation. Without this the first click on any route lands
    // before the optimizer finishes and the browser rejects the
    // dynamic import with "Failed to fetch dynamically imported
    // module" - the user sees the error boundary and has to F5.
    // We only list the page files (the actual lazy entry points);
    // libs and components are reachable through them, so listing
    // them here would just bloat the boot crawl with no extra
    // coverage. The `include` list below still covers all the
    // packages any of those files might import.
    entries: [
      'index.html',
      'src/main.tsx',
      'src/router.tsx',
      'src/pages/**/*.tsx',
    ],
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
    // Pre-transform the always-mounted shell so the first paint does
    // not pay the TSX/JSX transform cost on top of the dep optimizer
    // finishing. Documented at:
    //   https://vite.dev/guide/performance#warm-up-frequently-used-files
    warmup: {
      clientFiles: [
        './src/main.tsx',
        './src/router.tsx',
        './src/components/Layout.tsx',
        './src/components/AppSidebar.tsx',
        './src/components/CommandPalette.tsx',
      ],
    },
    proxy: {
      // Dev proxy defaults to `php spark serve` on 8090 so `npm run dev`
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
