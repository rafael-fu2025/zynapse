/**
 * ChangePasswordPage — self-service password rotation (Phase 10).
 *
 * Reached voluntarily or forced: when `/auth/me` reports
 * `force_reset: true` (admin-issued temporary password), the Layout
 * redirects every route here until the password is rotated.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import { KeyRound, Loader2, TriangleAlert } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useChangePassword, useMe } from '@/hooks/useAuth';
import { changePasswordSchema, type ChangePasswordInput } from '@/schemas/auth';

export default function ChangePasswordPage() {
  const me = useMe();
  const change = useChangePassword();
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<ChangePasswordInput>({ resolver: zodResolver(changePasswordSchema) });

  const onSubmit = handleSubmit((values) => change.mutate(values));

  return (
    <main className="mx-auto max-w-md space-y-4 p-6">
      <header className="space-y-2">
        <h1 className="flex items-center gap-2 text-xl font-semibold text-foreground">
          <KeyRound className="size-5" /> Change password
        </h1>
        {me.data?.force_reset === true ? (
          <Alert variant="destructive">
            <TriangleAlert className="size-4" />
            <AlertTitle>Password reset required</AlertTitle>
            <AlertDescription>
              Your password was reset by an administrator. Choose a new one to continue.
            </AlertDescription>
          </Alert>
        ) : (
          <p className="text-sm text-muted-foreground">
            Changing your password signs out every other session.
          </p>
        )}
      </header>

      <Card>
        <CardContent className="pt-6">
          <form onSubmit={(e) => void onSubmit(e)} className="space-y-3" noValidate>
            <div className="space-y-1.5">
              <Label htmlFor="current_password">Current password</Label>
              <Input id="current_password" type="password" autoComplete="current-password" aria-invalid={errors.current_password !== undefined} {...register('current_password')} />
              {errors.current_password !== undefined && (
                <p role="alert" className="text-xs text-destructive">{errors.current_password.message}</p>
              )}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="new_password">New password (min 12 chars)</Label>
              <Input id="new_password" type="password" autoComplete="new-password" aria-invalid={errors.new_password !== undefined} {...register('new_password')} />
              {errors.new_password !== undefined && (
                <p role="alert" className="text-xs text-destructive">{errors.new_password.message}</p>
              )}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="confirm_password">Confirm new password</Label>
              <Input id="confirm_password" type="password" autoComplete="new-password" aria-invalid={errors.confirm_password !== undefined} {...register('confirm_password')} />
              {errors.confirm_password !== undefined && (
                <p role="alert" className="text-xs text-destructive">{errors.confirm_password.message}</p>
              )}
            </div>
            <Button type="submit" disabled={change.isPending} className="w-full">
              {change.isPending && <Loader2 className="animate-spin" />} Change password
            </Button>
          </form>
        </CardContent>
      </Card>
    </main>
  );
}
