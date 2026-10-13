import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { api } from '@/lib/api-client';
import type { ZohoQuotePreview } from '@/types/zoho';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

export function ZohoQuoteExport({ kind, id }: { kind: 'standard' | 'in'; id: number }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [customerId, setCustomerId] = useState('');
  const [confirmedVersion, setConfirmedVersion] = useState('');
  const queryClient = useQueryClient();
  const key = ['zoho-quote', kind, id];
  const { data, error, isFetching } = useQuery<ZohoQuotePreview>({
    queryKey: [...key, search, customerId],
    queryFn: () => api.zohoQuotePreview(kind, id, search, customerId), enabled: open, retry: false,
  });
  const send = useMutation({
    retry: false,
    mutationFn: () => {
      if (!data?.preview) throw new Error('Review the quotation first.');
      return api.zohoSendQuote(kind, id, data.preview.payload.customer_id, data.preview.version);
    },
    onSuccess: result => toast.success(result.message),
    onError: (failure: Error) => toast.error(failure.message),
    onSettled: () => {
      setConfirmedVersion('');
      void queryClient.invalidateQueries({ queryKey: key });
      void queryClient.invalidateQueries({ queryKey: ['zoho-sync-status'] });
    },
  });
  const remove = useMutation({
    retry: false,
    mutationFn: () => api.zohoDeleteQuoteInZoho(kind, id),
    onSuccess: result => toast.success(result.message),
    onError: (failure: Error) => toast.error(failure.message),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: key });
      void queryClient.invalidateQueries({ queryKey: ['zoho-sync-status'] });
    },
  });
  const convert = useMutation({
    retry: false,
    mutationFn: () => api.zohoConvertQuoteToInvoice(kind, id),
    onSuccess: result => toast.success(result.message),
    onError: (failure: Error) => toast.error(failure.message),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: key });
      void queryClient.invalidateQueries({ queryKey: ['zoho-sync-status'] });
      void queryClient.invalidateQueries({ queryKey: ['financial'] });
    },
  });
  const blocked = data && !['new', 'rejected'].includes(data.state);
  const preview = data?.preview;
  return <>
    <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => setOpen(true)}>Send to Zoho</Button>
    <Dialog open={open} onOpenChange={value => { if (!send.isPending) setOpen(value); }}>
      <DialogContent className="max-w-xl max-h-[85vh] overflow-y-auto">
        <DialogHeader><DialogTitle>Send a draft quote to Zoho Books</DialogTitle></DialogHeader>
        <p className="text-sm text-muted-foreground">The accountant reviews this draft and creates the invoice in Zoho Books. The invoice returns with the daily sync. This action uses one quote-write call.</p>
        {data?.state === 'confirmed' ? <><p className="text-sm">Already sent: {data.estimate_id}. Make further changes in Zoho Books.</p>
          {data.conversion_state === 'converted' ? <p className="text-sm text-emerald-600">Converted to Zoho invoice: {data.invoice_id}.</p>
            : <Button size="sm" disabled={convert.isPending || data.conversion_state === 'converting' || data.conversion_state === 'uncertain'} onClick={() => {
              if (window.confirm('Convert this existing Zoho draft quote to one invoice? This will not create a second quote.')) convert.mutate();
            }}>{convert.isPending || data.conversion_state === 'converting' ? 'Converting…' : data.conversion_state === 'uncertain' ? 'Awaiting reconciliation' : 'Convert existing quote to invoice'}</Button>}
          <Button variant="destructive" size="sm" disabled={remove.isPending} onClick={() => {
            if (window.confirm('Delete this draft quotation from Zoho Books? This uses one write call and cannot be undone.')) remove.mutate();
          }}>{remove.isPending ? 'Deleting…' : 'Delete draft in Zoho (manual)'}</Button></>
          : data?.state === 'deleted' ? <p className="text-sm">Deleted in Zoho. The portal quotation is retained, and sending another copy is blocked.</p>
          : blocked ? <p className="text-sm">A previous send is awaiting reconciliation. Sending another copy is blocked; sync will check for the existing quote.</p>
          : <>
            <label className="text-sm space-y-1">Search saved Zoho customers
              <Input value={search} onChange={event => setSearch(event.target.value)} placeholder="Customer name or Zoho ID" />
            </label>
            <label className="text-sm space-y-1">Zoho customer
              <select className="w-full rounded-md border bg-background p-2" value={customerId || data?.customer_id || ''}
                onChange={event => { setCustomerId(event.target.value); setConfirmedVersion(''); }}>
                <option value="">Select the matching customer</option>
                {data?.customer_id && !data.contacts.some(contact => contact.id === data.customer_id)
                  && <option value={data.customer_id}>{preview?.customer_name ?? data.customer_id}</option>}
                {data?.contacts.map(contact => <option key={contact.id} value={contact.id}>{contact.name} ({contact.id})</option>)}
              </select>
            </label>
            <p className="text-xs text-muted-foreground">Showing up to 50 saved matches. Newly created customers become available after contact sync.</p>
            {preview && <div className="space-y-2 text-sm">
              <p>Portal client: {preview.client_name}<br />Zoho customer: {preview.customer_name}<br />Reference: {preview.payload.reference_number}</p>
              {preview.payload.line_items.map((line, index) => <p key={index}>{line.name}: {preview.currency} {line.rate.toFixed(2)}</p>)}
              <p>Portal expected tax: {preview.currency} {preview.portal_tax.toFixed(2)}<br />Portal expected total: {preview.currency} {preview.portal_total.toFixed(2)}</p>
              <p>Amounts are sent before tax. The accountant must apply and verify Zoho taxes before issuing the invoice; the draft total may differ from the portal total.</p>
              <label className="flex gap-2 items-start"><input type="checkbox" checked={confirmedVersion === preview.version}
                onChange={event => setConfirmedVersion(event.target.checked ? preview.version : '')} />
                I checked the customer and amounts and confirm this draft is for accountant review.</label>
            </div>}
            {(error || data?.error) && <p role="alert" className="text-sm text-destructive">{error?.message ?? data?.error}</p>}
            <Button disabled={send.isPending || isFetching || !preview || confirmedVersion !== preview.version || !!data?.error}
              onClick={() => send.mutate()}>{send.isPending ? 'Sending…' : 'Create draft in Zoho'}</Button>
          </>}
      </DialogContent>
    </Dialog>
  </>;
}
