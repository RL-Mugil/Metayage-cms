export const statusColor = (s: string): 'default' | 'secondary' | 'destructive' | 'outline' => {
  if (['Paid', 'Active', 'Done', 'Registered', 'Approved'].includes(s)) return 'default'
  if (['Overdue', 'Blocked', 'Rejected'].includes(s)) return 'destructive'
  if (['Draft', 'Todo', 'Onboarding', 'Probation'].includes(s)) return 'outline'
  return 'secondary'
}
