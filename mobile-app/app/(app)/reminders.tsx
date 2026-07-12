import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Controller, useForm } from 'react-hook-form';
import { ActivityIndicator, Alert, FlatList, Pressable, RefreshControl, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { z } from 'zod';

import {
  createReminder,
  deleteReminder,
  getReminders,
  getUsers,
  requestReminderHelp,
  updateReminderCompletion,
} from '../../src/lib/api';
import { useAuth } from '../../src/providers/auth-provider';
import type { ReminderCategory, ReminderPayload } from '../../src/types/api';

const categories: ReminderCategory[] = ['Deadline', 'Meeting', 'Follow-up', 'Renewal'];

const reminderSchema = z.object({
  title: z.string().min(3, 'Title is too short.').max(255, 'Title is too long.'),
  description: z.string().max(500, 'Keep the description brief.').optional(),
  category: z.enum(categories),
  due_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Use YYYY-MM-DD.'),
  due_time: z.string().optional(),
  scope: z.enum(['self', 'team']),
});

export default function RemindersScreen() {
  const { session } = useAuth();
  const queryClient = useQueryClient();
  const token = session!.token;
  const form = useForm<ReminderPayload>({
    resolver: zodResolver(reminderSchema),
    defaultValues: {
      title: '',
      description: '',
      category: 'Deadline',
      due_date: '',
      due_time: '',
      scope: 'self',
    },
  });

  const remindersQuery = useQuery({
    queryKey: ['reminders'],
    queryFn: () => getReminders(token),
  });
  const usersQuery = useQuery({
    queryKey: ['users'],
    queryFn: () => getUsers(token),
  });

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['reminders'] });
    await queryClient.invalidateQueries({ queryKey: ['notifications'] });
  };

  const createMutation = useMutation({
    mutationFn: (values: ReminderPayload) => createReminder(token, values),
    onSuccess: async () => {
      form.reset({
        title: '',
        description: '',
        category: 'Deadline',
        due_date: '',
        due_time: '',
        scope: 'self',
      });
      await refresh();
    },
    onError: (error) => {
      Alert.alert('Reminder creation failed', error instanceof Error ? error.message : 'Unable to create reminder.');
    },
  });

  const toggleMutation = useMutation({
    mutationFn: ({ id, completed }: { id: number; completed: boolean }) => updateReminderCompletion(token, id, completed),
    onSuccess: async () => {
      await refresh();
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteReminder(token, id),
    onSuccess: async () => {
      await refresh();
    },
  });

  const helpMutation = useMutation({
    mutationFn: ({ id, targetUserId }: { id: number; targetUserId: number }) => requestReminderHelp(token, id, targetUserId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['notifications'] });
      Alert.alert('Help requested', 'A teammate has been notified.');
    },
  });

  const teammate = (usersQuery.data ?? []).find((item) => item.id !== session?.user.id);
  const submit = form.handleSubmit(async (values) => {
    await createMutation.mutateAsync({
      ...values,
      description: values.description?.trim() || undefined,
      due_time: values.due_time?.trim() || undefined,
    });
  });

  return (
    <SafeAreaView style={styles.safeArea}>
      <FlatList
        contentContainerStyle={styles.content}
        data={remindersQuery.data ?? []}
        keyExtractor={(item) => `reminder-${item.id}`}
        refreshControl={<RefreshControl refreshing={remindersQuery.isRefetching} onRefresh={() => void remindersQuery.refetch()} tintColor="#f0b23d" />}
        ListHeaderComponent={
          <View style={styles.formCard}>
            <Text style={styles.formTitle}>New reminder</Text>

            <Controller
              control={form.control}
              name="title"
              render={({ field: { onChange, value } }) => (
                <View style={styles.field}>
                  <Text style={styles.label}>Title</Text>
                  <TextInput onChangeText={onChange} placeholder="Reminder title" placeholderTextColor="#7c8aa5" style={styles.input} value={value} />
                  {form.formState.errors.title ? <Text style={styles.error}>{form.formState.errors.title.message}</Text> : null}
                </View>
              )}
            />

            <Controller
              control={form.control}
              name="description"
              render={({ field: { onChange, value } }) => (
                <View style={styles.field}>
                  <Text style={styles.label}>Description</Text>
                  <TextInput multiline onChangeText={onChange} placeholder="Optional context" placeholderTextColor="#7c8aa5" style={[styles.input, styles.textArea]} value={value} />
                </View>
              )}
            />

            <View style={styles.dualRow}>
              <Controller
                control={form.control}
                name="due_date"
                render={({ field: { onChange, value } }) => (
                  <View style={[styles.field, styles.flexField]}>
                    <Text style={styles.label}>Due date</Text>
                    <TextInput onChangeText={onChange} placeholder="YYYY-MM-DD" placeholderTextColor="#7c8aa5" style={styles.input} value={value} />
                    {form.formState.errors.due_date ? <Text style={styles.error}>{form.formState.errors.due_date.message}</Text> : null}
                  </View>
                )}
              />

              <Controller
                control={form.control}
                name="due_time"
                render={({ field: { onChange, value } }) => (
                  <View style={[styles.field, styles.flexField]}>
                    <Text style={styles.label}>Due time</Text>
                    <TextInput onChangeText={onChange} placeholder="HH:MM" placeholderTextColor="#7c8aa5" style={styles.input} value={value} />
                  </View>
                )}
              />
            </View>

            <Controller
              control={form.control}
              name="category"
              render={({ field: { onChange, value } }) => (
                <View style={styles.field}>
                  <Text style={styles.label}>Category</Text>
                  <View style={styles.categoryRow}>
                    {categories.map((item) => (
                      <Pressable key={item} onPress={() => onChange(item)} style={[styles.categoryChip, value === item && styles.activeChip]}>
                        <Text style={styles.categoryText}>{item}</Text>
                      </Pressable>
                    ))}
                  </View>
                </View>
              )}
            />

            <Controller
              control={form.control}
              name="scope"
              render={({ field: { onChange, value } }) => (
                <View style={styles.field}>
                  <Text style={styles.label}>Visibility</Text>
                  <View style={styles.categoryRow}>
                    {(['self', 'team'] as const).map((item) => (
                      <Pressable key={item} onPress={() => onChange(item)} style={[styles.categoryChip, value === item && styles.activeChip]}>
                        <Text style={styles.categoryText}>{item === 'self' ? 'Only me' : 'Team'}</Text>
                      </Pressable>
                    ))}
                  </View>
                </View>
              )}
            />

            <Pressable disabled={createMutation.isPending} onPress={() => void submit()} style={styles.createButton}>
              {createMutation.isPending ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Create reminder</Text>}
            </Pressable>
          </View>
        }
        renderItem={({ item }) => (
          <View style={styles.card}>
            <Text style={styles.title}>{item.title}</Text>
            <Text style={styles.meta}>{item.category} | {item.section} | {item.dueDate}{item.dueTime ? ` ${item.dueTime}` : ''}</Text>
            {item.description ? <Text style={styles.body}>{item.description}</Text> : null}
            <View style={styles.actions}>
              <Pressable onPress={() => void toggleMutation.mutateAsync({ id: item.id, completed: !item.completed })} style={styles.completeButton}>
                <Text style={styles.buttonText}>{item.completed ? 'Reopen' : 'Complete'}</Text>
              </Pressable>
              {teammate ? (
                <Pressable onPress={() => void helpMutation.mutateAsync({ id: item.id, targetUserId: teammate.id })} style={styles.helpButton}>
                  <Text style={styles.buttonText}>Request help</Text>
                </Pressable>
              ) : null}
              <Pressable onPress={() => void deleteMutation.mutateAsync(item.id)} style={styles.deleteButton}>
                <Text style={styles.buttonText}>Delete</Text>
              </Pressable>
            </View>
          </View>
        )}
      />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#0d1321' },
  content: { gap: 12, padding: 20 },
  formCard: { backgroundColor: '#131c31', borderColor: '#21304f', borderRadius: 20, borderWidth: 1, gap: 12, marginBottom: 16, padding: 20 },
  formTitle: { color: '#f8fafc', fontSize: 18, fontWeight: '700' },
  field: { gap: 8 },
  flexField: { flex: 1 },
  label: { color: '#dbe4ff', fontSize: 14, fontWeight: '600' },
  input: { backgroundColor: '#0b1120', borderColor: '#2a3c61', borderRadius: 14, borderWidth: 1, color: '#f8fafc', fontSize: 15, paddingHorizontal: 14, paddingVertical: 12 },
  textArea: { minHeight: 90, textAlignVertical: 'top' },
  dualRow: { flexDirection: 'row', gap: 12 },
  categoryRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  categoryChip: { backgroundColor: '#0b1120', borderColor: '#2a3c61', borderRadius: 999, borderWidth: 1, paddingHorizontal: 12, paddingVertical: 8 },
  activeChip: { backgroundColor: '#2864ff', borderColor: '#2864ff' },
  categoryText: { color: '#fff', fontSize: 13, fontWeight: '600' },
  createButton: { alignItems: 'center', backgroundColor: '#2864ff', borderRadius: 14, justifyContent: 'center', minHeight: 48 },
  error: { color: '#fca5a5', fontSize: 13 },
  card: { backgroundColor: '#131c31', borderColor: '#21304f', borderRadius: 18, borderWidth: 1, gap: 7, padding: 18 },
  title: { color: '#f8fafc', fontSize: 16, fontWeight: '700' },
  meta: { color: '#f0b23d', fontSize: 13, fontWeight: '700' },
  body: { color: '#dbe4ff', fontSize: 14, lineHeight: 20 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 8 },
  completeButton: { alignItems: 'center', backgroundColor: '#2c4a24', borderRadius: 12, justifyContent: 'center', minHeight: 40, paddingHorizontal: 14 },
  helpButton: { alignItems: 'center', backgroundColor: '#2864ff', borderRadius: 12, justifyContent: 'center', minHeight: 40, paddingHorizontal: 14 },
  deleteButton: { alignItems: 'center', backgroundColor: '#8b1e3f', borderRadius: 12, justifyContent: 'center', minHeight: 40, paddingHorizontal: 14 },
  buttonText: { color: '#fff', fontSize: 13, fontWeight: '700' },
});
