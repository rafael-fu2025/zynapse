/**
 * ESLint flat config — typescript-eslint strict + react + react-hooks.
 * Mirrors the strictness of `tsconfig.json`.
 */
import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';

export default tseslint.config(
  { ignores: ['dist', 'node_modules', 'coverage'] },
  js.configs.recommended,
  ...tseslint.configs.recommendedTypeChecked,
  // Plain JS files (this config, postcss.config.js) and TS files outside
  // the tsconfig project (Playwright e2e) cannot use typed rules.
  {
    files: ['**/*.{js,cjs,mjs}', 'e2e/**/*.ts', 'playwright.config.ts'],
    ...tseslint.configs.disableTypeChecked,
    languageOptions: {
      globals: {
        __dirname: 'readonly',
        console: 'readonly',
        fetch: 'readonly',
        module: 'readonly',
        process: 'readonly',
        require: 'readonly',
      },
    },
    rules: {
      ...tseslint.configs.disableTypeChecked.rules,
      '@typescript-eslint/no-require-imports': 'off',
    },
  },
  {
    files: ['**/*.{ts,tsx}'],
    ignores: ['e2e/**', 'playwright.config.ts'],
    languageOptions: {
      parserOptions: {
        project: ['./tsconfig.json'],
        tsconfigRootDir: import.meta.dirname,
      },
    },
    plugins: {
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      'react-refresh/only-export-components': 'off',
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      '@typescript-eslint/consistent-type-imports': 'error',
      '@typescript-eslint/no-explicit-any': 'error',
    },
  },
);
