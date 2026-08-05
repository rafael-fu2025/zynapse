/**
 * Button — shadcn/ui (new-york), Radix Slot polymorphism via asChild.
 */
import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors touch-manipulation focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        default: 'bg-primary text-primary-foreground shadow hover:bg-primary/90',
        destructive: 'bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90',
        outline:
          'border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground hover:outline-1 hover:outline-primary active:bg-primary active:text-primary-foreground active:border-primary data-[state=open]:bg-primary data-[state=open]:text-primary-foreground data-[state=open]:border-primary focus-visible:ring-0! focus-visible:border-foreground/40',
        secondary: 'bg-secondary text-secondary-foreground shadow-sm hover:bg-secondary/80',
        ghost: 'hover:bg-accent hover:text-accent-foreground',
        link: 'text-primary underline-offset-4 hover:underline',
      },
      // Mobile-first touch sizing: 40px targets below `md` (WCAG 2.5.8
      // plus headroom toward the 44px iOS guidance); `md:` restores the
      // original compact desktop heights so dense rails are unchanged.
      size: {
        default: 'h-10 px-4 py-2 md:h-9',
        sm: 'h-10 rounded-md px-3 text-xs md:h-8',
        lg: 'h-10 rounded-md px-8',
        icon: 'h-10 w-10 md:h-9 md:w-9',
        'icon-sm': 'h-10 w-10 md:h-8 md:w-8',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  },
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean;
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, asChild = false, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';
    return <Comp className={cn(buttonVariants({ variant, size, className }))} ref={ref} {...props} />;
  },
);
Button.displayName = 'Button';

export { Button, buttonVariants };
