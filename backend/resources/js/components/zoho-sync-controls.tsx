import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { api } from '@/lib/api-client';
import { Button } from '@/components/ui/button';

export function ZohoSyncStatus() {
  const queryClient = useQueryClient();
  const { data, error } = useQuery({ queryKey: ['zoho-sync-status'], queryFn: () => api.zohoSyncStatus(), staleTime: 30_000 });
  const emergency = useMutation({
    mutationFn: () => api.zohoEmergencySync(),
    onSuccess: result => toast.success(result.message),
    onError: (error: Error) => toast.error(error.message),
    onSettled: () => { void queryClient.invalidateQueries({ queryKey: ['zoho-sync-status'] }); },
  });
  const addon = useMutation({
    mutationFn: async () => {
      const raw = window.prompt('Temporary Zoho add-on calls to use (maximum 450; leave 50 reserved):', String(data?.budget.addon_limit || 450));
      if (raw === null) throw new Error('Add-on sync cancelled.');
      const limit = Number(raw);
      if (!Number.isInteger(limit) || limit < 1 || limit > 450) throw new Error('Enter a whole number from 1 to 450.');
      await api.zohoConfigureAddon(limit);
      return api.zohoAddonSync();
    },
    onSuccess: result => toast.success(result.message),
    onError: (error: Error) => toast.error(error.message),
    onSettled: () => { void queryClient.invalidateQueries({ queryKey: ['zoho-sync-status'] }); },
  });
  return <div className="text-xs text-muted-foreground space-y-1 mb-3" aria-live="polite">
    <p>Automatic sync: daily at 3:00 AM IST. Use “Sync this record” for urgent updates.</p>
    {data && <p>{data.budget.used} / {data.budget.limit} calls used today · {data.pending} records awaiting complete details or PDFs.</p>}
    {data && <p>Automatic reads: {data.budget.pools.automatic.used} / 1,800 · Manual reads: {data.budget.pools.manual_read.used} / 100 · Quote writes: {data.budget.pools.write.used} / 100.</p>}
    {data && data.budget.pools.emergency.remaining > 0 && (data.evening?.pause_reason === 'automatic_daily' || data.evening?.pause_reason === 'emergency_reserve') && <Button size="sm" variant="outline" className="mt-1 h-7 text-xs" disabled={emergency.isPending} onClick={() => { if (window.confirm('Use the remaining 200 reserved Zoho API calls now to resume the saved sync cursor? This may leave no emergency reserve for today.')) emergency.mutate(); }}>{emergency.isPending ? 'Running emergency sync…' : `Emergency resume (${data.budget.pools.emergency.remaining} calls)`}</Button>}
    {data && data.budget.addon_limit > data.budget.addon_used && <Button size="sm" variant="outline" className="mt-1 ml-1 h-7 text-xs border-blue-500/50 text-blue-700" disabled={addon.isPending} onClick={() => addon.mutate()}>{addon.isPending ? 'Running add-on sync…' : `Add-on resume (${data.budget.addon_limit - data.budget.addon_used} calls)`}</Button>}
    <p>Quotes are sent as drafts. The accountant creates invoices in Zoho Books; the portal fetches them.</p>
    <p>If the morning run exhausts 1,800 calls, it can resume from 11 PM until midnight IST using unused reservations.</p>
    {data && <p>Evening calls used: {data.budget.evening_used}. {data.evening?.evening_eligible_date && !data.evening.completed_at ? `Eligible morning run: ${data.evening.evening_eligible_date}.` : 'No eligible unfinished morning run.'}</p>}
    <p>Deletion handling is manual while this Zoho plan has no outgoing webhooks. Use “Sync this record” for an urgent check; automatic deletion checks use no API calls.</p>
    {!!data?.deletions.failed && <p role="alert" className="text-destructive">{data.deletions.failed} deletion events need administrator review and replay.</p>}
    {data?.last_run && <p>Last sync: {data.last_run.summary}</p>}
    {error && <p>Sync usage is temporarily unavailable.</p>}
  </div>;
}

export function ZohoRecordSync({ id, onSynced }: { id: number; onSynced: () => void }) {
  const queryClient = useQueryClient();
  const refresh = useMutation({
    mutationFn: () => api.zohoRefreshRecord(id),
    onSuccess: result => { toast.success(result.message); onSynced(); },
    onError: (error: Error) => { toast.error(error.message); onSynced(); },
    onSettled: () => { void queryClient.invalidateQueries({ queryKey: ['zoho-sync-status'] }); },
  });
  const pdf = useMutation({ mutationFn: () => api.downloadZohoPdf(id), onError: (error: Error) => toast.error(error.message) });
  return <span className="inline-flex gap-1 ml-2">
    <Button size="sm" variant="outline" className="h-7 text-xs" disabled={refresh.isPending} onClick={() => refresh.mutate()}>
      {refresh.isPending ? 'Syncing…' : 'Sync this record'}
    </Button>
    <Button size="sm" variant="ghost" className="h-7 text-xs" disabled={pdf.isPending} onClick={() => pdf.mutate()}>PDF</Button>
  </span>;
}
