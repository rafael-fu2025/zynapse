/**
 * LoginPage — WCAG 2.2 AA, react-hook-form + zod, Sonner toasts.
 * Built from shadcn Card / Label / Input / Button primitives.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import { Eye, EyeOff, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link } from 'react-router-dom';
import { toast } from 'sonner';
import { ApiEnvelopeError } from '@/api/envelope';
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

export default function LoginPage() {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginInput>({ resolver: zodResolver(loginSchema) });
  const login = useLogin();
  const [showPassword, setShowPassword] = useState(false);

  const onSubmit = handleSubmit((data) => {
    login.mutate(data, {
      onError: (err) => {
        const message =
          err instanceof ApiEnvelopeError ? err.errors[0]?.message ?? 'Login failed.' : 'Login failed.';
        toast.error(message);
      },
    });
  });

  // Block the right-click / context menu and drag-start on the background
  // image. This is a UX deterrent, not real protection — the asset is
  // still fetchable from /FU-Social-Garden.jpg. For real protection, put
  // a hotlink rule + signed URLs in the web server (see README).
  const swallow = (e: React.SyntheticEvent) => {
    e.preventDefault();
    e.stopPropagation();
  };

  return (
    <main className="relative grid min-h-dvh place-items-center overflow-hidden bg-background p-6">
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
        className="relative w-full max-w-sm shadow-lg ring-1 ring-black/5 backdrop-blur-sm"
        style={{ zIndex: 2 }}
        aria-labelledby="login-title"
      >
        <CardHeader>
          <CardTitle id="login-title" className="text-xl">
            Sign in to SYNAPSE
          </CardTitle>
          <CardDescription>Use your university credentials. All access is audited.</CardDescription>
        </CardHeader>

        <CardContent>
          <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="email">Email</Label>
              <Input
                id="email"
                type="email"
                autoComplete="email"
                aria-invalid={errors.email !== undefined}
                aria-describedby={errors.email !== undefined ? 'email-err' : undefined}
                {...register('email')}
              />
              {errors.email !== undefined && (
                <p id="email-err" role="alert" className="text-xs text-destructive">
                  {errors.email.message}
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
                  {...register('password')}
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
            Trouble signing in?{' '}
            <Link to="/support" className="text-primary underline underline-offset-4">
              Contact support
            </Link>
            .
          </p>
        </CardFooter>
      </Card>
    </main>
  );
}
