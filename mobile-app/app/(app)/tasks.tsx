import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Controller, useForm } from 'react-hook-form';
import { useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Modal, Pressable, RefreshControl, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { z } from 'zod';

import { getTasksForSession, logTaskTime, updateTaskStatus } from '../../src/lib/api';
import { enqueueAction, isOfflineLikeError } from '../../src/lib/offline-queue';
import { useAuth } from '../../src/providers/auth-provider';
import type { Task, TaskLogPayload, TaskStatus } from '../../src/types/api';

const logSchema = z.object({
  durationHours: z.string().refine((value) => {
    const numeric = Number(value);
    return Number.isFinite(numeric) && numeric >= 0.1 && numeric <= 24;
  }, 'Enter between 0.1 and 24 hours.'),
  description: z.string().min(3, 'Add a short work note.').max(300, 'Keep the note concise.'),
});

type LogFormValues = z.infer<typeof logSchema>;

function nextActions(task: Task): Array<{ label: string; status: TaskStatus }> {
  switch (task.status) {
    case 'Pending':
      return [{ label: 'Start', status: 'In Progress' }, { label: 'Block', status: 'Blocked' }];
    case 'In Progress':
      return [{ label: 'Review', status: 'Review' }, { label: 'Complete', status: 'Completed' }];
    case 'Review':
      return [{ label: 'Resume', status: 'In Progress' }, { label: 'Complete', status: 'Completed' }];
    case 'Blocked':
      return [{ label: 'Resume', status: 'In Progress' }];
    default:
      return [];
  }
}

function queueId(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

export default function TasksScreen() {
  const { session } = useAuth();
  const queryClient = useQueryClient();
  const token = session!.token;
  const [selectedTask, setSelectedTask] = useState<Task | null>(null);
  const form = useForm<LogFormValues>({
    resolver: zodResolver(logSchema),
    defaultValues: {
      durationHours: '0.5',
      description: '',
    },
  });

  const query = useQuery({
    queryKey: ['tasks'],
    queryFn: () => getTasksForSession(token),
  });

  const refreshTasks = async () => {
    await queryClient.invalidateQueries({ queryKey: ['tasks'] });
  };

  const updateStatusMutation = useMutation({
    mutationFn: async ({ id, status }: { id: number; status: TaskStatus }) => {
      try {
        return await updateTaskStatus(token, id, status);
      } catch (error) {
        if (!isOfflineLikeError(error)) {
          throw error;
        }

        await enqueueAction({
          id: queueId('task-status'),
          kind: 'tasks.status',
          createdAt: new Date().toISOString(),
          payload: { taskId: id, status },
        });

        return null;
      }
    },
    onSuccess: async (result) => {
      await refreshTasks();
      Alert.alert('Task updated', result ? 'Task status changed.' : 'Task status queued for sync.');
    },
    onError: (error) => {
      Alert.alert('Task update failed', error instanceof Error ? error.message : 'Unable to update task.');
    },
  });

  const logMutation = useMutation({
    mutationFn: async ({ task, values }: { task: Task; values: TaskLogPayload }) => {
      if (!task.project_id) {
        throw new Error('This task is not linked to a project.');
      }

      try {
        await logTaskTime(token, {
          taskId: task.id,
          projectId: task.project_id,
          durationHours: Number(values.durationHours),
          description: values.description,
        });
        return 'saved' as const;
      } catch (error) {
        if (!isOfflineLikeError(error)) {
          throw error;
        }

        await enqueueAction({
          id: queueId('task-time'),
          kind: 'tasks.timeLog',
          createdAt: new Date().toISOString(),
          payload: {
            taskId: task.id,
            projectId: task.project_id,
            durationHours: Number(values.durationHours),
            description: values.description,
          },
        });

        return 'queued' as const;
      }
    },
    onSuccess: async (result) => {
      await refreshTasks();
      setSelectedTask(null);
      form.reset({ durationHours: '0.5', description: '' });
      Alert.alert('Time entry recorded', result === 'queued' ? 'The time log was queued for sync.' : 'The time log was saved.');
    },
    onError: (error) => {
      Alert.alert('Time log failed', error instanceof Error ? error.message : 'Unable to log time.');
    },
  });

  const submitLog = form.handleSubmit(async (values) => {
    if (!selectedTask) {
      return;
    }

    await logMutation.mutateAsync({
      task: selectedTask,
      values: {
        durationHours: Number(values.durationHours),
        description: values.description,
      },
    });
  });

  return (
    <SafeAreaView style={styles.safeArea}>
      <FlatList
        contentContainerStyle={styles.content}
        data={query.data ?? []}
        keyExtractor={(item) => String(item.id)}
        refreshControl={<RefreshControl refreshing={query.isRefetching} onRefresh={() => void query.refetch()} tintColor="#f0b23d" />}
        renderItem={({ item }) => (
          <View style={styles.card}>
            <Text style={styles.title}>{item.title}</Text>
            <Text style={styles.meta}>{item.status} | {item.priority}</Text>
            {item.project?.project_name ? <Text style={styles.project}>{item.project.project_name}</Text> : null}
            {item.description ? <Text style={styles.description}>{item.description}</Text> : null}
            {item.due_date ? <Text style={styles.project}>Due {item.due_date}</Text> : null}

            <View style={styles.row}>
              {nextActions(item).map((action) => (
                <Pressable
                  key={`${item.id}-${action.status}`}
                  onPress={() => void updateStatusMutation.mutateAsync({ id: item.id, status: action.status })}
                  style={styles.actionButton}
                >
                  <Text style={styles.actionText}>{action.label}</Text>
                </Pressable>
              ))}
              <Pressable onPress={() => {
                setSelectedTask(item);
                form.reset({
                  durationHours: '0.5',
                  description: `Worked on ${item.title.toLowerCase()}`,
                });
              }} style={styles.secondaryButton}>
                <Text style={styles.actionText}>Log time</Text>
              </Pressable>
            </View>
          </View>
        )}
      />

      <Modal animationType="slide" transparent visible={Boolean(selectedTask)} onRequestClose={() => setSelectedTask(null)}>
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>Log time</Text>
            <Text style={styles.modalSubtitle}>{selectedTask?.title}</Text>

            <Controller
              control={form.control}
              name="durationHours"
              render={({ field: { onChange, value } }) => (
                <View style={styles.field}>
                  <Text style={styles.label}>Hours</Text>
                  <TextInput
                    keyboardType="decimal-pad"
                    onChangeText={onChange}
                    placeholder="0.5"
                    placeholderTextColor="#7c8aa5"
                    style={styles.input}
                    value={String(value ?? '')}
                  />
                  {form.formState.errors.durationHours ? <Text style={styles.error}>{form.formState.errors.durationHours.message}</Text> : null}
                </View>
              )}
            />

            <Controller
              control={form.control}
              name="description"
              render={({ field: { onChange, value } }) => (
                <View style={styles.field}>
                  <Text style={styles.label}>Work note</Text>
                  <TextInput
                    multiline
                    onChangeText={onChange}
                    placeholder="What work did you complete?"
                    placeholderTextColor="#7c8aa5"
                    style={[styles.input, styles.textArea]}
                    value={value}
                  />
                  {form.formState.errors.description ? <Text style={styles.error}>{form.formState.errors.description.message}</Text> : null}
                </View>
              )}
            />

            <View style={styles.row}>
              <Pressable onPress={() => setSelectedTask(null)} style={styles.cancelButton}>
                <Text style={styles.actionText}>Cancel</Text>
              </Pressable>
              <Pressable onPress={() => void submitLog()} style={styles.actionButton}>
                <Text style={styles.actionText}>{logMutation.isPending ? 'Saving...' : 'Save log'}</Text>
              </Pressable>
            </View>
          </View>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#0d1321' },
  content: { flexGrow: 1, gap: 12, padding: 20 },
  card: { backgroundColor: '#131c31', borderColor: '#21304f', borderRadius: 18, borderWidth: 1, gap: 6, padding: 18 },
  title: { color: '#f8fafc', fontSize: 16, fontWeight: '700' },
  meta: { color: '#f0b23d', fontSize: 13, fontWeight: '600' },
  project: { color: '#9fb0d3', fontSize: 14 },
  description: { color: '#dbe4ff', fontSize: 14, lineHeight: 20 },
  row: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 8 },
  actionButton: { alignItems: 'center', backgroundColor: '#2864ff', borderRadius: 12, justifyContent: 'center', minHeight: 42, minWidth: 96, paddingHorizontal: 14 },
  secondaryButton: { alignItems: 'center', backgroundColor: '#2c4a24', borderRadius: 12, justifyContent: 'center', minHeight: 42, minWidth: 96, paddingHorizontal: 14 },
  cancelButton: { alignItems: 'center', backgroundColor: '#39445f', borderRadius: 12, flex: 1, justifyContent: 'center', minHeight: 44 },
  actionText: { color: '#fff', fontSize: 13, fontWeight: '700' },
  modalBackdrop: { backgroundColor: 'rgba(6, 10, 18, 0.8)', flex: 1, justifyContent: 'flex-end' },
  modalCard: { backgroundColor: '#131c31', borderTopLeftRadius: 24, borderTopRightRadius: 24, gap: 14, padding: 20 },
  modalTitle: { color: '#f8fafc', fontSize: 20, fontWeight: '700' },
  modalSubtitle: { color: '#9fb0d3', fontSize: 14 },
  field: { gap: 8 },
  label: { color: '#dbe4ff', fontSize: 14, fontWeight: '600' },
  input: { backgroundColor: '#0b1120', borderColor: '#2a3c61', borderRadius: 14, borderWidth: 1, color: '#f8fafc', fontSize: 15, paddingHorizontal: 14, paddingVertical: 12 },
  textArea: { minHeight: 110, textAlignVertical: 'top' },
  error: { color: '#fca5a5', fontSize: 13 },
});
