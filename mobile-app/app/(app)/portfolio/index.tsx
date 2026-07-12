import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { getProjects } from '../../../src/lib/api';
import { useAuth } from '../../../src/providers/auth-provider';
import type { Project } from '../../../src/types/api';

const OFFICE_LABELS: Record<string, string> = {
  IN: 'India (IPO)',
  US: 'USPTO',
  EP: 'EPO',
  WO: 'WIPO / PCT',
  AU: 'Australia',
  CA: 'Canada',
  JP: 'Japan',
  CN: 'China',
  KR: 'Korea',
};

const TYPE_FILTERS = [
  { label: 'All Patents',    value: 'Patent' },
  { label: 'Utility',       value: 'Utility Patent' },
  { label: 'Design',        value: 'Design Patent' },
  { label: 'PCT',           value: 'PCT' },
  { label: 'Trademark',     value: 'Trademark' },
  { label: 'Copyright',     value: 'Copyright' },
];

const STATUS_COLORS: Record<string, string> = {
  'Active':      '#16a34a',
  'In Progress': '#d97706',
  'On Hold':     '#64748b',
  'Closed':      '#475569',
  'Completed':   '#16a34a',
  'Draft':       '#64748b',
  'Open':        '#2864ff',
};

function deadlineLabel(deadline?: string | null): { text: string; color: string } {
  if (!deadline) return { text: '—', color: '#7c8aa5' };
  const days = Math.ceil((Date.parse(deadline) - Date.now()) / 86_400_000);
  if (days < 0) return { text: `${Math.abs(days)}d overdue`, color: '#f87171' };
  if (days <= 7) return { text: `${days}d left`, color: '#fbbf24' };
  if (days <= 30) return { text: `${days}d left`, color: '#d97706' };
  return { text: deadline, color: '#7c8aa5' };
}

export default function PortfolioScreen() {
  const { session } = useAuth();
  const router = useRouter();
  const token = session!.token;
  const [typeFilter, setTypeFilter] = useState('Patent');

  const query = useQuery({
    queryKey: ['portfolio', typeFilter],
    queryFn: () => getProjects(token, { project_type: typeFilter, per_page: 100 }),
    staleTime: 60_000,
  });

  const projects = query.data?.data ?? [];

  // Group by patent_office_code
  const grouped = projects.reduce<Record<string, Project[]>>((acc, p) => {
    const key = (p as Project & { patent_office_code?: string }).patent_office_code ?? 'Other';
    if (!acc[key]) acc[key] = [];
    acc[key].push(p);
    return acc;
  }, {});

  const sections = Object.entries(grouped).sort(([a], [b]) => {
    const order = ['IN', 'US', 'EP', 'WO', 'AU', 'CA', 'JP', 'CN', 'KR'];
    return (order.indexOf(a) ?? 99) - (order.indexOf(b) ?? 99);
  });

  const total = projects.length;
  const active = projects.filter((p) => p.status === 'Active' || p.status === 'In Progress').length;
  const overdue = projects.filter((p) => {
    const d = (p as Project).hard_deadline;
    return d && Date.parse(d) < Date.now() && p.status !== 'Closed' && p.status !== 'Completed';
  }).length;

  return (
    <SafeAreaView style={styles.safe}>
      {/* Stats */}
      <View style={styles.statsBar}>
        <StatCell label="Total" value={String(total)} color="#f0b23d" />
        <StatCell label="Active" value={String(active)} color="#86efac" />
        <StatCell label="Overdue" value={String(overdue)} color="#f87171" />
        <StatCell label="Offices" value={String(sections.length)} color="#60a5fa" />
      </View>

      {/* Type filter chips */}
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.filterBar} contentContainerStyle={styles.filterContent}>
        {TYPE_FILTERS.map((f) => (
          <Pressable key={f.value} onPress={() => setTypeFilter(f.value)} style={[styles.chip, typeFilter === f.value && styles.chipActive]}>
            <Text style={[styles.chipText, typeFilter === f.value && styles.chipTextActive]}>{f.label}</Text>
          </Pressable>
        ))}
      </ScrollView>

      {query.isLoading ? (
        <ActivityIndicator color="#f0b23d" style={{ marginTop: 40 }} />
      ) : sections.length === 0 ? (
        <Text style={styles.empty}>No projects found.</Text>
      ) : (
        <FlatList
          data={sections}
          keyExtractor={([office]) => office}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={query.isRefetching} onRefresh={() => void query.refetch()} tintColor="#f0b23d" />}
          renderItem={({ item: [office, items] }) => (
            <View style={styles.officeGroup}>
              <View style={styles.officeHeader}>
                <Text style={styles.officeCode}>{office}</Text>
                <Text style={styles.officeName}>{OFFICE_LABELS[office] ?? office}</Text>
                <View style={styles.officeBadge}>
                  <Text style={styles.officeBadgeText}>{items.length}</Text>
                </View>
              </View>
              {items.map((p) => {
                const sc = STATUS_COLORS[p.status] ?? '#64748b';
                const dl = deadlineLabel(p.hard_deadline);
                return (
                  <Pressable
                    key={p.id}
                    style={({ pressed }) => [styles.projectCard, pressed && styles.projectCardPressed]}
                    onPress={() => router.push(`/projects/${p.id}`)}
                  >
                    <View style={styles.projectTop}>
                      <Text style={styles.docket}>{p.docket_number}</Text>
                      <View style={[styles.statusBadge, { backgroundColor: sc + '22' }]}>
                        <Text style={[styles.statusText, { color: sc }]}>{p.status}</Text>
                      </View>
                    </View>
                    <Text style={styles.projectName}>{p.project_name}</Text>
                    <View style={styles.projectMeta}>
                      <Text style={styles.clientName}>{p.client.company_name}</Text>
                      <Text style={[styles.deadline, { color: dl.color }]}>{dl.text}</Text>
                    </View>
                  </Pressable>
                );
              })}
            </View>
          )}
        />
      )}
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

  statsBar:        {
    backgroundColor: '#0b1829',
    borderBottomColor: 'rgba(100,160,255,0.1)',
    borderBottomWidth: 1,
    flexDirection: 'row',
    paddingHorizontal: 8,
    paddingVertical: 14,
  },
  statCell:        { alignItems: 'center', flex: 1, gap: 3 },
  statValue:       { fontSize: 20, fontWeight: '800', letterSpacing: -0.5 },
  statLabel:       { color: '#334155', fontSize: 10, fontWeight: '700', letterSpacing: 1, textTransform: 'uppercase' },

  filterBar:       { flexGrow: 0 },
  filterContent:   { flexDirection: 'row', gap: 8, paddingHorizontal: 16, paddingVertical: 12 },
  chip:            {
    borderColor: 'rgba(100,160,255,0.2)',
    borderRadius: 20,
    borderWidth: 1,
    flexShrink: 0,
    paddingHorizontal: 18,
    paddingVertical: 10,
    backgroundColor: 'rgba(255,255,255,0.04)',
  },
  chipActive:      { backgroundColor: '#f0b23d', borderColor: '#f0b23d' },
  chipText:        { color: '#475569', fontSize: 13, fontWeight: '600' },
  chipTextActive:  { color: '#040d1a', fontWeight: '800' },

  list:            { gap: 20, padding: 16 },
  officeGroup:     { gap: 10 },
  officeHeader:    { alignItems: 'center', flexDirection: 'row', gap: 10, marginBottom: 4, paddingHorizontal: 4 },
  officeCode:      { color: '#f0b23d', fontFamily: 'monospace', fontSize: 15, fontWeight: '800' },
  officeName:      { color: '#475569', flex: 1, fontSize: 13, fontWeight: '600' },
  officeBadge:     {
    alignItems: 'center',
    backgroundColor: 'rgba(240,178,61,0.12)',
    borderColor: 'rgba(240,178,61,0.25)',
    borderRadius: 10,
    borderWidth: 1,
    justifyContent: 'center',
    minWidth: 28,
    paddingHorizontal: 8,
    paddingVertical: 2,
  },
  officeBadgeText: { color: '#f0b23d', fontSize: 12, fontWeight: '800' },

  projectCard:     {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 18,
    borderTopColor: 'rgba(255,255,255,0.08)',
    borderWidth: 1,
    elevation: 6,
    gap: 8,
    marginLeft: 16,
    overflow: 'hidden',
    padding: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.35,
    shadowRadius: 14,
  },
  projectCardPressed: { opacity: 0.85, transform: [{ scale: 0.98 }] },

  projectTop:      { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  docket:          { color: '#60a5fa', fontFamily: 'monospace', fontSize: 12, fontWeight: '700' },
  statusBadge:     { borderRadius: 6, paddingHorizontal: 8, paddingVertical: 3 },
  statusText:      { fontSize: 11, fontWeight: '700' },
  projectName:     { color: '#f1f5f9', fontSize: 14, fontWeight: '700', lineHeight: 20 },
  projectMeta:     { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginTop: 2 },
  clientName:      { color: '#334155', fontSize: 12, fontWeight: '500' },
  deadline:        { fontSize: 12, fontWeight: '700' },

  empty:           { color: '#334155', fontSize: 14, marginTop: 60, textAlign: 'center' },
});
