import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Controller, useForm, useFieldArray } from 'react-hook-form';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Modal,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { z } from 'zod';

import { createInvoice, getClients, getFinancialStats, getInvoices, getProjects } from '../../../src/lib/api';
import { useAuth } from '../../../src/providers/auth-provider';
import type { Invoice, InvoiceStatus } from '../../../src/types/api';

const STATUS_FILTERS = [
  { label: 'All',           value: '' },
  { label: 'Draft',         value: 'Draft' },
  { label: 'Sent',          value: 'Sent' },
  { label: 'Overdue',       value: 'Overdue' },
  { label: 'Partially Paid',value: 'Partially Paid' },
  { label: 'Paid',          value: 'Paid' },
];

const STATUS_COLORS: Partial<Record<InvoiceStatus, string>> = {
  Draft:            '#64748b',
  Sent:             '#2864ff',
  Viewed:           '#7c3aed',
  Overdue:          '#dc2626',
  'Partially Paid': '#d97706',
  Paid:             '#16a34a',
  Cancelled:        '#64748b',
};

const CREATE_ROLES = ['super_admin', 'partner', 'finance'];

const lineItemSchema = z.object({
  description: z.string().min(1, 'Required'),
  amount: z.string().refine((v) => Number(v) > 0, 'Enter a valid amount'),
});

const createSchema = z.object({
  client_id: z.string().min(1, 'Select a client'),
  project_id: z.string().optional(),
  due_date: z.string().min(1, 'Enter due date').regex(/^\d{4}-\d{2}-\d{2}$/, 'Use YYYY-MM-DD'),
  currency: z.string().default('INR'),
  payment_terms: z.string().optional(),
  items: z.array(lineItemSchema).min(1, 'Add at least one item'),
});

type CreateFormValues = z.infer<typeof createSchema>;

function fmt(amount: number, currency: string): string {
  const sym = currency === 'INR' ? '₹' : currency === 'USD' ? '$' : currency === 'EUR' ? '€' : `${currency} `;
  return `${sym}${Number(amount).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`;
}

export default function InvoicesScreen() {
  const { session } = useAuth();
  const router = useRouter();
  const queryClient = useQueryClient();
  const token = session!.token;
  const role = session!.user.role;
  const [activeFilter, setActiveFilter] = useState('');
  const [showCreate, setShowCreate] = useState(false);
  const [clientSearch, setClientSearch] = useState('');

  const canCreate = CREATE_ROLES.includes(role);

  const statsQuery = useQuery({
    queryKey: ['financial-stats'],
    queryFn: () => getFinancialStats(token),
    staleTime: 60_000,
  });

  const invoicesQuery = useQuery({
    queryKey: ['invoices', activeFilter],
    queryFn: () => getInvoices(token, { status: activeFilter || undefined }),
    staleTime: 30_000,
  });

  const clientsQuery = useQuery({
    queryKey: ['clients-picker', clientSearch],
    queryFn: () => getClients(token, { search: clientSearch || undefined, per_page: 20 }),
    enabled: showCreate,
    staleTime: 30_000,
  });

  const projectsQuery = useQuery({
    queryKey: ['projects-picker'],
    queryFn: () => getProjects(token, { per_page: 50 }),
    enabled: showCreate,
    staleTime: 60_000,
  });

  const form = useForm<CreateFormValues>({
    resolver: zodResolver(createSchema),
    defaultValues: {
      client_id: '',
      project_id: '',
      due_date: '',
      currency: 'INR',
      payment_terms: 'Net 30',
      items: [{ description: '', amount: '' }],
    },
  });

  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'items' });

  const createMutation = useMutation({
    mutationFn: (values: CreateFormValues) =>
      createInvoice(token, {
        client_id: Number(values.client_id),
        project_id: values.project_id ? Number(values.project_id) : null,
        due_date: values.due_date,
        currency: values.currency,
        payment_terms: values.payment_terms,
        items: values.items.map((i) => ({ description: i.description, amount: Number(i.amount) })),
      }),
    onSuccess: async (inv) => {
      await queryClient.invalidateQueries({ queryKey: ['invoices'] });
      await queryClient.invalidateQueries({ queryKey: ['financial-stats'] });
      setShowCreate(false);
      form.reset();
      router.push(`/invoices/${inv.id}`);
    },
    onError: (e) => Alert.alert('Failed', e instanceof Error ? e.message : 'Could not create invoice.'),
  });

  const stats = statsQuery.data;
  const selectedClientId = form.watch('client_id');
  const filteredProjects = (projectsQuery.data?.data ?? []).filter(
    (p) => !selectedClientId || String(p.client.id) === selectedClientId,
  );

  const renderInvoice = ({ item }: { item: Invoice }) => {
    const sc = STATUS_COLORS[item.status as InvoiceStatus] ?? '#64748b';
    const overdue = item.status === 'Overdue';
    return (
      <Pressable style={({ pressed }) => [styles.card, overdue && styles.cardOverdue, pressed && { transform: [{ scale: 0.98 }], opacity: 0.85 }]} onPress={() => router.push(`/invoices/${item.id}`)}>
        <View style={styles.cardHeader}>
          <Text style={styles.code}>{item.invoice_code}</Text>
          <View style={[styles.badge, { backgroundColor: sc + '22' }]}>
            <Text style={[styles.badgeText, { color: sc }]}>{item.status}</Text>
          </View>
        </View>
        <Text style={styles.clientName}>{item.client.company_name}</Text>
        {item.project ? <Text style={styles.projectName}>{item.project.project_name}</Text> : null}
        <View style={styles.amountRow}>
          <View>
            <Text style={styles.amountLabel}>Total</Text>
            <Text style={styles.amount}>{fmt(item.total_amount, item.currency)}</Text>
          </View>
          {item.balance_due > 0 ? (
            <View style={{ alignItems: 'flex-end' }}>
              <Text style={styles.amountLabel}>Balance due</Text>
              <Text style={[styles.amount, { color: overdue ? '#f87171' : '#fbbf24' }]}>{fmt(item.balance_due, item.currency)}</Text>
            </View>
          ) : null}
        </View>
        <Text style={styles.dates}>
          Issued {item.issue_date}  ·  Due {item.due_date}
        </Text>
      </Pressable>
    );
  };

  return (
    <SafeAreaView style={styles.safe}>
      {/* Stats bar */}
      {stats ? (
        <View style={styles.statsBar}>
          <StatCell label="Outstanding" value={`₹${Math.round(stats.total_outstanding / 1000)}k`} color="#fbbf24" />
          <StatCell label="Overdue"     value={String(stats.overdue_count)}                         color="#f87171" />
          <StatCell label="Paid"        value={String(stats.paid_count)}                            color="#86efac" />
          <StatCell label="Draft"       value={String(stats.draft_count)}                           color="#94a3b8" />
        </View>
      ) : null}

      {/* Filter chips */}
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.filterBar} contentContainerStyle={styles.filterContent}>
        {STATUS_FILTERS.map((f) => (
          <Pressable key={f.value} onPress={() => setActiveFilter(f.value)} style={[styles.chip, activeFilter === f.value && styles.chipActive]}>
            <Text style={[styles.chipText, activeFilter === f.value && styles.chipTextActive]}>{f.label}</Text>
          </Pressable>
        ))}
      </ScrollView>

      <FlatList
        data={invoicesQuery.data?.data ?? []}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={styles.list}
        refreshControl={<RefreshControl refreshing={invoicesQuery.isRefetching} onRefresh={() => void invoicesQuery.refetch()} tintColor="#f0b23d" />}
        renderItem={renderInvoice}
        ListEmptyComponent={
          invoicesQuery.isLoading
            ? <ActivityIndicator color="#f0b23d" style={{ marginTop: 40 }} />
            : <Text style={styles.empty}>No invoices found.</Text>
        }
      />

      {/* Create FAB */}
      {canCreate ? (
        <Pressable style={styles.fab} onPress={() => setShowCreate(true)}>
          <Text style={styles.fabIcon}>+</Text>
        </Pressable>
      ) : null}

      {/* Create Invoice Modal */}
      <Modal animationType="slide" transparent visible={showCreate} onRequestClose={() => setShowCreate(false)}>
        <View style={styles.backdrop}>
          <View style={styles.sheet}>
            <Text style={styles.sheetTitle}>New Invoice</Text>

            <ScrollView style={{ maxHeight: 500 }} showsVerticalScrollIndicator={false}>
              <View style={{ gap: 14 }}>
                {/* Client picker */}
                <View style={{ gap: 6 }}>
                  <Text style={styles.fieldLabel}>Client *</Text>
                  <TextInput
                    style={styles.input}
                    placeholder="Search clients…"
                    placeholderTextColor="#7c8aa5"
                    value={clientSearch}
                    onChangeText={setClientSearch}
                  />
                  <Controller
                    control={form.control}
                    name="client_id"
                    render={({ field, fieldState }) => (
                      <>
                        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                          {(clientsQuery.data?.data ?? []).map((c) => (
                            <Pressable
                              key={c.id}
                              onPress={() => field.onChange(String(c.id))}
                              style={[styles.toggleOption, field.value === String(c.id) && styles.toggleActive]}
                            >
                              <Text style={[styles.toggleText, field.value === String(c.id) && styles.toggleTextActive]}>
                                {c.company_name}
                              </Text>
                            </Pressable>
                          ))}
                        </ScrollView>
                        {fieldState.error ? <Text style={styles.fieldError}>{fieldState.error.message}</Text> : null}
                      </>
                    )}
                  />
                </View>

                {/* Project (optional) */}
                {filteredProjects.length > 0 ? (
                  <View style={{ gap: 6 }}>
                    <Text style={styles.fieldLabel}>Project (optional)</Text>
                    <Controller
                      control={form.control}
                      name="project_id"
                      render={({ field }) => (
                        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                          {filteredProjects.map((p) => (
                            <Pressable
                              key={p.id}
                              onPress={() => field.onChange(field.value === String(p.id) ? '' : String(p.id))}
                              style={[styles.toggleOption, field.value === String(p.id) && styles.toggleActive]}
                            >
                              <Text style={[styles.toggleText, field.value === String(p.id) && styles.toggleTextActive]}>
                                {p.docket_number}
                              </Text>
                            </Pressable>
                          ))}
                        </ScrollView>
                      )}
                    />
                  </View>
                ) : null}

                {/* Due date */}
                <View style={{ gap: 6 }}>
                  <Text style={styles.fieldLabel}>Due date * (YYYY-MM-DD)</Text>
                  <Controller
                    control={form.control}
                    name="due_date"
                    render={({ field, fieldState }) => (
                      <>
                        <TextInput
                          style={styles.input}
                          placeholder="2026-08-15"
                          placeholderTextColor="#7c8aa5"
                          value={field.value}
                          onChangeText={field.onChange}
                          keyboardType="numbers-and-punctuation"
                        />
                        {fieldState.error ? <Text style={styles.fieldError}>{fieldState.error.message}</Text> : null}
                      </>
                    )}
                  />
                </View>

                {/* Currency */}
                <View style={{ gap: 6 }}>
                  <Text style={styles.fieldLabel}>Currency</Text>
                  <Controller
                    control={form.control}
                    name="currency"
                    render={({ field }) => (
                      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                        {['INR', 'USD', 'EUR', 'AED', 'GBP'].map((c) => (
                          <Pressable
                            key={c}
                            onPress={() => field.onChange(c)}
                            style={[styles.toggleOption, field.value === c && styles.toggleActive]}
                          >
                            <Text style={[styles.toggleText, field.value === c && styles.toggleTextActive]}>{c}</Text>
                          </Pressable>
                        ))}
                      </ScrollView>
                    )}
                  />
                </View>

                {/* Line items */}
                <View style={{ gap: 8 }}>
                  <Text style={styles.fieldLabel}>Line items *</Text>
                  {fields.map((field, index) => (
                    <View key={field.id} style={styles.lineItemRow}>
                      <Controller
                        control={form.control}
                        name={`items.${index}.description`}
                        render={({ field: f }) => (
                          <TextInput
                            style={[styles.input, { flex: 1 }]}
                            placeholder="Description"
                            placeholderTextColor="#7c8aa5"
                            value={f.value}
                            onChangeText={f.onChange}
                          />
                        )}
                      />
                      <Controller
                        control={form.control}
                        name={`items.${index}.amount`}
                        render={({ field: f }) => (
                          <TextInput
                            style={[styles.input, { width: 90 }]}
                            placeholder="Amount"
                            placeholderTextColor="#7c8aa5"
                            keyboardType="decimal-pad"
                            value={f.value}
                            onChangeText={f.onChange}
                          />
                        )}
                      />
                      {fields.length > 1 ? (
                        <Pressable onPress={() => remove(index)} style={styles.removeBtn}>
                          <Text style={styles.removeBtnText}>✕</Text>
                        </Pressable>
                      ) : null}
                    </View>
                  ))}
                  <Pressable
                    style={styles.addLineBtn}
                    onPress={() => append({ description: '', amount: '' })}
                  >
                    <Text style={styles.addLineBtnText}>+ Add item</Text>
                  </Pressable>
                </View>
              </View>
            </ScrollView>

            <View style={styles.btnRow}>
              <Pressable style={styles.sheetCancelBtn} onPress={() => setShowCreate(false)}>
                <Text style={styles.btnText}>Cancel</Text>
              </Pressable>
              <Pressable
                style={styles.submitBtn}
                onPress={() => form.handleSubmit((v) => void createMutation.mutateAsync(v))()}
              >
                <Text style={styles.btnText}>{createMutation.isPending ? 'Creating…' : 'Create Invoice'}</Text>
              </Pressable>
            </View>
          </View>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

function StatCell({ label, value, color }: { label: string; value: string; color: string }) {
  return (
    <View style={styles.statCell}>
      <Text style={[styles.statValue, { color }]}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  safe:            { backgroundColor: '#040d1a', flex: 1 },
  statsBar:        { backgroundColor: '#0b1829', borderBottomColor: 'rgba(100,160,255,0.1)', borderBottomWidth: 1, flexDirection: 'row', paddingHorizontal: 8, paddingVertical: 14 },
  statCell:        { alignItems: 'center', flex: 1, gap: 3 },
  statValue:       { fontSize: 18, fontWeight: '800' },
  statLabel:       { color: '#334155', fontSize: 10, fontWeight: '700', letterSpacing: 0.8, textTransform: 'uppercase' },
  filterBar:       { flexGrow: 0 },
  filterContent:   { flexDirection: 'row', gap: 8, paddingHorizontal: 16, paddingVertical: 10 },
  chip:            { backgroundColor: 'rgba(255,255,255,0.04)', borderColor: 'rgba(255,255,255,0.1)', borderRadius: 100, borderWidth: 1, flexShrink: 0, paddingHorizontal: 18, paddingVertical: 10 },
  chipActive:      { backgroundColor: '#f0b23d', borderColor: '#f0b23d' },
  chipText:        { color: 'rgba(255,255,255,0.4)', fontSize: 14, fontWeight: '600' },
  chipTextActive:  { color: '#040d1a', fontWeight: '800' },
  list:            { gap: 10, padding: 16, paddingBottom: 100 },
  card:            { backgroundColor: '#0b1829', borderColor: 'rgba(100,160,255,0.1)', borderRadius: 20, borderTopColor: 'rgba(255,255,255,0.08)', borderWidth: 1, gap: 8, padding: 16, shadowColor: '#000', shadowOffset: { width: 0, height: 6 }, shadowOpacity: 0.35, shadowRadius: 14, elevation: 6 },
  cardOverdue:     { borderColor: 'rgba(239,68,68,0.35)' },
  cardHeader:      { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  code:            { color: '#334155', fontFamily: 'monospace', fontSize: 12, fontWeight: '700' },
  badge:           { borderRadius: 8, paddingHorizontal: 10, paddingVertical: 4 },
  badgeText:       { fontSize: 11, fontWeight: '800', letterSpacing: 0.3 },
  clientName:      { color: '#f1f5f9', fontSize: 15, fontWeight: '700' },
  projectName:     { color: '#475569', fontSize: 13 },
  amountRow:       { flexDirection: 'row', justifyContent: 'space-between', marginTop: 4 },
  amountLabel:     { color: '#334155', fontSize: 10, fontWeight: '700', letterSpacing: 0.8, textTransform: 'uppercase' },
  amount:          { color: '#f1f5f9', fontSize: 18, fontWeight: '800' },
  dates:           { color: '#334155', fontSize: 12 },
  empty:           { color: '#334155', marginTop: 48, textAlign: 'center' },
  fab:             { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 30, bottom: 28, elevation: 8, height: 60, justifyContent: 'center', position: 'absolute', right: 20, shadowColor: '#f0b23d', shadowOffset: { width: 0, height: 6 }, shadowOpacity: 0.45, shadowRadius: 14, width: 60 },
  fabIcon:         { color: '#040d1a', fontSize: 30, fontWeight: '700', lineHeight: 34 },
  backdrop:        { backgroundColor: 'rgba(2,8,22,0.85)', flex: 1, justifyContent: 'flex-end' },
  sheet:           { backgroundColor: '#0b1829', borderColor: 'rgba(100,160,255,0.12)', borderTopLeftRadius: 28, borderTopRightRadius: 28, borderWidth: 1, gap: 14, padding: 24, paddingBottom: 36 },
  sheetTitle:      { color: '#f1f5f9', fontSize: 22, fontWeight: '800' },
  input:           { backgroundColor: '#0d1e38', borderColor: 'rgba(100,160,255,0.15)', borderRadius: 14, borderWidth: 1, color: '#f1f5f9', fontSize: 15, paddingHorizontal: 16, paddingVertical: 13 },
  fieldLabel:      { color: '#64748b', fontSize: 11, fontWeight: '700', letterSpacing: 0.8, textTransform: 'uppercase' },
  fieldError:      { color: '#f87171', fontSize: 12 },
  toggleOption:    { backgroundColor: '#0d1e38', borderColor: 'rgba(100,160,255,0.15)', borderRadius: 12, borderWidth: 1, flexShrink: 0, paddingHorizontal: 16, paddingVertical: 10 },
  toggleActive:    { backgroundColor: '#f0b23d', borderColor: '#f0b23d' },
  toggleText:      { color: '#475569', fontSize: 13, fontWeight: '600' },
  toggleTextActive:{ color: '#040d1a', fontWeight: '800' },
  lineItemRow:     { flexDirection: 'row', gap: 8 },
  removeBtn:       { alignItems: 'center', backgroundColor: 'rgba(239,68,68,0.15)', borderRadius: 10, justifyContent: 'center', width: 36 },
  removeBtnText:   { color: '#f87171', fontSize: 14, fontWeight: '700' },
  addLineBtn:      { alignItems: 'center', borderColor: 'rgba(100,160,255,0.2)', borderRadius: 10, borderStyle: 'dashed', borderWidth: 1, paddingVertical: 10 },
  addLineBtnText:  { color: '#475569', fontSize: 14, fontWeight: '600' },
  btnRow:          { flexDirection: 'row', gap: 10, marginTop: 4 },
  sheetCancelBtn:  { alignItems: 'center', backgroundColor: '#0d1e38', borderRadius: 14, flex: 1, justifyContent: 'center', paddingVertical: 14 },
  submitBtn:       { alignItems: 'center', backgroundColor: '#22c55e', borderRadius: 14, flex: 2, justifyContent: 'center', paddingVertical: 14 },
  btnText:         { color: '#040d1a', fontSize: 15, fontWeight: '800' },
});
