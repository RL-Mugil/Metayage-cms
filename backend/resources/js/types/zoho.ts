export interface ZohoQuotePreview {
  state: 'new' | 'sending' | 'uncertain' | 'rejected' | 'confirmed' | 'deleted';
  estimate_id: string | null;
  customer_id: string;
  contacts: { id: string; name: string }[];
  error: string | null;
  preview: {
    version: string; customer_name: string; client_name: string; currency: string; portal_tax: number; portal_total: number;
    payload: { customer_id: string; reference_number: string; line_items: { name: string; quantity: number; rate: number }[] };
  } | null;
}
