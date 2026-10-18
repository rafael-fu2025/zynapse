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
import { AlertCircle, Eye, EyeOff, Loader2, Moon, Sun } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { ApiEnvelopeError } from '@/api/envelope';
import { humanizeCode } from '@/api/errorCodes';
import { LoginCarousel } from '@/components/LoginCarousel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useLogin } from '@/hooks/useAuth';
import { useCarouselSlides } from '@/hooks/useCarouselSlides';
import { useTheme } from '@/hooks/useTheme';
import { loginSchema, type LoginInput } from '@/schemas/auth';
import { toLoginSlides, type LoginSlide } from '@/schemas/carousel';

/**
 * Carousel fallback — used while the public query is in flight and
 * whenever the endpoint returns no rows. Lives in the LoginPage rather
 * than LoginCarousel so the component stays purely presentational and
 * the wiring (API + fallback) is visible in one place.
 */
const FALLBACK_SLIDES: LoginSlide[] = [
  {
    lightSrc: '/assets/img/brand/fuel-bg.jpg',
    darkSrc: '/assets/img/brand/FoundationU-Dark.png',
    title: 'Foundation University',
    subtitle: 'Health & Guidance Services Portal',
    fitMode: 'cover',
  },
  {
    lightSrc: '/assets/img/brand/guidance-center.png',
    darkSrc: '/assets/img/brand/guidance-center.png',
    title: 'Guidance & Counseling Center',
    subtitle: 'Confidential Support & Mental Wellness Services',
    fitMode: 'contain_blur',
  },
  {
    lightSrc: '/assets/img/brand/health-services.png',
    darkSrc: '/assets/img/brand/health-services.png',
    title: 'University Health Services',
    subtitle: 'Accessible Campus Medical & Wellness Care',
    fitMode: 'contain_blur',
  },
];

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
  const { theme, toggleTheme } = useTheme();
  const [showPassword, setShowPassword] = useState(false);
  const [loginError, setLoginError] = useState<string | null>(null);
  const loginErrorRef = useRef<HTMLDivElement>(null);
  const carousel = useCarouselSlides();
  const slides = useMemo<LoginSlide[]>(() => {
    if (carousel.data !== undefined && carousel.data.length > 0) {
      return toLoginSlides(carousel.data);
    }
    return FALLBACK_SLIDES;
  }, [carousel.data]);

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
        Welcome pane — auto-advancing 3-slide photo carousel sitting
        over the moove theme's maroon underlay, inset from the page
        edge inside a rounded container. The underlay shows only as a
        brief flash before the first slide's image paints (cover-mode
        slides fill the frame; contain_blur slides fill it with their
        blurred backdrop, so the maroon never bleeds through).
        `hidden lg:block` mirrors the reference's d-none d-lg-flex: on
        phones the form pane takes the whole screen.
      */}
      <aside className="relative hidden p-2 lg:block">
        <div className="relative h-full w-full overflow-hidden rounded-xl bg-[#ac1e37]">
          <LoginCarousel slides={slides} />
          {/* Shield over the carousel — blocks long-press context menu,
              image drag handles, and right-click from reaching the
              <img> elements inside. `pointer-events-none` so clicks
              pass through to the indicator dots below; the
              contextmenu/dragstart handlers still fire on the shield
              itself when it sits on top. */}
          <div
            aria-hidden
            onContextMenu={swallow}
            onDragStart={swallow}
            className="no-copy pointer-events-none absolute inset-0 z-20 select-none"
          />
        </div>
      </aside>

      {/*
        Form pane. The form column is centred in the leftover space and
        the Socials row sits at the bottom; short viewports scroll
        instead of overlapping. Every accessible name the form had is
        unchanged.
      */}
      <section className="relative flex flex-col items-center p-6 sm:p-10">
        {/* Theme toggle — positioned in the upper right corner */}
        <button
          type="button"
          onClick={toggleTheme}
          aria-label={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
          className="absolute right-6 top-6 grid size-9 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background sm:right-10 sm:top-10"
        >
          {theme === 'dark' ? (
            <Sun aria-hidden className="size-5" />
          ) : (
            <Moon aria-hidden className="size-5" />
          )}
        </button>
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
                Access university health, guidance, and composting services.
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

        {/* A quiet, labelled social dock: the seals keep their colours,
            while the surface and focus states follow the active theme. */}
        <nav aria-label="Socials" className="flex w-full max-w-xs flex-col items-center gap-3 pt-10">
          <div className="flex w-full items-center gap-3">
            <span aria-hidden className="h-px flex-1 bg-border" />
            <span className="text-[0.6rem] font-medium tracking-wide text-muted-foreground">
              Connect with us
            </span>
            <span aria-hidden className="h-px flex-1 bg-border" />
          </div>
          <TooltipProvider delayDuration={150}>
            <div className="grid w-full grid-cols-3 rounded-[2rem] border border-border bg-muted/40 p-1.5 shadow-sm">
              {SOCIALS.map((social) => (
                <Tooltip key={social.label}>
                  <TooltipTrigger asChild>
                    <a
                      href={social.href}
                      target="_blank"
                      rel="noopener noreferrer"
                      aria-label={`${social.label} on Facebook (opens in a new tab)`}
                      onContextMenu={swallow}
                      onDragStart={swallow}
                      className="group flex min-w-0 flex-col items-center gap-2 rounded-[1.6rem] px-1 py-3 text-center text-muted-foreground transition-colors hover:bg-background hover:text-foreground hover:shadow-sm focus-visible:bg-background focus-visible:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                    >
                      <span className="grid size-11 shrink-0 place-items-center rounded-full bg-white p-0.5 ring-1 ring-black/5 motion-safe:transition-transform motion-safe:group-hover:-translate-y-0.5">
                        <img
                          src={social.src}
                          alt=""
                          draggable={false}
                          onDragStart={swallow}
                          onContextMenu={swallow}
                          className="no-copy size-full select-none rounded-full object-contain"
                        />
                      </span>
                      <span className="flex min-h-[2.5em] items-center justify-center text-[0.6rem] font-medium leading-tight">
                        {social.label}
                      </span>
                    </a>
                  </TooltipTrigger>
                  <TooltipContent side="top" sideOffset={8} className="max-w-[calc(100vw-2rem)] text-center">
                    <span className="block font-medium">{social.label} on Facebook</span>
                    <span className="block text-[0.6rem]">Opens in a new tab</span>
                  </TooltipContent>
                </Tooltip>
              ))}
            </div>
          </TooltipProvider>
        </nav>
      </section>
    </main>
  );
}
