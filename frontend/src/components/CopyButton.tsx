/**
 * CopyButton — copy a string to the clipboard with success/error toast
 * and a transient checkmark. Used for one-time secrets and identifiers
 * (temporary passwords, QR tokens, kiosk IDs) that users must relay to
 * someone else, where manual selection is error-prone.
 */
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';

interface CopyButtonProps {
  /** The text to place on the clipboard. */
  value: string;
  /** Accessible label + tooltip (e.g. "Copy temporary password"). */
  label?: string;
  /** Toast shown on success. */
  successMessage?: string;
  className?: string;
}

export function CopyButton({
  value,
  label = 'Copy to clipboard',
  successMessage = 'Copied to clipboard.',
  className,
}: CopyButtonProps) {
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      toast.success(successMessage);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      toast.error('Could not access the clipboard. Copy manually.');
    }
  }

  return (
    <Button
      type="button"
      size="icon"
      variant="outline"
      aria-label={label}
      title={label}
      onClick={() => void copy()}
      className={className}
    >
      {copied ? <Check className="size-4" aria-hidden /> : <Copy className="size-4" aria-hidden />}
    </Button>
  );
}
