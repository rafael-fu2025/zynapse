/**
 * Calendar — shadcn/ui wrapper around react-day-picker v10.
 *
 * Tailwind-styled (design tokens, no external CSS import). Used by the
 * DatePicker; can also be used standalone. The v10 `classNames` keys
 * (month_caption, button_previous, day_button, ...) differ from the
 * pre-v9 API — see node_modules/react-day-picker/dist/esm/UI.d.ts.
 */
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { DayPicker } from 'react-day-picker';
import { cn } from '@/lib/utils';
import { buttonVariants } from '@/components/ui/button';

export type CalendarProps = React.ComponentProps<typeof DayPicker>;

export function Calendar({
  className,
  classNames,
  showOutsideDays = true,
  captionLayout = 'label',
  ...props
}: CalendarProps) {
  return (
    <DayPicker
      showOutsideDays={showOutsideDays}
      captionLayout={captionLayout}
      className={cn('p-3', className)}
      classNames={{
        months: 'flex flex-col sm:flex-row gap-2',
        month: 'flex flex-col gap-4',
        month_caption: 'flex justify-center pt-1 relative items-center h-7',
        // In dropdown caption layout the month/year selects already show
        // the period, so keep the redundant text label screen-reader-only
        // (prevents it overlapping the dropdowns).
        caption_label: 'sr-only',
        dropdowns: 'flex items-center justify-center gap-2 text-sm font-medium',
        dropdown_root: 'relative inline-flex items-center',
        dropdown: 'rounded-md border border-input bg-background px-2 py-1 text-sm',
        nav: 'absolute inset-x-0 top-1 flex items-center justify-between px-1',
        button_previous: cn(
          buttonVariants({ variant: 'outline' }),
          'size-7 bg-transparent p-0 opacity-70 hover:opacity-100',
        ),
        button_next: cn(
          buttonVariants({ variant: 'outline' }),
          'size-7 bg-transparent p-0 opacity-70 hover:opacity-100',
        ),
        month_grid: 'w-full border-collapse space-y-1',
        weekdays: 'flex',
        weekday: 'text-muted-foreground w-8 font-normal text-[0.8rem]',
        week: 'flex w-full mt-2',
        day: 'relative p-0 text-center text-sm',
        day_button: cn(
          buttonVariants({ variant: 'ghost' }),
          'size-8 p-0 font-normal aria-selected:opacity-100',
        ),
        selected:
          'bg-primary text-primary-foreground rounded-md hover:bg-primary hover:text-primary-foreground focus:bg-primary focus:text-primary-foreground',
        today: 'bg-accent text-accent-foreground rounded-md',
        outside: 'text-muted-foreground opacity-50',
        disabled: 'text-muted-foreground opacity-50',
        hidden: 'invisible',
        ...classNames,
      }}
      components={{
        Chevron: ({ orientation }) => {
          const Icon = orientation === 'left' ? ChevronLeft : ChevronRight;
          return <Icon className="size-4" />;
        },
      }}
      {...props}
    />
  );
}
