import { cva, type VariantProps } from 'class-variance-authority'
import type { ButtonHTMLAttributes } from 'react'
import { cn } from '../../shared/lib/cn'

const buttonVariants = cva(
  'inline-flex h-10 items-center justify-center gap-2 border px-4 text-sm font-semibold transition-colors disabled:pointer-events-none disabled:opacity-50',
  {
    variants: {
      variant: {
        primary:
          'border-oxide-700 bg-oxide-600 text-white hover:bg-oxide-700',
        secondary:
          'border-paper-300 bg-paper-50 text-ink-900 hover:border-ink-600 hover:bg-white',
        ghost: 'border-transparent bg-transparent text-ink-700 hover:bg-paper-200',
      },
    },
    defaultVariants: {
      variant: 'primary',
    },
  },
)

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> &
  VariantProps<typeof buttonVariants>

export function Button({ className, variant, type = 'button', ...props }: ButtonProps) {
  return (
    <button
      type={type}
      className={cn(buttonVariants({ variant }), className)}
      {...props}
    />
  )
}
