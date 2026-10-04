import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

/**
 * Unit-test runner. Node environment on purpose: these tests cover pure
 * logic (schemas, envelope normalization, date conversion, error-code
 * mapping) plus component markup rendered through `react-dom/server`
 * (no DOM needed — assertions run against static markup). The day a
 * test needs to INTERACT (click, type), add jsdom + @testing-library.
 */
export default defineConfig({
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
  },
});
