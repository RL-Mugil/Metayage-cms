import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useState } from 'react';

import { getFinancialStats, getInvoices } from '../../../src/lib/api';
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

function fmt(amount: number, currency: string): string {
  const sym = currency === 'INR' ? '₹' : currency === 'USD' ? '$' : currency === 'EUR' ? '€' : `${currency} `;
  return `${sym}${Number(amount).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`;
}

export default function InvoicesScreen() {
  const { session } = useAuth();
  const router = useRouter();
  const token = session!.token;
  const [activeFilter, setActiveFilter] = useState('');

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

  const stats = statsQuery.data;

  const renderInvoice = ({ item }: { item: Invoice }) => {
    const sc = STATUS_COLORS[item.status as InvoiceStatus] ?? '#64748b';
    const overdue = item.status === 'Overdue';
    return (
      <Pressable style={[styles.card, overdue && styles.cardOverdue]} onPress={() => router.push(`/invoices/${item.id}`)}>
        <View style={styles.cardHeader}>
          <Text style={styles.code}>{item.invoice_code}</Text>
          <View style={[styles.badge, { backgroundColor: sc + '33', borderColor: sc }]}>
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
  safe:           { backgroundColor: '#0d1321', flex: 1 },
  statsBar:       { backgroundColor: '#131c31', borderBottomColor: '#21304f', borderBottomWidth: 1, flexDirection: 'row', paddingHorizontal: 8, paddingVertical: 12 },
  statCell:       { alignItems: 'center', flex: 1, gap: 2 },
  statValue:      { fontSize: 17, fontWeight: '800' },
  statLabel:      { color: '#7c8aa5', fontSize: 11, fontWeight: '600', textTransform: 'uppercase' },
  filterBar:      { flexGrow: 0 },
  filterContent:  { flexDirection: 'row', gap: 8, paddingHorizontal: 16, paddingVertical: 10 },
  chip:           { borderColor: '#2a3c61', borderRadius: 20, borderWidth: 1, paddingHorizontal: 14, paddingVertical: 6 },
  chipActive:     { backgroundColor: '#f0b23d22', borderColor: '#f0b23d' },
  chipText:       { color: '#94a3b8', fontSize: 13, fontWeight: '600' },
  chipTextActive: { color: '#f0b23d' },
  list:           { gap: 12, padding: 16 },
  card:           { backgroundColor: '#131c31', borderColor: '#21304f', borderRadius: 16, borderWidth: 1, gap: 8, padding: 16 },
  cardOverdue:    { borderColor: '#7f1d1d' },
  cardHeader:     { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  code:           { color: '#9fb0d3', fontFamily: 'monospace', fontSize: 13, fontWeight: '700' },
  badge:          { borderRadius: 8, borderWidth: 1, paddingHorizontal: 8, paddingVertical: 2 },
  badgeText:      { fontSize: 12, fontWeight: '700' },
  clientName:     { color: '#f8fafc', fontSize: 15, fontWeight: '700' },
  projectName:    { color: '#9fb0d3', fontSize: 13 },
  amountRow:      { flexDirection: 'row', justifyContent: 'space-between', marginTop: 4 },
  amountLabel:    { color: '#7c8aa5', fontSize: 11, fontWeight: '600', textTransform: 'uppercase' },
  amount:         { color: '#f8fafc', fontSize: 17, fontWeight: '800' },
  dates:          { color: '#7c8aa5', fontSize: 12 },
  empty:          { color: '#94a3b8', marginTop: 40, textAlign: 'center' },
});
