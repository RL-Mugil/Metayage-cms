import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams, useNavigation } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { advanceProjectStage, getProject } from '../../../src/lib/api';
import { useAuth } from '../../../src/providers/auth-provider';
import type { Stage, Task, UserRole } from '../../../src/types/api';

const CAN_ADVANCE: UserRole[] = ['super_admin', 'partner', 'manager'];

function stageIcon(status: Stage['status']): string {
  if (status === 'Completed')   return '✓';
  if (status === 'In Progress') return '▶';
  return '○';
}

function stageColor(status: Stage['status']): string {
  if (status === 'Completed')   return '#16a34a';
  if (status === 'In Progress') return '#f0b23d';
  return '#2a3c61';
}

function priorityColor(p: string): string {
  switch (p) {
    case 'Urgent': return '#dc2626';
    case 'High':   return '#ea580c';
    case 'Medium': return '#d97706';
    default:       return '#64748b';
  }
}

export default function ProjectDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { session } = useAuth();
  const navigation = useNavigation();
  const queryClient = useQueryClient();
  const token = session!.token;
  const role = session!.user.role;
  const [tab, setTab] = useState<'stages' | 'tasks' | 'team'>('stages');

  const query = useQuery({
    queryKey: ['project', id],
    queryFn: () => getProject(token, Number(id)),
  });

  const project = query.data;

  useEffect(() => {
    if (project) navigation.setOptions({ title: project.docket_number });
  }, [project, navigation]);

  const advanceMutation = useMutation({
    mutationFn: (stageName: string) => advanceProjectStage(token, Number(id), stageName),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['project', id] });
      Alert.alert('Stage updated', 'The project stage has been advanced.');
    },
    onError: (error) => Alert.alert('Failed', error instanceof Error ? error.message : 'Could not update stage.'),
  });

  if (query.isLoading) {
    return <View style={styles.center}><ActivityIndicator color="#f0b23d" size="large" /></View>;
  }

  if (!project) {
    return <View style={styles.center}><Text style={styles.empty}>Project not found.</Text></View>;
  }

  const canAdvance = CAN_ADVANCE.includes(role);
  const inProgressStage = project.stages.find((s) => s.status === 'In Progress');
  const nextPendingStage = project.stages
    .filter((s) => s.status === 'Pending')
    .sort((a, b) => a.sequence_order - b.sequence_order)[0];

  return (
    <SafeAreaView style={styles.safe} edges={['bottom']}>
      {/* Header */}
      <View style={styles.header}>
        <View style={styles.headerRow}>
          <Text style={styles.docket}>{project.docket_number}</Text>
          <View style={styles.badge}>
            <Text style={styles.badgeText}>{project.status}</Text>
          </View>
        </View>
        <Text style={styles.name}>{project.project_name}</Text>
        <Text style={styles.client}>{project.client.company_name}</Text>
        {project.hard_deadline ? (
          <Text style={[styles.deadline, Date.parse(project.hard_deadline) < Date.now() && styles.deadlineRed]}>
            Deadline: {project.hard_deadline}
          </Text>
        ) : null}
      </View>

      {/* Segment tabs */}
      <View style={styles.segmentBar}>
        {(['stages', 'tasks', 'team'] as const).map((t) => (
          <Pressable key={t} onPress={() => setTab(t)} style={[styles.segment, tab === t && styles.segmentActive]}>
            <Text style={[styles.segmentText, tab === t && styles.segmentTextActive]}>
              {t === 'stages' ? 'Stages' : t === 'tasks' ? `Tasks (${project.tasks?.length ?? 0})` : 'Team'}
            </Text>
          </Pressable>
        ))}
      </View>

      {tab === 'stages' ? (
        <ScrollView contentContainerStyle={styles.section}>
          {project.stages
            .sort((a, b) => a.sequence_order - b.sequence_order)
            .map((stage) => {
              const color = stageColor(stage.status);
              return (
                <View key={stage.id} style={styles.stageRow}>
                  <View style={[styles.stageIcon, { borderColor: color, backgroundColor: stage.status === 'Completed' ? color + '33' : '#0d1321' }]}>
                    <Text style={[styles.stageIconText, { color }]}>{stageIcon(stage.status)}</Text>
                  </View>
                  <View style={styles.stageLine}>
                    <Text style={[styles.stageName, stage.status === 'In Progress' && styles.stageNameActive]}>{stage.stage_name}</Text>
                    <Text style={[styles.stageStatus, { color }]}>{stage.status}</Text>
                  </View>
                </View>
              );
            })}

          {canAdvance && nextPendingStage ? (
            <Pressable
              style={styles.advanceBtn}
              onPress={() => {
                Alert.alert(
                  'Advance stage',
                  `Mark "${nextPendingStage.stage_name}" as In Progress?`,
                  [
                    { text: 'Cancel', style: 'cancel' },
                    { text: 'Advance', onPress: () => void advanceMutation.mutateAsync(nextPendingStage.stage_name) },
                  ],
                );
              }}
            >
              <Text style={styles.advanceBtnText}>
                {advanceMutation.isPending ? 'Updating…' : `Advance → ${nextPendingStage.stage_name}`}
              </Text>
            </Pressable>
          ) : null}
        </ScrollView>
      ) : tab === 'tasks' ? (
        <FlatList
          data={project.tasks ?? []}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={styles.section}
          renderItem={({ item }: { item: Task }) => (
            <View style={styles.taskCard}>
              <View style={styles.headerRow}>
                <Text style={styles.taskTitle}>{item.title}</Text>
                <View style={[styles.smallBadge, { backgroundColor: priorityColor(item.priority) + '33' }]}>
                  <Text style={[styles.smallBadgeText, { color: priorityColor(item.priority) }]}>{item.priority}</Text>
                </View>
              </View>
              <Text style={styles.taskStatus}>{item.status}</Text>
              {item.due_date ? <Text style={styles.taskDue}>Due {item.due_date}</Text> : null}
            </View>
          )}
          ListEmptyComponent={<Text style={styles.empty}>No tasks linked to this project.</Text>}
        />
      ) : (
        <ScrollView contentContainerStyle={styles.section}>
          <TeamRow label="Partner"         person={project.partner} />
          <TeamRow label="Manager"         person={project.manager} />
          <TeamRow label="Patent Engineer" person={project.patentEngineer} />
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

function TeamRow({ label, person }: { label: string; person?: { id: number; name: string } | null }) {
  return (
    <View style={styles.teamRow}>
      <Text style={styles.teamLabel}>{label}</Text>
      <Text style={styles.teamName}>{person?.name ?? '—'}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  safe:             { backgroundColor: '#0d1321', flex: 1 },
  center:           { alignItems: 'center', backgroundColor: '#0d1321', flex: 1, justifyContent: 'center' },
  header:           { backgroundColor: '#131c31', borderBottomColor: '#21304f', borderBottomWidth: 1, gap: 6, padding: 20 },
  headerRow:        { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  docket:           { color: '#9fb0d3', fontFamily: 'monospace', fontSize: 14, fontWeight: '700' },
  badge:            { backgroundColor: '#2864ff33', borderColor: '#2864ff', borderRadius: 8, borderWidth: 1, paddingHorizontal: 8, paddingVertical: 2 },
  badgeText:        { color: '#7ca3ff', fontSize: 12, fontWeight: '700' },
  name:             { color: '#f8fafc', fontSize: 18, fontWeight: '800' },
  client:           { color: '#9fb0d3', fontSize: 14 },
  deadline:         { color: '#94a3b8', fontSize: 13 },
  deadlineRed:      { color: '#f87171' },
  segmentBar:       { borderBottomColor: '#21304f', borderBottomWidth: 1, flexDirection: 'row' },
  segment:          { alignItems: 'center', flex: 1, paddingVertical: 12 },
  segmentActive:    { borderBottomColor: '#f0b23d', borderBottomWidth: 2 },
  segmentText:      { color: '#94a3b8', fontSize: 13, fontWeight: '600' },
  segmentTextActive:{ color: '#f0b23d' },
  section:          { gap: 12, padding: 16 },
  stageRow:         { alignItems: 'flex-start', flexDirection: 'row', gap: 14 },
  stageIcon:        { alignItems: 'center', borderRadius: 20, borderWidth: 2, height: 36, justifyContent: 'center', width: 36 },
  stageIconText:    { fontSize: 14, fontWeight: '800' },
  stageLine:        { flex: 1, gap: 2, paddingTop: 6 },
  stageName:        { color: '#dbe4ff', fontSize: 15, fontWeight: '600' },
  stageNameActive:  { color: '#f0b23d' },
  stageStatus:      { fontSize: 12, fontWeight: '600' },
  advanceBtn:       { alignItems: 'center', backgroundColor: '#16a34a22', borderColor: '#16a34a', borderRadius: 14, borderWidth: 1, marginTop: 8, paddingVertical: 13 },
  advanceBtnText:   { color: '#86efac', fontSize: 14, fontWeight: '700' },
  taskCard:         { backgroundColor: '#131c31', borderColor: '#21304f', borderRadius: 14, borderWidth: 1, gap: 4, padding: 14 },
  taskTitle:        { color: '#f8fafc', flex: 1, fontSize: 15, fontWeight: '700' },
  taskStatus:       { color: '#9fb0d3', fontSize: 13 },
  taskDue:          { color: '#7c8aa5', fontSize: 12 },
  smallBadge:       { borderRadius: 6, paddingHorizontal: 7, paddingVertical: 2 },
  smallBadgeText:   { fontSize: 11, fontWeight: '700' },
  teamRow:          { borderBottomColor: '#1a2540', borderBottomWidth: 1, flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 14 },
  teamLabel:        { color: '#7c8aa5', fontSize: 14 },
  teamName:         { color: '#f8fafc', fontSize: 14, fontWeight: '600' },
  empty:            { color: '#94a3b8', marginTop: 24, textAlign: 'center' },
});
