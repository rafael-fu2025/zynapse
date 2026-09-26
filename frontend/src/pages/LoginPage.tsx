/**
 * LoginPage — WCAG 2.2 AA, react-hook-form + zod, Sonner toasts.
 * Built from shadcn Card / Label / Input / Button primitives.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import { AlertCircle, Eye, EyeOff, Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { ApiEnvelopeError } from '@/api/envelope';
import { humanizeCode } from '@/api/errorCodes';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLogin } from '@/hooks/useAuth';
import { loginSchema, type LoginInput } from '@/schemas/auth';

/**
 * The two services this portal fronts, shown as a branding strip under
 * the card. The artwork is supplied as circular seals on transparent
 * PNG, so it keeps its own colours (maroon/navy and teal) rather than
 * following the theme — only the caption is themed. `alt` stays empty
 * because the caption beside each mark already names it; giving the
 * image the same text would announce the service twice.
 */
const SERVICE_MARKS = [
  { src: '/guidance-center.png', label: 'Guidance Center' },
  { src: '/health-services.png', label: 'Health Services' },
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
  // artwork — the background photo, the SYNAPSE mark and the service
  // seals. This is a UX deterrent, not real protection: the assets are
  // still fetchable from /FU-Social-Garden.jpg and friends. For real
  // protection, put a hotlink rule + signed URLs in the web server
  // (see README).
  const swallow = (e: React.SyntheticEvent) => {
    e.preventDefault();
    e.stopPropagation();
  };

  return (
    /*
      Column layout rather than `place-items-center`: the branding strip
      below is a real flow child, so on short viewports the card and the
      strip push apart and the page scrolls instead of overlapping.
      `overflow-x-hidden` (not `overflow-hidden`) leaves that vertical
      scroll reachable; the background layers are `fixed`, so nothing
      needs clipping here to contain them.
    */
    <main className="relative flex min-h-dvh flex-col items-center overflow-x-hidden bg-background p-6">
      {/*
        Background image. The img is `pointer-events-none` and the
        transparent shield above it absorbs right-click / long-press /
        drag so users can't pull the asset out of the DOM. The shield
        is `aria-hidden` because it carries no semantics.
      */}
      <div
        aria-hidden
        className="no-copy pointer-events-none fixed inset-0 select-none"
        style={{ zIndex: 0 }}
      >
        <img
          src="/FU-Social-Garden.jpg"
          alt=""
          draggable={false}
          onDragStart={swallow}
          onContextMenu={swallow}
          className="no-copy size-full select-none object-cover"
        />
      </div>
      {/* Transparent shield over the image — blocks long-press context
          menu, image-drag handles, and right-click from reaching the
          <img> underneath. */}
      <div
        aria-hidden
        onContextMenu={swallow}
        onDragStart={swallow}
        className="no-copy fixed inset-0 select-none"
        style={{ zIndex: 1 }}
      />

      <Card
        /*
          `my-auto shrink-0` centres the card in the space left above the
          branding strip, and refuses the vertical squash a column flex
          would otherwise apply on short viewports.
        */
        className="relative my-auto w-full max-w-sm shrink-0 shadow-lg ring-1 ring-black/5 backdrop-blur-sm"
        style={{ zIndex: 2 }}
        aria-labelledby="login-title"
      >
        {/* Branded header — SYNAPSE mark on a light surface so the
            maroon artwork reads. */}
        <CardHeader className="items-center gap-3 text-center">
          <img
            src="/synapse-maroon.png"
            alt=""
            className="no-copy h-14 w-auto select-none object-contain"
            draggable={false}
            onDragStart={swallow}
            onContextMenu={swallow}
          />
          <div className="space-y-1">
            <CardTitle id="login-title" className="text-xl">
              Sign in to SYNAPSE
            </CardTitle>
            <CardDescription>
              Use your university ID number and password. All access is audited.
            </CardDescription>
          </div>
        </CardHeader>

        <CardContent>
          <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-4">
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
              <Label htmlFor="identifier">Student / Employee number</Label>
              <Input
                id="identifier"
                type="text"
                // Autofill stores the admin email here — the wire payload
                // splitter (loginWirePayload) sends emails as `email`,
                // so both credential kinds work through one field.
                autoComplete="username"
                inputMode="text"
                aria-invalid={errors.identifier !== undefined}
                aria-describedby={
                  errors.identifier !== undefined ? 'identifier-err' : 'identifier-hint'
                }
                {...register('identifier', { onChange: () => setLoginError(null) })}
              />
              {errors.identifier !== undefined ? (
                <p id="identifier-err" role="alert" className="text-xs text-destructive">
                  {errors.identifier.message}
                </p>
              ) : (
                <p id="identifier-hint" className="text-xs text-muted-foreground">
                  Administrators: sign in with your email address.
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="password">Password</Label>
              <div className="relative">
                <Input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
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
        </CardContent>

        <CardFooter>
          <p className="text-xs text-muted-foreground">
            Trouble signing in? Contact your system administrator.
          </p>
        </CardFooter>
      </Card>

      {/*
        Service branding. Fixed dark glass rather than a bare overlay on
        purpose: the photo is the same asset in both colour schemes, and
        on an ultra-wide viewport `object-cover` crops it to its bright
        mid-band, where the captions lose contrast. Measured over the
        real backdrop at 2560x800, a 40% scrim left the worst pixel at
        3.0:1 against white; 60% holds 6.0:1 there even if
        `backdrop-filter` is unavailable, and ~10:1 with the blur
        applied. Don't lighten it back toward 40% without re-measuring.
      */}
      <footer
        onContextMenu={swallow}
        onDragStart={swallow}
        className="no-copy relative mt-6 flex flex-wrap items-center justify-center gap-x-5 gap-y-3 rounded-2xl bg-black/60 px-5 py-3 ring-1 ring-white/15 backdrop-blur-md"
        style={{ zIndex: 2 }}
      >
        {SERVICE_MARKS.map((mark) => (
          <div key={mark.label} className="flex items-center gap-2.5">
            <img
              src={mark.src}
              alt=""
              draggable={false}
              onDragStart={swallow}
              onContextMenu={swallow}
              className="size-12 shrink-0 select-none object-contain sm:size-14"
            />
            <span className="text-xs font-semibold leading-tight text-white sm:text-sm">
              {mark.label}
            </span>
          </div>
        ))}
      </footer>
    </main>
  );
}
