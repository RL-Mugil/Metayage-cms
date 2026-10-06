import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export const statusColor = (s: string): 'default' | 'secondary' | 'destructive' | 'outline' | 'success' => {
  if (s === 'Granted') return 'success'
  if (['Paid', 'Active', 'Done', 'Registered', 'Approved', 'Completed'].includes(s)) return 'default'
  if (['Overdue', 'Blocked', 'Rejected', 'Closed', 'Abandoned', 'Cancelled', 'Refused', 'Revoked', 'Expired', 'Resigned', 'Terminated', 'Suspended'].includes(s)) return 'destructive'
  if (['Draft', 'Todo', 'Onboarding', 'Probation', 'Open', 'On Probation', 'On Leave'].includes(s)) return 'outline'
  return 'secondary'
}
