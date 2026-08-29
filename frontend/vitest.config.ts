import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

/**
 * Unit-test runner. Node environment on purpose: these tests cover pure
 * logic (schemas, envelope normalization, date conversion, error-code
 * mapping). Component tests would need jsdom + @testing-library — add
 * them when the first component test lands.
 */
export default defineConfig({
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
});
