/**
 * SYNAPSE SPA — entry point.
 *
 * Strict mode + React 18 createRoot. Provider order:
 *   1. QueryProvider (TanStack Query cache)
 *   2. RouterProvider (React Router v6)
 */
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { AppRouter } from './router';
import { QueryProvider } from './providers/QueryProvider';
import './styles/index.css';

const container = document.getElementById('root');
if (container === null) {
  throw new Error('Root container #root not found in index.html.');
}

createRoot(container).render(
  <StrictMode>
    <QueryProvider>
      <AppRouter />
    </QueryProvider>
  </StrictMode>,
);