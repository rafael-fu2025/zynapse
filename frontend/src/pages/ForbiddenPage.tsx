/**
 * ForbiddenPage — friendly 403 with a return-home link.
 */
import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';

export default function ForbiddenPage() {
  return (
    <main className="grid min-h-dvh place-items-center p-6">
      <section className="max-w-md text-center">
        <h1 className="text-3xl font-semibold text-foreground">403 — Forbidden</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Your account does not have the required permission. If you believe this is
          wrong, contact your administrator.
        </p>
        <Button asChild className="mt-4">
          <Link to="/">Return to dashboard</Link>
        </Button>
      </section>
    </main>
  );
}
