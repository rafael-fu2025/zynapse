/**
 * LoginPage — WCAG 2.2 AA, react-hook-form + zod, Sonner toasts.
 *
 * Split layout after fuel.foundationu.com's login (2026-09-27): the left
 * half is the campus-garden photo, the right half the sign-in form with
 * the Socials row (the offices' Facebook pages) pinned at its bottom.
 * The photo pane is hidden below `lg` and the form takes the full width
 * there. All behaviour — validation, the mutation, error focus,
 * autofill nudging — is unchanged; only the geometry moved.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import { AlertCircle, Eye, EyeOff, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { ApiEnvelopeError } from '@/api/envelope';
import { humanizeCode } from '@/api/errorCodes';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLogin } from '@/hooks/useAuth';
import { loginSchema, type LoginInput } from '@/schemas/auth';

/**
 * The offices this portal fronts, as circular seals linking to their
 * Facebook pages, shown under the form. The artwork keeps its own
 * colours (maroon/navy and teal) rather than following the theme.
 * `alt` stays empty because each link carries its own aria-label;
 * giving the image the same text would announce the service twice.
 */
const SOCIALS = [
  {
    src: '/guidance-center.png',
    label: 'Guidance Center',
    href: 'https://www.facebook.com/profile.php?id=100063778576372',
  },
  {
    src: '/FoundationU.png',
    label: 'Foundation University',
    href: 'https://www.facebook.com/foundationu.edu',
  },
  {
    src: '/health-services.png',
    label: 'Health Services',
    href: 'https://www.facebook.com/profile.php?id=100057107094031',
  },
] as const;

export default function LoginPage() {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginInput>({ resolver: zodResolver(loginSchema) });
  const login = useLogin();
  const [showPassword, setShowPassword] = useState(false);
  const [loginError, setLoginError] = useState<string | null>(null);
  const loginErrorRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (loginError !== null) loginErrorRef.current?.focus();
  }, [loginError]);

  // Chrome paints autofilled credentials before the Figtree webfont has
  // finished loading and never repaints them when it arrives — the value
  // keeps the fallback font (Arial) until the field is focused. Once the
  // fonts are ready, nudge a re-layout of autofilled inputs so the value
  // re-renders with the loaded font. When the font loads before Chrome
  // fills, the fill already paints correctly and the nudge is a no-op.
  useEffect(() => {
    let cancelled = false;
    void document.fonts.ready.then(() => {
      if (cancelled) return;
      document.querySelectorAll<HTMLInputElement>('input:-webkit-autofill').forEach((el) => {
        el.style.letterSpacing = '0.001px';
        void el.offsetWidth;
        el.style.removeProperty('letter-spacing');
      });
    });
    return () => {
      cancelled = true;
    };
  }, []);

  function describeLoginError(error: unknown): string {
    if (!(error instanceof ApiEnvelopeError)) {
      console.error('Login error:', error);
      return 'Login failed unexpectedly. Please try again.';
    }
    if (error.httpStatus === 0) return 'Cannot reach the SYNAPSE server. Check your connection and try again.';
    if (error.httpStatus >= 500) return 'The SYNAPSE server could not complete the login. Please try again shortly.';
    const primary = error.errors[0];
    if (primary === undefined) return 'Login failed. Please try again.';
    const friendly = humanizeCode(primary.code);
    return friendly !== primary.code ? friendly : (primary.message !== primary.code ? primary.message : 'Login failed. Please check your credentials and try again.');
  }

  const onSubmit = handleSubmit((data) => {
    setLoginError(null);
    login.mutate(data, {
      onError: (err) => {
        setLoginError(describeLoginError(err));
      },
    });
  });

  // Block the right-click / context menu and drag-start on this page's
  // artwork — the garden photo, the SYNAPSE mark and the service seals.
  // This is a UX deterrent, not real protection: the assets are still
  // fetchable from /fuel-bg.jpg and friends. For real protection, put a
  // hotlink rule + signed URLs in the web server (see README).
  const swallow = (e: React.SyntheticEvent) => {
    e.preventDefault();
    e.stopPropagation();
  };

  return (
    // 60/40 split (photo/form) — `3fr_2fr` rather than percentages so the
    // columns can never overflow the grid by a rounding pixel.
    <main className="grid min-h-dvh bg-background lg:grid-cols-[3fr_2fr]">
      {/*
        Welcome pane — the campus garden photo over the moove theme's
        maroon underlay, inset from the page edge inside a rounded
        container. No overlay panel: the photo speaks for itself.
        `hidden lg:block` mirrors the reference's d-none d-lg-flex: on
        phones the form pane takes the whole screen.
      */}
      <aside className="relative hidden p-2 lg:block">
        <div className="relative h-full w-full overflow-hidden rounded-xl bg-[#ac1e37]">
          {/* Day photo on the light theme, night photo in dark mode — same
              garden, so the swap reads as a lighting change, not a new
              page. */}
          <img
            src="/fuel-bg.jpg"
            alt=""
            draggable={false}
            onDragStart={swallow}
            onContextMenu={swallow}
            className="no-copy pointer-events-none absolute inset-0 size-full select-none object-cover dark:hidden"
          />
          <img
            src="/FoundationU-Dark.png"
            alt=""
            draggable={false}
            onDragStart={swallow}
            onContextMenu={swallow}
            className="no-copy pointer-events-none absolute inset-0 hidden size-full select-none object-cover dark:block"
          />
          {/* Shield over the photo — blocks long-press context menu, image
              drag handles, and right-click from reaching the <img>. */}
          <div aria-hidden onContextMenu={swallow} onDragStart={swallow} className="no-copy absolute inset-0 select-none" />
        </div>
      </aside>

      {/*
        Form pane. The form column is centred in the leftover space and
        the Socials row sits at the bottom; short viewports scroll
        instead of overlapping. Every accessible name the form had is
        unchanged.
      */}
      <section className="flex flex-col items-center p-6 sm:p-10">
        <div className="flex w-full max-w-sm flex-1 flex-col items-center justify-center">
          <div className="mb-8 flex flex-col items-center gap-3 text-center">
            {/* Maroon mark on the light surface, white mark in dark mode. */}
            <img
              src="/synapse-maroon.png"
              alt=""
              className="no-copy h-14 w-auto select-none object-contain dark:hidden"
              draggable={false}
              onDragStart={swallow}
              onContextMenu={swallow}
            />
            <img
              src="/synapse-white.png"
              alt=""
              className="no-copy hidden h-14 w-auto select-none object-contain dark:block"
              draggable={false}
              onDragStart={swallow}
              onContextMenu={swallow}
            />
            <div className="space-y-1">
              <h1 id="login-title" className="text-2xl font-semibold">
                Welcome back
              </h1>
              <p className="text-sm text-muted-foreground">
                Log in to the university health &amp; guidance portal.
              </p>
            </div>
          </div>

          <form noValidate onSubmit={(e) => void onSubmit(e)} className="w-full space-y-4">
            {loginError !== null && (
              <div
                ref={loginErrorRef}
                role="alert"
                aria-live="assertive"
                tabIndex={-1}
                className="flex gap-3 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive outline-none focus-visible:ring-2 focus-visible:ring-destructive/40"
              >
                <AlertCircle aria-hidden className="mt-0.5 size-4 shrink-0" />
                <div><p className="font-semibold">Unable to sign in</p><p className="mt-0.5">{loginError}</p></div>
              </div>
            )}
            <div className="space-y-1.5">
              <Label htmlFor="identifier">ID Number</Label>
              <Input
                id="identifier"
                type="text"
                placeholder="Enter your ID number"
                // Autofill stores the admin email here — the wire payload
                // splitter (loginWirePayload) sends emails as `email`,
                // so both credential kinds work through one field.
                autoComplete="username"
                inputMode="text"
                aria-invalid={errors.identifier !== undefined}
                aria-describedby={errors.identifier !== undefined ? 'identifier-err' : undefined}
                {...register('identifier', { onChange: () => setLoginError(null) })}
              />
              {errors.identifier !== undefined && (
                <p id="identifier-err" role="alert" className="text-xs text-destructive">
                  {errors.identifier.message}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="password">Password</Label>
              <div className="relative">
                <Input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  placeholder="Enter your password"
                  autoComplete="current-password"
                  className="pr-10"
                  aria-invalid={errors.password !== undefined}
                  aria-describedby={errors.password !== undefined ? 'password-err' : undefined}
                  {...register('password', { onChange: () => setLoginError(null) })}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  aria-pressed={showPassword}
                  className="absolute right-2 top-1/2 grid size-7 -translate-y-1/2 place-items-center rounded-md text-foreground/70 transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                >
                  {showPassword ? (
                    <EyeOff aria-hidden className="size-4" />
                  ) : (
                    <Eye aria-hidden className="size-4" />
                  )}
                </button>
              </div>
              {errors.password !== undefined && (
                <p id="password-err" role="alert" className="text-xs text-destructive">
                  {errors.password.message}
                </p>
              )}
            </div>

            <Button type="submit" className="w-full" disabled={isSubmitting || login.isPending}>
              {(isSubmitting || login.isPending) && <Loader2 className="animate-spin" />}
              Sign in
            </Button>
          </form>

          <p className="mt-6 text-center text-xs text-muted-foreground">
            Trouble signing in? Contact your system administrator.
          </p>
        </div>

        {/*
          Socials — the offices behind the portal, each seal a link to
          its Facebook page. Deliberately small and quiet: branding, not
          navigation.
        */}
        <nav aria-label="Socials" className="flex flex-col items-center gap-2 pt-10">
          <span className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
            Socials
          </span>
          {/* Same pill language as the header's profile pill
              (UserMenu): full round, primary-tinted border, translucent
              fill. */}
          <div className="flex items-center gap-4 rounded-full border border-primary/60 bg-background/60 px-4 py-1.5">
            {SOCIALS.map((social) => (
              <a
                key={social.label}
                href={social.href}
                target="_blank"
                rel="noreferrer"
                aria-label={`${social.label} on Facebook`}
                onContextMenu={swallow}
                onDragStart={swallow}
                className="rounded-full opacity-90 transition-opacity hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
              >
                <img
                  src={social.src}
                  alt=""
                  draggable={false}
                  onDragStart={swallow}
                  onContextMenu={swallow}
                  className="no-copy size-9 select-none object-contain"
                />
              </a>
            ))}
          </div>
        </nav>
      </section>
    </main>
  );
}
