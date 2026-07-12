import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
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

import { createTask, deleteTask, getTasksForSession, logTaskTime, updateTaskStatus } from '../../src/lib/api';
import { enqueueAction, isOfflineLikeError } from '../../src/lib/offline-queue';
import { useAuth } from '../../src/providers/auth-provider';
import type { Task, TaskPriority, TaskStatus } from '../../src/types/api';

const PRIORITIES: TaskPriority[] = ['Low', 'Medium', 'High', 'Urgent'];

const PRIORITY_COLORS: Record<TaskPriority, string> = {
  Low: '#22c55e', Medium: '#f0b23d', High: '#fb923c', Urgent: '#ef4444',
};

const STATUS_COLORS: Record<string, string> = {
  Pending: '#334155', 'In Progress': '#3b82f6', Review: '#a855f7',
  Completed: '#22c55e', Blocked: '#ef4444',
};

const logSchema = z.object({
  durationHours: z.string().refine((v) => { const n = Number(v); return Number.isFinite(n) && n >= 0.1 && n <= 24; }, 'Enter 0.1–24 hours.'),
  description: z.string().min(3, 'Add a short work note.').max(300, 'Keep it concise.'),
});

const createSchema = z.object({
  title: z.string().min(2, 'Title is required.'),
  description: z.string().optional(),
  priority: z.enum(['Low', 'Medium', 'High', 'Urgent'] as const),
  due_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Use YYYY-MM-DD.').optional().or(z.literal('')),
});

type LogFormValues = z.infer<typeof logSchema>;
type CreateFormValues = z.infer<typeof createSchema>;

function nextActions(task: Task): Array<{ label: string; status: TaskStatus }> {
  switch (task.status) {
    case 'Pending':     return [{ label: 'Start', status: 'In Progress' }, { label: 'Block', status: 'Blocked' }];
    case 'In Progress': return [{ label: 'Review', status: 'Review' }, { label: 'Done', status: 'Completed' }];
    case 'Review':      return [{ label: 'Resume', status: 'In Progress' }, { label: 'Done', status: 'Completed' }];
    case 'Blocked':     return [{ label: 'Resume', status: 'In Progress' }];
    default:            return [];
  }
}

function queueId(p: string) { return `${p}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`; }

export default function TasksScreen() {
  const { session } = useAuth();
  const queryClient = useQueryClient();
  const token = session!.token;

  const [selectedTask, setSelectedTask] = useState<Task | null>(null);
  const [showCreate, setShowCreate] = useState(false);

  const logForm = useForm<LogFormValues>({
    resolver: zodResolver(logSchema),
    defaultValues: { durationHours: '0.5', description: '' },
  });

  const createForm = useForm<CreateFormValues>({
    resolver: zodResolver(createSchema),
    defaultValues: { title: '', description: '', priority: 'Medium', due_date: '' },
  });

  const query = useQuery({
    queryKey: ['tasks'],
    queryFn: () => getTasksForSession(token),
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['tasks'] });

  const statusMutation = useMutation({
    mutationFn: async ({ id, status }: { id: number; status: TaskStatus }) => {
      try { return await updateTaskStatus(token, id, status); }
      catch (error) {
        if (!isOfflineLikeError(error)) throw error;
        await enqueueAction({ id: queueId('task-status'), kind: 'tasks.status', createdAt: new Date().toISOString(), payload: { taskId: id, status } });
        return null;
      }
    },
    onSuccess: async () => { await refresh(); },
    onError: (e) => Alert.alert('Failed', e instanceof Error ? e.message : 'Unable to update task.'),
  });

  const logMutation = useMutation({
    mutationFn: async ({ task, values }: { task: Task; values: LogFormValues }) => {
      if (!task.project_id) throw new Error('Task not linked to a project.');
      try {
        await logTaskTime(token, { taskId: task.id, projectId: task.project_id, durationHours: Number(values.durationHours), description: values.description });
        return 'saved' as const;
      } catch (error) {
        if (!isOfflineLikeError(error)) throw error;
        await enqueueAction({ id: queueId('task-time'), kind: 'tasks.timeLog', createdAt: new Date().toISOString(), payload: { taskId: task.id, projectId: task.project_id, durationHours: Number(values.durationHours), description: values.description } });
        return 'queued' as const;
      }
    },
    onSuccess: async (result) => {
      await refresh();
      setSelectedTask(null);
      logForm.reset({ durationHours: '0.5', description: '' });
      Alert.alert('Time logged', result === 'queued' ? 'Queued for sync.' : 'Saved.');
    },
    onError: (e) => Alert.alert('Failed', e instanceof Error ? e.message : 'Unable to log time.'),
  });

  const createMutation = useMutation({
    mutationFn: (values: CreateFormValues) =>
      createTask(token, {
        title: values.title,
        description: values.description || undefined,
        priority: values.priority,
        due_date: values.due_date || undefined,
      }),
    onSuccess: async () => {
      await refresh();
      setShowCreate(false);
      createForm.reset();
      Alert.alert('Task created', 'Your new task has been added.');
    },
    onError: (e) => Alert.alert('Failed', e instanceof Error ? e.message : 'Could not create task.'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteTask(token, id),
    onSuccess: async () => { await refresh(); },
    onError: (e) => Alert.alert('Failed', e instanceof Error ? e.message : 'Could not delete task.'),
  });

  function confirmDelete(task: Task) {
    Alert.alert('Delete task', `Delete "${task.title}"? This cannot be undone.`, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Delete', style: 'destructive', onPress: () => void deleteMutation.mutateAsync(task.id) },
    ]);
  }

  const submitLog = logForm.handleSubmit((values) => {
    if (selectedTask) void logMutation.mutateAsync({ task: selectedTask, values });
  });

  const submitCreate = createForm.handleSubmit((values) => void createMutation.mutateAsync(values));

  const tasks = query.data ?? [];
  const pending = tasks.filter((t) => t.status === 'Pending').length;
  const inProgress = tasks.filter((t) => t.status === 'In Progress').length;

  return (
    <SafeAreaView style={s.safe}>
      {/* Stats strip */}
      {tasks.length > 0 && (
        <View style={s.statsRow}>
          <View style={s.statChip}>
            <Text style={s.statValue}>{tasks.length}</Text>
            <Text style={s.statLabel}>Total</Text>
          </View>
          <View style={[s.statChip, { borderColor: 'rgba(59,130,246,0.25)' }]}>
            <Text style={[s.statValue, { color: '#3b82f6' }]}>{inProgress}</Text>
            <Text style={s.statLabel}>Active</Text>
          </View>
          <View style={[s.statChip, { borderColor: 'rgba(240,178,61,0.25)' }]}>
            <Text style={[s.statValue, { color: '#f0b23d' }]}>{pending}</Text>
            <Text style={s.statLabel}>Pending</Text>
          </View>
        </View>
      )}

      <FlatList
        contentContainerStyle={s.list}
        data={tasks}
        keyExtractor={(item) => String(item.id)}
        refreshControl={<RefreshControl refreshing={query.isRefetching} onRefresh={() => void query.refetch()} tintColor="#f0b23d" />}
        renderItem={({ item }) => {
          const pColor = PRIORITY_COLORS[item.priority];
          const sColor = STATUS_COLORS[item.status] ?? '#334155';
          const isCompleted = item.status === 'Completed';
          return (
            <Pressable
              style={({ pressed }) => [s.card, pressed && s.cardPressed, isCompleted && s.cardDone]}
            >
              {/* Left accent bar */}
              <View style={[s.accentBar, { backgroundColor: pColor }]} />

              <View style={s.cardContent}>
                <View style={s.cardTop}>
                  <Text style={[s.taskTitle, isCompleted && s.taskTitleDone]} numberOfLines={2}>{item.title}</Text>
                  <View style={[s.statusBadge, { backgroundColor: sColor + '22' }]}>
                    <Text style={[s.statusText, { color: sColor }]}>{item.status}</Text>
                  </View>
                </View>

                {item.project?.project_name ? (
                  <Text style={s.projectName} numberOfLines={1}>{item.project.project_name}</Text>
                ) : null}

                {item.description ? (
                  <Text style={s.desc} numberOfLines={2}>{item.description}</Text>
                ) : null}

                <View style={s.cardMeta}>
                  <View style={[s.priorityTag, { backgroundColor: pColor + '1a' }]}>
                    <View style={[s.priorityDot, { backgroundColor: pColor }]} />
                    <Text style={[s.priorityText, { color: pColor }]}>{item.priority}</Text>
                  </View>
                  {item.due_date ? <Text style={s.dueDate}>Due {item.due_date}</Text> : null}
                </View>

                {!isCompleted && (
                  <View style={s.actions}>
                    {nextActions(item).map((action) => (
                      <Pressable
                        key={`${item.id}-${action.status}`}
                        onPress={() => void statusMutation.mutateAsync({ id: item.id, status: action.status })}
                        style={[s.actionBtn, action.status === 'Completed' && s.actionBtnGreen, action.status === 'Blocked' && s.actionBtnRed]}
                      >
                        <Text style={s.actionBtnText}>{action.label}</Text>
                      </Pressable>
                    ))}
                    {item.project_id ? (
                      <Pressable
                        onPress={() => { setSelectedTask(item); logForm.reset({ durationHours: '0.5', description: `Worked on ${item.title.toLowerCase()}` }); }}
                        style={s.logBtn}
                      >
                        <Text style={s.logBtnText}>Log time</Text>
                      </Pressable>
                    ) : null}
                    <Pressable onPress={() => confirmDelete(item)} style={s.deleteBtn}>
                      <Text style={s.deleteBtnText}>✕</Text>
                    </Pressable>
                  </View>
                )}

                {isCompleted && (
                  <Pressable onPress={() => confirmDelete(item)} style={s.deleteBtnFull}>
                    <Text style={s.deleteBtnText}>Remove</Text>
                  </Pressable>
                )}
              </View>
            </Pressable>
          );
        }}
        ListEmptyComponent={
          query.isLoading
            ? <ActivityIndicator color="#f0b23d" style={{ marginTop: 48 }} />
            : (
              <View style={s.empty}>
                <Text style={s.emptyIcon}>✓</Text>
                <Text style={s.emptyTitle}>No tasks assigned</Text>
                <Text style={s.emptyMsg}>Tasks assigned to you will appear here.</Text>
              </View>
            )
        }
      />

      {/* FAB */}
      <Pressable onPress={() => setShowCreate(true)} style={s.fab}>
        <Text style={s.fabText}>+</Text>
      </Pressable>

      {/* Log Time Modal */}
      <Modal animationType="slide" transparent visible={Boolean(selectedTask)} onRequestClose={() => setSelectedTask(null)}>
        <Pressable style={s.backdrop} onPress={() => setSelectedTask(null)} />
        <View style={s.sheet}>
          <View style={s.sheetHandle} />
          <Text style={s.sheetTitle}>Log Time</Text>
          <Text style={s.sheetSub} numberOfLines={2}>{selectedTask?.title}</Text>

          <View style={s.field}>
            <Text style={s.fieldLabel}>Hours worked</Text>
            <Controller control={logForm.control} name="durationHours" render={({ field }) => (
              <TextInput style={s.input} keyboardType="decimal-pad" placeholder="0.5" placeholderTextColor="#334155" value={String(field.value ?? '')} onChangeText={field.onChange} />
            )} />
            {logForm.formState.errors.durationHours ? <Text style={s.fieldError}>{logForm.formState.errors.durationHours.message}</Text> : null}
          </View>

          <View style={s.field}>
            <Text style={s.fieldLabel}>Work note</Text>
            <Controller control={logForm.control} name="description" render={({ field }) => (
              <TextInput style={[s.input, s.textArea]} multiline textAlignVertical="top" placeholder="What did you work on?" placeholderTextColor="#334155" value={field.value} onChangeText={field.onChange} />
            )} />
            {logForm.formState.errors.description ? <Text style={s.fieldError}>{logForm.formState.errors.description.message}</Text> : null}
          </View>

          <View style={s.btnRow}>
            <Pressable onPress={() => setSelectedTask(null)} style={s.cancelBtn}>
              <Text style={s.cancelBtnText}>Cancel</Text>
            </Pressable>
            <Pressable onPress={() => void submitLog()} disabled={logMutation.isPending} style={[s.submitBtn, logMutation.isPending && { opacity: 0.6 }]}>
              <Text style={s.submitBtnText}>{logMutation.isPending ? 'Saving…' : 'Save Log'}</Text>
            </Pressable>
          </View>
        </View>
      </Modal>

      {/* Create Task Modal */}
      <Modal animationType="slide" transparent visible={showCreate} onRequestClose={() => setShowCreate(false)}>
        <Pressable style={s.backdrop} onPress={() => setShowCreate(false)} />
        <View style={s.sheet}>
          <View style={s.sheetHandle} />
          <Text style={s.sheetTitle}>New Task</Text>

          <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={{ gap: 16 }}>
            <View style={s.field}>
              <Text style={s.fieldLabel}>Title *</Text>
              <Controller control={createForm.control} name="title" render={({ field }) => (
                <TextInput style={s.input} placeholder="Task title" placeholderTextColor="#334155" value={field.value} onChangeText={field.onChange} />
              )} />
              {createForm.formState.errors.title ? <Text style={s.fieldError}>{createForm.formState.errors.title.message}</Text> : null}
            </View>

            <View style={s.field}>
              <Text style={s.fieldLabel}>Description</Text>
              <Controller control={createForm.control} name="description" render={({ field }) => (
                <TextInput style={[s.input, s.textArea]} multiline textAlignVertical="top" placeholder="Optional description…" placeholderTextColor="#334155" value={field.value} onChangeText={field.onChange} />
              )} />
            </View>

            <View style={s.field}>
              <Text style={s.fieldLabel}>Priority</Text>
              <Controller control={createForm.control} name="priority" render={({ field }) => (
                <View style={s.chipRow}>
                  {PRIORITIES.map((p) => {
                    const active = field.value === p;
                    const pColor = PRIORITY_COLORS[p];
                    return (
                      <Pressable key={p} onPress={() => field.onChange(p)}
                        style={[s.chip, active && { backgroundColor: pColor, borderColor: pColor }]}>
                        <Text style={[s.chipText, active && { color: '#040d1a', fontWeight: '800' }]}>{p}</Text>
                      </Pressable>
                    );
                  })}
                </View>
              )} />
            </View>

            <View style={s.field}>
              <Text style={s.fieldLabel}>Due date (YYYY-MM-DD)</Text>
              <Controller control={createForm.control} name="due_date" render={({ field }) => (
                <TextInput style={s.input} placeholder="2026-12-31" placeholderTextColor="#334155" keyboardType="numbers-and-punctuation" value={field.value} onChangeText={field.onChange} />
              )} />
              {createForm.formState.errors.due_date ? <Text style={s.fieldError}>{createForm.formState.errors.due_date.message}</Text> : null}
            </View>

            <View style={s.btnRow}>
              <Pressable onPress={() => { setShowCreate(false); createForm.reset(); }} style={s.cancelBtn}>
                <Text style={s.cancelBtnText}>Cancel</Text>
              </Pressable>
              <Pressable onPress={() => void submitCreate()} disabled={createMutation.isPending} style={[s.submitBtn, createMutation.isPending && { opacity: 0.6 }]}>
                <Text style={s.submitBtnText}>{createMutation.isPending ? 'Creating…' : 'Create Task'}</Text>
              </Pressable>
            </View>
          </ScrollView>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

const s = StyleSheet.create({
  safe:           { backgroundColor: '#040d1a', flex: 1 },

  statsRow:       { flexDirection: 'row', gap: 10, paddingHorizontal: 16, paddingVertical: 12 },
  statChip:       { alignItems: 'center', backgroundColor: '#0b1829', borderColor: 'rgba(100,160,255,0.15)', borderRadius: 14, borderWidth: 1, flex: 1, paddingVertical: 10 },
  statValue:      { color: '#f1f5f9', fontSize: 20, fontWeight: '800' },
  statLabel:      { color: '#334155', fontSize: 10, fontWeight: '700', letterSpacing: 0.8, textTransform: 'uppercase' },

  list:           { gap: 12, padding: 16, paddingBottom: 100 },

  card:           {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 20,
    borderTopColor: 'rgba(255,255,255,0.08)',
    borderWidth: 1,
    flexDirection: 'row',
    overflow: 'hidden',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 8 },
    shadowOpacity: 0.4,
    shadowRadius: 16,
    elevation: 6,
  },
  cardPressed:    { transform: [{ scale: 0.98 }], opacity: 0.85 },
  cardDone:       { opacity: 0.55 },

  accentBar:      { borderRadius: 2, width: 4 },
  cardContent:    { flex: 1, gap: 8, padding: 16 },

  cardTop:        { alignItems: 'flex-start', flexDirection: 'row', gap: 8, justifyContent: 'space-between' },
  taskTitle:      { color: '#f1f5f9', flex: 1, fontSize: 15, fontWeight: '700', lineHeight: 21 },
  taskTitleDone:  { color: '#334155', textDecorationLine: 'line-through' },

  statusBadge:    { borderRadius: 8, paddingHorizontal: 8, paddingVertical: 3 },
  statusText:     { fontSize: 11, fontWeight: '700', letterSpacing: 0.3 },

  projectName:    { color: '#475569', fontSize: 12, fontWeight: '500' },
  desc:           { color: '#475569', fontSize: 13, lineHeight: 18 },

  cardMeta:       { alignItems: 'center', flexDirection: 'row', gap: 10, marginTop: 2 },
  priorityTag:    { alignItems: 'center', borderRadius: 8, flexDirection: 'row', gap: 5, paddingHorizontal: 8, paddingVertical: 4 },
  priorityDot:    { borderRadius: 3, height: 6, width: 6 },
  priorityText:   { fontSize: 11, fontWeight: '700' },
  dueDate:        { color: '#334155', fontSize: 12 },

  actions:        { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 4 },
  actionBtn:      { alignItems: 'center', backgroundColor: '#1e3a8a', borderRadius: 10, justifyContent: 'center', paddingHorizontal: 14, paddingVertical: 8 },
  actionBtnGreen: { backgroundColor: '#14532d' },
  actionBtnRed:   { backgroundColor: '#7f1d1d' },
  actionBtnText:  { color: '#fff', fontSize: 12, fontWeight: '700' },
  logBtn:         { alignItems: 'center', backgroundColor: '#0f2040', borderColor: 'rgba(100,160,255,0.2)', borderRadius: 10, borderWidth: 1, justifyContent: 'center', paddingHorizontal: 12, paddingVertical: 8 },
  logBtnText:     { color: '#60a5fa', fontSize: 12, fontWeight: '700' },
  deleteBtn:      { alignItems: 'center', backgroundColor: '#2d0a0a', borderColor: 'rgba(239,68,68,0.2)', borderRadius: 10, borderWidth: 1, height: 34, justifyContent: 'center', marginLeft: 'auto', width: 34 },
  deleteBtnFull:  { alignItems: 'center', backgroundColor: '#2d0a0a', borderColor: 'rgba(239,68,68,0.2)', borderRadius: 10, borderWidth: 1, marginTop: 4, paddingVertical: 8 },
  deleteBtnText:  { color: '#f87171', fontSize: 12, fontWeight: '700' },

  empty:          { alignItems: 'center', paddingTop: 80 },
  emptyIcon:      { color: '#22c55e', fontSize: 40, marginBottom: 12 },
  emptyTitle:     { color: '#f1f5f9', fontSize: 18, fontWeight: '700', marginBottom: 6 },
  emptyMsg:       { color: '#334155', fontSize: 14, textAlign: 'center' },

  fab:            { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 30, bottom: 28, elevation: 8, height: 60, justifyContent: 'center', position: 'absolute', right: 20, shadowColor: '#f0b23d', shadowOffset: { width: 0, height: 6 }, shadowOpacity: 0.45, shadowRadius: 14, width: 60 },
  fabText:        { color: '#040d1a', fontSize: 30, fontWeight: '700', lineHeight: 34 },

  backdrop:       { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(2,8,22,0.85)' },
  sheet:          { backgroundColor: '#0b1829', borderColor: 'rgba(100,160,255,0.12)', borderTopLeftRadius: 28, borderTopRightRadius: 28, borderWidth: 1, bottom: 0, left: 0, maxHeight: '88%', padding: 24, paddingBottom: 40, position: 'absolute', right: 0 },
  sheetHandle:    { alignSelf: 'center', backgroundColor: '#1e3a5f', borderRadius: 3, height: 4, marginBottom: 20, width: 40 },
  sheetTitle:     { color: '#f1f5f9', fontSize: 22, fontWeight: '800', marginBottom: 4 },
  sheetSub:       { color: '#475569', fontSize: 14, marginBottom: 20 },

  field:          { gap: 8 },
  fieldLabel:     { color: '#64748b', fontSize: 11, fontWeight: '700', letterSpacing: 0.8, textTransform: 'uppercase' },
  input:          { backgroundColor: '#0d1e38', borderColor: 'rgba(100,160,255,0.15)', borderRadius: 14, borderWidth: 1, color: '#f1f5f9', fontSize: 15, paddingHorizontal: 16, paddingVertical: 13 },
  textArea:       { height: 88, lineHeight: 22 },
  fieldError:     { color: '#f87171', fontSize: 12 },

  chipRow:        { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip:           { backgroundColor: '#0d1e38', borderColor: 'rgba(100,160,255,0.15)', borderRadius: 100, borderWidth: 1, flexShrink: 0, paddingHorizontal: 16, paddingVertical: 10 },
  chipText:       { color: '#475569', fontSize: 13, fontWeight: '600' },

  btnRow:         { flexDirection: 'row', gap: 10, marginTop: 8 },
  cancelBtn:      { alignItems: 'center', backgroundColor: '#0d1e38', borderRadius: 14, flex: 1, justifyContent: 'center', paddingVertical: 14 },
  cancelBtnText:  { color: '#475569', fontSize: 15, fontWeight: '700' },
  submitBtn:      { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 14, flex: 2, justifyContent: 'center', paddingVertical: 14 },
  submitBtnText:  { color: '#040d1a', fontSize: 15, fontWeight: '800' },
});
