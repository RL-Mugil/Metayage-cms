import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { getLifecycleStats, getProjects } from '../../../src/lib/api';
import { useAuth } from '../../../src/providers/auth-provider';
import type { Project } from '../../../src/types/api';

const STAGE_ORDER = [
  'Intake',
  'Drafting',
  'Filing',
  'Examination',
  'Object Received',
  'Granted',
  'Renewal',
];

const STAGE_COLORS: Record<string, string> = {
  Intake:           '#60a5fa',
  Drafting:         '#a78bfa',
  Filing:           '#f0b23d',
  Examination:      '#fb923c',
  'Object Received':'#f87171',
  Granted:          '#86efac',
  Renewal:          '#34d399',
};

function deadlineLabel(deadline?: string | null): { text: string; color: string } {
  if (!deadline) return { text: '—', color: '#7c8aa5' };
  const days = Math.ceil((Date.parse(deadline) - Date.now()) / 86_400_000);
  if (days < 0) return { text: `${Math.abs(days)}d overdue`, color: '#f87171' };
  if (days <= 7) return { text: `${days}d left`, color: '#fbbf24' };
  return { text: deadline, color: '#7c8aa5' };
}

export default function LifecycleScreen() {
  const { session } = useAuth();
  const router = useRouter();
  const token = session!.token;
  const [selectedStage, setSelectedStage] = useState<string | null>(null);

  const statsQuery = useQuery({
    queryKey: ['lifecycle-stats'],
    queryFn: () => getLifecycleStats(token),
    staleTime: 60_000,
  });

  const projectsQuery = useQuery({
    queryKey: ['projects-lifecycle', selectedStage],
    queryFn: () => getProjects(token, { lifecycle_stage: selectedStage!, per_page: 50 }),
    enabled: selectedStage !== null,
    staleTime: 30_000,
  });

  const stats = statsQuery.data ?? {};
  const total = Object.values(stats).reduce((s, n) => s + n, 0);

  const stages = STAGE_ORDER.filter((s) => stats[s] != null && stats[s] > 0)
    .concat(Object.keys(stats).filter((s) => !STAGE_ORDER.includes(s) && stats[s] > 0));

  const maxCount = Math.max(...Object.values(stats), 1);

  const drillProjects = projectsQuery.data?.data ?? [];

  return (
    <SafeAreaView style={styles.safe}>
      {/* Header stats */}
      <View style={styles.headerBar}>
        <View>
          <Text style={styles.totalCount}>{total}</Text>
          <Text style={styles.totalLabel}>Matters in pipeline</Text>
        </View>
        {selectedStage ? (
          <Pressable onPress={() => setSelectedStage(null)} style={styles.clearBtn}>
            <Text style={styles.clearBtnText}>Show all stages</Text>
          </Pressable>
        ) : null}
      </View>

      {statsQuery.isLoading ? (
        <ActivityIndicator color="#f0b23d" style={{ marginTop: 40 }} />
      ) : stages.length === 0 ? (
        <Text style={styles.empty}>No active matters in pipeline.</Text>
      ) : selectedStage === null ? (
        /* Pipeline overview */
        <FlatList
          data={stages}
          keyExtractor={(s) => s}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={statsQuery.isRefetching} onRefresh={() => void statsQuery.refetch()} tintColor="#f0b23d" />}
          renderItem={({ item: stage }) => {
            const count = stats[stage] ?? 0;
            const color = STAGE_COLORS[stage] ?? '#94a3b8';
            const pct = (count / maxCount) * 100;
            return (
              <Pressable style={({ pressed }) => [styles.stageCard, pressed && { opacity: 0.85, transform: [{ scale: 0.98 }] }]} onPress={() => setSelectedStage(stage)}>
                <View style={styles.stageTop}>
                  <View style={[styles.stageDot, { backgroundColor: color }]} />
                  <Text style={styles.stageName}>{stage}</Text>
                  <Text style={[styles.stageCount, { color }]}>{count}</Text>
                </View>
                <View style={styles.barTrack}>
                  <View style={[styles.barFill, { width: `${pct}%`, backgroundColor: color }]} />
                </View>
                <Text style={styles.tapHint}>Tap to view matters →</Text>
              </Pressable>
            );
          }}
        />
      ) : (
        /* Drill-down: projects in this stage */
        <FlatList
          data={drillProjects}
          keyExtractor={(p) => String(p.id)}
          contentContainerStyle={styles.list}
          refreshControl={<RefreshControl refreshing={projectsQuery.isRefetching} onRefresh={() => void projectsQuery.refetch()} tintColor="#f0b23d" />}
          ListHeaderComponent={
            <View style={styles.drillHeader}>
              <View style={[styles.stageDot, { backgroundColor: STAGE_COLORS[selectedStage] ?? '#94a3b8' }]} />
              <Text style={styles.drillTitle}>{selectedStage}</Text>
              <Text style={styles.drillCount}>{drillProjects.length} matters</Text>
            </View>
          }
          ListEmptyComponent={
            projectsQuery.isLoading
              ? <ActivityIndicator color="#f0b23d" style={{ marginTop: 20 }} />
              : <Text style={styles.empty}>No matters in this stage.</Text>
          }
          renderItem={({ item: p }) => {
            const dl = deadlineLabel(p.hard_deadline);
            return (
              <Pressable style={({ pressed }) => [styles.projectCard, pressed && { opacity: 0.85, transform: [{ scale: 0.98 }] }]} onPress={() => router.push(`/projects/${p.id}`)}>
                <View style={styles.projectTop}>
                  <Text style={styles.docket}>{p.docket_number}</Text>
                  <Text style={[styles.deadline, { color: dl.color }]}>{dl.text}</Text>
                </View>
                <Text style={styles.projectName}>{p.project_name}</Text>
                <Text style={styles.clientName}>{p.client.company_name}</Text>
              </Pressable>
            );
          }}
        />
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe:         { backgroundColor: '#040d1a', flex: 1 },
  headerBar:    {
    alignItems: 'center',
    backgroundColor: '#0b1829',
    borderBottomColor: 'rgba(100,160,255,0.1)',
    borderBottomWidth: 1,
    flexDirection: 'row',
    justifyContent: 'space-between',
    padding: 20,
  },
  totalCount:   { color: '#f0b23d', fontSize: 36, fontWeight: '800', letterSpacing: -1 },
  totalLabel:   { color: '#334155', fontSize: 13, fontWeight: '500', marginTop: 2 },
  clearBtn:     {
    backgroundColor: 'rgba(240,178,61,0.12)',
    borderColor: 'rgba(240,178,61,0.3)',
    borderRadius: 12,
    borderWidth: 1,
    paddingHorizontal: 14,
    paddingVertical: 8,
  },
  clearBtnText: { color: '#f0b23d', fontSize: 13, fontWeight: '700' },

  list:         { gap: 12, padding: 16 },

  stageCard:    {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 20,
    borderTopColor: 'rgba(255,255,255,0.08)',
    borderWidth: 1,
    elevation: 6,
    gap: 12,
    overflow: 'hidden',
    padding: 18,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.35,
    shadowRadius: 14,
  },
  stageTop:     { alignItems: 'center', flexDirection: 'row', gap: 12 },
  stageDot:     { borderRadius: 6, height: 12, width: 12 },
  stageName:    { color: '#f1f5f9', flex: 1, fontSize: 16, fontWeight: '700' },
  stageCount:   { fontSize: 24, fontWeight: '800', letterSpacing: -0.5 },
  barTrack:     { backgroundColor: 'rgba(255,255,255,0.06)', borderRadius: 4, height: 8, overflow: 'hidden' },
  barFill:      { borderRadius: 4, height: '100%', opacity: 0.85 },
  tapHint:      { color: '#334155', fontSize: 12, fontWeight: '500' },

  drillHeader:  { alignItems: 'center', flexDirection: 'row', gap: 10, marginBottom: 8 },
  drillTitle:   { color: '#f1f5f9', flex: 1, fontSize: 18, fontWeight: '700' },
  drillCount:   { color: '#475569', fontSize: 14 },

  projectCard:  {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 18,
    borderTopColor: 'rgba(255,255,255,0.07)',
    borderWidth: 1,
    elevation: 5,
    gap: 6,
    padding: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 5 },
    shadowOpacity: 0.3,
    shadowRadius: 12,
  },
  projectTop:   { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  docket:       { color: '#60a5fa', fontFamily: 'monospace', fontSize: 12, fontWeight: '700' },
  deadline:     { fontSize: 12, fontWeight: '700' },
  projectName:  { color: '#f1f5f9', fontSize: 14, fontWeight: '700', lineHeight: 20 },
  clientName:   { color: '#334155', fontSize: 12, fontWeight: '500' },

  empty:        { color: '#334155', fontSize: 14, marginTop: 60, textAlign: 'center' },
});
