import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams, useNavigation } from 'expo-router';
import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import {
  ActivityIndicator,
  Alert,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { z } from 'zod';

import { getInvoice, recordPayment, updateInvoiceStatus } from '../../../src/lib/api';
import { useAuth } from '../../../src/providers/auth-provider';
import type { InvoiceStatus, UserRole } from '../../../src/types/api';

const PAYMENT_ROLES: UserRole[] = ['super_admin', 'partner', 'finance'];
const MANAGE_ROLES: UserRole[] = ['super_admin', 'partner', 'finance'];
const PAYMENT_METHODS = ['Bank Transfer', 'Cheque', 'UPI', 'Cash', 'NEFT', 'RTGS'];

const STATUS_COLORS: Partial<Record<InvoiceStatus, string>> = {
  Draft:            '#64748b',
  Sent:             '#2864ff',
  Overdue:          '#dc2626',
  'Partially Paid': '#d97706',
  Paid:             '#16a34a',
  Cancelled:        '#64748b',
};

const SENDABLE_STATUSES = ['Draft', 'Sent', 'Viewed', 'Overdue'];

const paySchema = z.object({
  amount: z.string().refine((v) => Number(v) > 0, 'Enter a valid amount.'),
  payment_method: z.string().min(1, 'Select a payment method.'),
  transaction_reference: z.string().optional(),
});

type PayFormValues = z.infer<typeof paySchema>;

function fmt(amount: number, currency: string): string {
  const sym = currency === 'INR' ? '₹' : currency === 'USD' ? '$' : currency === 'EUR' ? '€' : `${currency} `;
  return `${sym}${Number(amount).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
}

export default function InvoiceDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { session } = useAuth();
  const navigation = useNavigation();
  const queryClient = useQueryClient();
  const token = session!.token;
  const role = session!.user.role;
  const [showPayment, setShowPayment] = useState(false);

  const form = useForm<PayFormValues>({
    resolver: zodResolver(paySchema),
    defaultValues: { amount: '', payment_method: 'Bank Transfer', transaction_reference: '' },
  });

  const query = useQuery({
    queryKey: ['invoice', id],
    queryFn: () => getInvoice(token, Number(id)),
  });

  const invoice = query.data;

  useEffect(() => {
    if (invoice) navigation.setOptions({ title: invoice.invoice_code });
  }, [invoice, navigation]);

  const payMutation = useMutation({
    mutationFn: (values: PayFormValues) =>
      recordPayment(token, {
        invoice_id: Number(id),
        amount: Number(values.amount),
        payment_method: values.payment_method,
        transaction_reference: values.transaction_reference || undefined,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['invoice', id] });
      await queryClient.invalidateQueries({ queryKey: ['invoices'] });
      await queryClient.invalidateQueries({ queryKey: ['financial-stats'] });
      setShowPayment(false);
      form.reset();
      Alert.alert('Payment recorded', 'The payment has been logged and a receipt generated.');
    },
    onError: (error) => Alert.alert('Failed', error instanceof Error ? error.message : 'Could not record payment.'),
  });

  const statusMutation = useMutation({
    mutationFn: (status: string) => updateInvoiceStatus(token, Number(id), status),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['invoice', id] });
      await queryClient.invalidateQueries({ queryKey: ['invoices'] });
      await queryClient.invalidateQueries({ queryKey: ['financial-stats'] });
    },
    onError: (error) => Alert.alert('Failed', error instanceof Error ? error.message : 'Could not update status.'),
  });

  const confirmCancel = () => {
    Alert.alert(
      'Cancel Invoice',
      'This will cancel the invoice and reverse the outstanding balance in the client ledger. Payments already applied are unaffected. Continue?',
      [
        { text: 'Keep Invoice', style: 'cancel' },
        {
          text: 'Cancel Invoice',
          style: 'destructive',
          onPress: () => void statusMutation.mutateAsync('Cancelled'),
        },
      ],
    );
  };

  const markSent = () => void statusMutation.mutateAsync('Sent');

  const submitPay = form.handleSubmit((values) => void payMutation.mutateAsync(values));

  if (query.isLoading) {
    return <View style={styles.center}><ActivityIndicator color="#f0b23d" size="large" /></View>;
  }

  if (!invoice) {
    return <View style={styles.center}><Text style={styles.empty}>Invoice not found.</Text></View>;
  }

  const sc = STATUS_COLORS[invoice.status as InvoiceStatus] ?? '#64748b';
  const canPay = PAYMENT_ROLES.includes(role) && invoice.balance_due > 0 && invoice.status !== 'Cancelled';
  const canManage = MANAGE_ROLES.includes(role) && invoice.status !== 'Cancelled';
  const canSend = canManage && SENDABLE_STATUSES.includes(invoice.status) && invoice.status !== 'Sent';

  return (
    <SafeAreaView style={styles.safe} edges={['bottom']}>
      <ScrollView contentContainerStyle={styles.scroll}>
        {/* Header */}
        <View style={styles.header}>
          <View style={styles.headerRow}>
            <Text style={styles.code}>{invoice.invoice_code}</Text>
            <View style={[styles.badge, { backgroundColor: sc + '33', borderColor: sc }]}>
              <Text style={[styles.badgeText, { color: sc }]}>{invoice.status}</Text>
            </View>
          </View>
          <Text style={styles.clientName}>{invoice.client.company_name}</Text>
          {invoice.project ? <Text style={styles.projectName}>{invoice.project.project_name}</Text> : null}
          <View style={styles.datesRow}>
            <Text style={styles.dateItem}>Issued: {invoice.issue_date}</Text>
            <Text style={[styles.dateItem, invoice.status === 'Overdue' && styles.dateRed]}>Due: {invoice.due_date}</Text>
          </View>
        </View>

        {/* Line items */}
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Line items</Text>
          {invoice.items.map((item) => (
            <View key={item.id} style={styles.lineItem}>
              <View style={{ flex: 1 }}>
                <Text style={styles.lineDesc}>{item.description}</Text>
                <Text style={styles.lineMeta}>{item.quantity} × {fmt(item.unit_rate, invoice.currency)}</Text>
              </View>
              <Text style={styles.lineAmount}>{fmt(item.amount, invoice.currency)}</Text>
            </View>
          ))}
        </View>

        {/* Totals */}
        <View style={styles.section}>
          <TotalRow label="Subtotal" value={fmt(invoice.subtotal, invoice.currency)} />
          <TotalRow label="GST" value={fmt(invoice.tax_amount, invoice.currency)} />
          <View style={styles.divider} />
          <TotalRow label="Total" value={fmt(invoice.total_amount, invoice.currency)} bold />
          {invoice.balance_due < invoice.total_amount ? (
            <TotalRow label="Paid" value={fmt(invoice.total_amount - invoice.balance_due, invoice.currency)} color="#86efac" />
          ) : null}
          {invoice.balance_due > 0 ? (
            <TotalRow label="Balance due" value={fmt(invoice.balance_due, invoice.currency)} color={invoice.status === 'Overdue' ? '#f87171' : '#fbbf24'} bold />
          ) : null}
        </View>

        {/* Payment history */}
        {invoice.payments?.length > 0 ? (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>Payment history</Text>
            {invoice.payments.map((p) => (
              <View key={p.id} style={styles.paymentRow}>
                <View>
                  <Text style={styles.receiptCode}>{p.receipt_code}</Text>
                  <Text style={styles.paymentMeta}>{p.payment_date}  ·  {p.payment_method}</Text>
                  {p.transaction_reference ? <Text style={styles.paymentMeta}>Ref: {p.transaction_reference}</Text> : null}
                </View>
                <Text style={styles.paymentAmount}>{fmt(p.amount, invoice.currency)}</Text>
              </View>
            ))}
          </View>
        ) : null}

        {/* Actions */}
        <View style={styles.actionsRow}>
          {canPay ? (
            <Pressable style={styles.payBtn} onPress={() => {
              form.reset({ amount: String(invoice.balance_due), payment_method: 'Bank Transfer', transaction_reference: '' });
              setShowPayment(true);
            }}>
              <Text style={styles.payBtnText}>Record Payment</Text>
            </Pressable>
          ) : null}

          {canSend ? (
            <Pressable style={styles.sentBtn} onPress={markSent} disabled={statusMutation.isPending}>
              <Text style={styles.sentBtnText}>{statusMutation.isPending ? 'Updating…' : 'Mark as Sent'}</Text>
            </Pressable>
          ) : null}

          {canManage ? (
            <Pressable style={styles.cancelBtn} onPress={confirmCancel} disabled={statusMutation.isPending}>
              <Text style={styles.cancelBtnText}>Cancel Invoice</Text>
            </Pressable>
          ) : null}
        </View>
      </ScrollView>

      {/* Record payment modal */}
      <Modal animationType="slide" transparent visible={showPayment} onRequestClose={() => setShowPayment(false)}>
        <View style={styles.backdrop}>
          <View style={styles.sheet}>
            <Text style={styles.sheetTitle}>Record Payment</Text>
            <Text style={styles.sheetSub}>Balance due: {fmt(invoice.balance_due, invoice.currency)}</Text>

            <View style={{ gap: 14 }}>
              <Field label="Amount *" error={form.formState.errors.amount?.message}>
                <Controller control={form.control} name="amount" render={({ field }) => (
                  <TextInput style={styles.input} keyboardType="decimal-pad" placeholder="0.00" placeholderTextColor="#7c8aa5" value={field.value} onChangeText={field.onChange} />
                )} />
              </Field>

              <Field label="Payment method *" error={form.formState.errors.payment_method?.message}>
                <Controller control={form.control} name="payment_method" render={({ field }) => (
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                    {PAYMENT_METHODS.map((m) => (
                      <Pressable key={m} onPress={() => field.onChange(m)} style={[styles.toggleOption, field.value === m && styles.toggleActive]}>
                        <Text style={[styles.toggleText, field.value === m && styles.toggleTextActive]}>{m}</Text>
                      </Pressable>
                    ))}
                  </ScrollView>
                )} />
              </Field>

              <Field label="Transaction reference">
                <Controller control={form.control} name="transaction_reference" render={({ field }) => (
                  <TextInput style={styles.input} placeholder="UTR / cheque number" placeholderTextColor="#7c8aa5" value={field.value} onChangeText={field.onChange} />
                )} />
              </Field>
            </View>

            <View style={styles.btnRow}>
              <Pressable style={styles.sheetCancelBtn} onPress={() => setShowPayment(false)}>
                <Text style={styles.btnText}>Cancel</Text>
              </Pressable>
              <Pressable style={styles.submitBtn} onPress={() => void submitPay()}>
                <Text style={styles.btnText}>{payMutation.isPending ? 'Saving…' : 'Save payment'}</Text>
              </Pressable>
            </View>
          </View>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

function TotalRow({ label, value, bold, color }: { label: string; value: string; bold?: boolean; color?: string }) {
  return (
    <View style={styles.totalRow}>
      <Text style={[styles.totalLabel, bold && styles.bold]}>{label}</Text>
      <Text style={[styles.totalValue, bold && styles.bold, color ? { color } : null]}>{value}</Text>
    </View>
  );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return (
    <View style={{ gap: 6 }}>
      <Text style={styles.fieldLabel}>{label}</Text>
      {children}
      {error ? <Text style={styles.fieldError}>{error}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  safe:            { backgroundColor: '#040d1a', flex: 1 },
  center:          { alignItems: 'center', backgroundColor: '#040d1a', flex: 1, justifyContent: 'center' },
  scroll:          { gap: 0, paddingBottom: 32 },
  header:          {
    backgroundColor: '#0b1829',
    borderBottomColor: 'rgba(100,160,255,0.1)',
    borderBottomWidth: 1,
    gap: 8,
    padding: 20,
  },
  headerRow:       { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  code:            { color: '#60a5fa', fontFamily: 'monospace', fontSize: 13, fontWeight: '700' },
  badge:           { borderRadius: 8, paddingHorizontal: 10, paddingVertical: 3 },
  badgeText:       { fontSize: 12, fontWeight: '700' },
  clientName:      { color: '#f1f5f9', fontSize: 20, fontWeight: '800', letterSpacing: -0.5 },
  projectName:     { color: '#475569', fontSize: 14, fontWeight: '500' },
  datesRow:        { flexDirection: 'row', gap: 16 },
  dateItem:        { color: '#475569', fontSize: 13 },
  dateRed:         { color: '#f87171', fontWeight: '600' },
  section:         { borderTopColor: 'rgba(100,160,255,0.07)', borderTopWidth: 1, gap: 10, padding: 16 },
  sectionTitle:    { color: '#334155', fontSize: 11, fontWeight: '800', letterSpacing: 1.2, marginBottom: 4, textTransform: 'uppercase' },
  lineItem:        { alignItems: 'flex-start', flexDirection: 'row', gap: 12, paddingVertical: 8 },
  lineDesc:        { color: '#f1f5f9', fontSize: 14, fontWeight: '600' },
  lineMeta:        { color: '#475569', fontSize: 12 },
  lineAmount:      { color: '#f1f5f9', fontSize: 14, fontWeight: '700' },
  divider:         { backgroundColor: 'rgba(100,160,255,0.07)', height: 1, marginVertical: 4 },
  totalRow:        { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 7 },
  totalLabel:      { color: '#475569', fontSize: 14 },
  totalValue:      { color: '#f1f5f9', fontSize: 14 },
  bold:            { fontWeight: '800', color: '#f0b23d' },
  paymentRow:      { alignItems: 'flex-start', borderBottomColor: 'rgba(100,160,255,0.07)', borderBottomWidth: 1, flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 10 },
  receiptCode:     { color: '#60a5fa', fontFamily: 'monospace', fontSize: 13, fontWeight: '700' },
  paymentMeta:     { color: '#475569', fontSize: 12 },
  paymentAmount:   { color: '#86efac', fontSize: 15, fontWeight: '700' },
  actionsRow:      { gap: 10, padding: 16 },
  payBtn:          {
    alignItems: 'center',
    backgroundColor: 'rgba(134,239,172,0.08)',
    borderColor: 'rgba(134,239,172,0.3)',
    borderRadius: 16,
    borderWidth: 1,
    paddingVertical: 15,
  },
  payBtnText:      { color: '#86efac', fontSize: 15, fontWeight: '700' },
  sentBtn:         {
    alignItems: 'center',
    backgroundColor: 'rgba(96,165,250,0.08)',
    borderColor: 'rgba(96,165,250,0.3)',
    borderRadius: 16,
    borderWidth: 1,
    paddingVertical: 14,
  },
  sentBtnText:     { color: '#93c5fd', fontSize: 14, fontWeight: '700' },
  cancelBtn:       {
    alignItems: 'center',
    backgroundColor: 'rgba(239,68,68,0.08)',
    borderColor: 'rgba(239,68,68,0.25)',
    borderRadius: 16,
    borderWidth: 1,
    paddingVertical: 14,
  },
  cancelBtnText:   { color: '#f87171', fontSize: 14, fontWeight: '700' },
  backdrop:        { backgroundColor: 'rgba(4,13,26,0.92)', flex: 1, justifyContent: 'flex-end' },
  sheet:           { backgroundColor: '#0b1829', borderTopLeftRadius: 28, borderTopRightRadius: 28, gap: 14, padding: 24 },
  sheetTitle:      { color: '#f1f5f9', fontSize: 20, fontWeight: '800' },
  sheetSub:        { color: '#475569', fontSize: 14 },
  input:           {
    backgroundColor: 'rgba(255,255,255,0.04)',
    borderColor: 'rgba(100,160,255,0.2)',
    borderRadius: 12,
    borderWidth: 1,
    color: '#f1f5f9',
    fontSize: 15,
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  toggleOption:    { borderColor: 'rgba(100,160,255,0.2)', borderRadius: 12, borderWidth: 1, paddingHorizontal: 14, paddingVertical: 9 },
  toggleActive:    { backgroundColor: '#f0b23d', borderColor: '#f0b23d' },
  toggleText:      { color: '#475569', fontSize: 13, fontWeight: '600' },
  toggleTextActive:{ color: '#040d1a', fontWeight: '800' },
  fieldLabel:      { color: '#94a3b8', fontSize: 14, fontWeight: '600' },
  fieldError:      { color: '#fca5a5', fontSize: 13 },
  btnRow:          { flexDirection: 'row', gap: 10, marginTop: 4 },
  sheetCancelBtn:  { alignItems: 'center', backgroundColor: 'rgba(255,255,255,0.06)', borderRadius: 14, flex: 1, justifyContent: 'center', paddingVertical: 14 },
  submitBtn:       { alignItems: 'center', backgroundColor: '#16a34a', borderRadius: 14, flex: 2, justifyContent: 'center', paddingVertical: 14 },
  btnText:         { color: '#fff', fontSize: 14, fontWeight: '700' },
  empty:           { color: '#334155', fontSize: 14, marginTop: 40, textAlign: 'center' },
});
