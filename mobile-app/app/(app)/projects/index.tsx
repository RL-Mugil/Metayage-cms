import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
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

import { createProject, getClients, getProjects, getUsers } from '../../../src/lib/api';
import { useAuth } from '../../../src/providers/auth-provider';
import type { Project } from '../../../src/types/api';

const STATUS_FILTERS = [
  { label: 'All',         value: '' },
  { label: 'Active',      value: 'Active' },
  { label: 'In Progress', value: 'In Progress' },
  { label: 'On Hold',     value: 'On Hold' },
  { label: 'Overdue',     value: '__overdue__' },
];

const PATENT_OFFICES = ['IN', 'US', 'EP', 'WO', 'AU', 'CA', 'JP', 'CN', 'KR'];
const PROJECT_TYPES  = ['Patent', 'Trademark', 'Copyright', 'Design', 'Trade Secret'];

const createSchema = z.object({
  client_id:          z.number({ required_error: 'Select a client.' }),
  project_name:       z.string().min(3, 'Project name is required.'),
  patent_office_code: z.string().min(1, 'Select a patent office.'),
  project_type:       z.string().min(1, 'Select project type.'),
  hard_deadline:      z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Use YYYY-MM-DD format.').optional().or(z.literal('')),
  assigned_partner_id:  z.number().optional(),
  assigned_manager_id:  z.number().optional(),
});

type CreateFormValues = z.infer<typeof createSchema>;

function deadlineLabel(dateStr?: string | null): { text: string; color: string } {
  if (!dateStr) return { text: '', color: '#94a3b8' };
  const days = Math.ceil((Date.parse(dateStr) - Date.now()) / 86_400_000);
  if (days < 0)  return { text: 'Overdue',        color: '#f87171' };
  if (days <= 7) return { text: `${days}d left`,  color: '#fbbf24' };
  return               { text: `${days}d left`,  color: '#94a3b8' };
}

function statusColor(status: string): string {
  switch (status) {
    case 'Active':      return '#16a34a';
    case 'In Progress': return '#2864ff';
    case 'On Hold':     return '#dc2626';
    case 'Completed':   return '#0d9488';
    case 'Closed':      return '#64748b';
    default:            return '#d97706';
  }
}

export default function ProjectsScreen() {
  const { session } = useAuth();
  const router = useRouter();
  const queryClient = useQueryClient();
  const token = session!.token;

  const [search, setSearch]           = useState('');
  const [activeFilter, setActiveFilter] = useState('');
  const [showCreate, setShowCreate]   = useState(false);
  const [clientSearch, setClientSearch] = useState('');

  const form = useForm<CreateFormValues>({
    resolver: zodResolver(createSchema),
    defaultValues: { project_name: '', patent_office_code: 'IN', project_type: 'Patent', hard_deadline: '' },
  });

  const overdue = activeFilter === '__overdue__';
  const statusFilter = overdue ? '' : activeFilter;

  const projectsQuery = useQuery({
    queryKey: ['projects', search, activeFilter],
    queryFn: () => getProjects(token, { search: search || undefined, status: statusFilter || undefined, overdue: overdue || undefined }),
    staleTime: 30_000,
  });

  const clientsQuery = useQuery({
    queryKey: ['clients', clientSearch],
    queryFn: () => getClients(token, { search: clientSearch || undefined, status: 'Active', per_page: 20 }),
    enabled: showCreate,
    staleTime: 60_000,
  });

  const usersQuery = useQuery({
    queryKey: ['users'],
    queryFn: () => getUsers(token),
    staleTime: 60_000,
  });

  const createMutation = useMutation({
    mutationFn: (values: CreateFormValues) =>
      createProject(token, {
        client_id: values.client_id,
        project_name: values.project_name,
        patent_office_code: values.patent_office_code,
        project_type: values.project_type,
        hard_deadline: values.hard_deadline || undefined,
        assigned_partner_id: values.assigned_partner_id,
        assigned_manager_id: values.assigned_manager_id,
        record_mode: 'new',
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['projects'] });
      setShowCreate(false);
      form.reset();
      Alert.alert('Project created', 'The new case has been added.');
    },
    onError: (error) => Alert.alert('Failed', error instanceof Error ? error.message : 'Could not create project.'),
  });

  const submitCreate = form.handleSubmit((values) => void createMutation.mutateAsync(values));

  const renderProject = ({ item }: { item: Project }) => {
    const dl = deadlineLabel(item.hard_deadline);
    const sc = statusColor(item.status);
    return (
      <Pressable style={[styles.card, item.hard_deadline && Date.parse(item.hard_deadline) < Date.now() && styles.cardOverdue]} onPress={() => router.push(`/projects/${item.id}`)}>
        <View style={styles.cardHeader}>
          <Text style={styles.docket}>{item.docket_number}</Text>
          <View style={[styles.badge, { backgroundColor: sc + '33', borderColor: sc }]}>
            <Text style={[styles.badgeText, { color: sc }]}>{item.status}</Text>
          </View>
        </View>
        <Text style={styles.name}>{item.project_name}</Text>
        <Text style={styles.clientName}>{item.client.company_name}</Text>
        <View style={styles.footer}>
          {item.manager ? <Text style={styles.meta}>{item.manager.name}</Text> : null}
          {dl.text ? <Text style={[styles.deadline, { color: dl.color }]}>{dl.text}</Text> : null}
        </View>
      </Pressable>
    );
  };

  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.searchRow}>
        <TextInput
          style={styles.search}
          placeholder="Search by docket, name, client…"
          placeholderTextColor="#7c8aa5"
          value={search}
          onChangeText={setSearch}
          returnKeyType="search"
        />
      </View>

      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.filterBar} contentContainerStyle={styles.filterContent}>
        {STATUS_FILTERS.map((f) => (
          <Pressable key={f.value} onPress={() => setActiveFilter(f.value)} style={[styles.chip, activeFilter === f.value && styles.chipActive]}>
            <Text style={[styles.chipText, activeFilter === f.value && styles.chipTextActive]}>{f.label}</Text>
          </Pressable>
        ))}
      </ScrollView>

      <FlatList
        data={projectsQuery.data?.data ?? []}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={styles.list}
        refreshControl={<RefreshControl refreshing={projectsQuery.isRefetching} onRefresh={() => void projectsQuery.refetch()} tintColor="#f0b23d" />}
        renderItem={renderProject}
        ListEmptyComponent={
          projectsQuery.isLoading
            ? <ActivityIndicator color="#f0b23d" style={{ marginTop: 40 }} />
            : <Text style={styles.empty}>No projects found.</Text>
        }
      />

      <Pressable style={styles.fab} onPress={() => setShowCreate(true)}>
        <Text style={styles.fabText}>+</Text>
      </Pressable>

      {/* Create Project Modal */}
      <Modal animationType="slide" transparent visible={showCreate} onRequestClose={() => setShowCreate(false)}>
        <View style={styles.backdrop}>
          <View style={styles.sheet}>
            <Text style={styles.sheetTitle}>New Project</Text>

            <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={{ gap: 14 }}>
              <Field label="Client *" error={form.formState.errors.client_id?.message}>
                <TextInput
                  style={styles.input}
                  placeholder="Search client…"
                  placeholderTextColor="#7c8aa5"
                  value={clientSearch}
                  onChangeText={setClientSearch}
                />
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingTop: 6 }}>
                  <Controller control={form.control} name="client_id" render={({ field }) => (
                    <>
                      {(clientsQuery.data?.data ?? []).map((c) => (
                        <Pressable key={c.id} onPress={() => field.onChange(c.id)} style={[styles.toggleOption, field.value === c.id && styles.toggleActive]}>
                          <Text style={[styles.toggleText, field.value === c.id && styles.toggleTextActive]}>{c.company_name || c.legal_name}</Text>
                        </Pressable>
                      ))}
                    </>
                  )} />
                </ScrollView>
              </Field>

              <Field label="Project name *" error={form.formState.errors.project_name?.message}>
                <Controller control={form.control} name="project_name" render={({ field }) => (
                  <TextInput style={styles.input} placeholder="e.g. Machine Learning Patent Filing" placeholderTextColor="#7c8aa5" value={field.value} onChangeText={field.onChange} />
                )} />
              </Field>

              <Field label="Patent office *" error={form.formState.errors.patent_office_code?.message}>
                <Controller control={form.control} name="patent_office_code" render={({ field }) => (
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                    {PATENT_OFFICES.map((o) => (
                      <Pressable key={o} onPress={() => field.onChange(o)} style={[styles.toggleOption, field.value === o && styles.toggleActive]}>
                        <Text style={[styles.toggleText, field.value === o && styles.toggleTextActive]}>{o}</Text>
                      </Pressable>
                    ))}
                  </ScrollView>
                )} />
              </Field>

              <Field label="Type *" error={form.formState.errors.project_type?.message}>
                <Controller control={form.control} name="project_type" render={({ field }) => (
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                    {PROJECT_TYPES.map((t) => (
                      <Pressable key={t} onPress={() => field.onChange(t)} style={[styles.toggleOption, field.value === t && styles.toggleActive]}>
                        <Text style={[styles.toggleText, field.value === t && styles.toggleTextActive]}>{t}</Text>
                      </Pressable>
                    ))}
                  </ScrollView>
                )} />
              </Field>

              <Field label="Hard deadline (YYYY-MM-DD)" error={form.formState.errors.hard_deadline?.message}>
                <Controller control={form.control} name="hard_deadline" render={({ field }) => (
                  <TextInput style={styles.input} placeholder="2026-12-31" placeholderTextColor="#7c8aa5" keyboardType="numeric" value={field.value} onChangeText={field.onChange} />
                )} />
              </Field>

              <Field label="Partner">
                <Controller control={form.control} name="assigned_partner_id" render={({ field }) => (
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                    {(usersQuery.data ?? []).filter((u) => u.role === 'partner').map((u) => (
                      <Pressable key={u.id} onPress={() => field.onChange(u.id)} style={[styles.toggleOption, field.value === u.id && styles.toggleActive]}>
                        <Text style={[styles.toggleText, field.value === u.id && styles.toggleTextActive]}>{u.name}</Text>
                      </Pressable>
                    ))}
                  </ScrollView>
                )} />
              </Field>

              <Field label="Manager">
                <Controller control={form.control} name="assigned_manager_id" render={({ field }) => (
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                    {(usersQuery.data ?? []).filter((u) => u.role === 'manager').map((u) => (
                      <Pressable key={u.id} onPress={() => field.onChange(u.id)} style={[styles.toggleOption, field.value === u.id && styles.toggleActive]}>
                        <Text style={[styles.toggleText, field.value === u.id && styles.toggleTextActive]}>{u.name}</Text>
                      </Pressable>
                    ))}
                  </ScrollView>
                )} />
              </Field>
            </ScrollView>

            <View style={[styles.row, { marginTop: 16 }]}>
              <Pressable style={styles.cancelBtn} onPress={() => { setShowCreate(false); form.reset(); setClientSearch(''); }}>
                <Text style={styles.btnText}>Cancel</Text>
              </Pressable>
              <Pressable style={styles.submitBtn} onPress={() => void submitCreate()}>
                <Text style={styles.btnText}>{createMutation.isPending ? 'Saving…' : 'Create project'}</Text>
              </Pressable>
            </View>
          </View>
        </View>
      </Modal>
    </SafeAreaView>
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
  safe:            { backgroundColor: '#0d1321', flex: 1 },
  searchRow:       { paddingHorizontal: 16, paddingTop: 12, paddingBottom: 4 },
  search:          { backgroundColor: '#131c31', borderColor: '#21304f', borderRadius: 12, borderWidth: 1, color: '#f8fafc', fontSize: 15, paddingHorizontal: 14, paddingVertical: 10 },
  filterBar:       { flexGrow: 0, marginBottom: 4 },
  filterContent:   { flexDirection: 'row', gap: 8, paddingHorizontal: 16, paddingVertical: 8 },
  chip:            { borderColor: '#2a3c61', borderRadius: 20, borderWidth: 1, paddingHorizontal: 14, paddingVertical: 6 },
  chipActive:      { backgroundColor: '#f0b23d22', borderColor: '#f0b23d' },
  chipText:        { color: '#94a3b8', fontSize: 13, fontWeight: '600' },
  chipTextActive:  { color: '#f0b23d' },
  list:            { gap: 12, padding: 16 },
  card:            { backgroundColor: '#131c31', borderColor: '#21304f', borderRadius: 16, borderWidth: 1, gap: 6, padding: 16 },
  cardOverdue:     { borderColor: '#7f1d1d' },
  cardHeader:      { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  docket:          { color: '#9fb0d3', fontFamily: 'monospace', fontSize: 13, fontWeight: '700' },
  badge:           { borderRadius: 8, borderWidth: 1, paddingHorizontal: 8, paddingVertical: 2 },
  badgeText:       { fontSize: 12, fontWeight: '700' },
  name:            { color: '#f8fafc', fontSize: 15, fontWeight: '700' },
  clientName:      { color: '#9fb0d3', fontSize: 13 },
  footer:          { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginTop: 4 },
  meta:            { color: '#7c8aa5', fontSize: 12 },
  deadline:        { fontSize: 12, fontWeight: '700' },
  empty:           { color: '#94a3b8', marginTop: 40, textAlign: 'center' },
  fab:             { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 28, bottom: 24, elevation: 6, height: 56, justifyContent: 'center', position: 'absolute', right: 24, shadowColor: '#f0b23d', shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.4, shadowRadius: 8, width: 56 },
  fabText:         { color: '#0d1321', fontSize: 28, fontWeight: '700', lineHeight: 32 },
  backdrop:        { backgroundColor: 'rgba(6,10,18,0.85)', flex: 1, justifyContent: 'flex-end' },
  sheet:           { backgroundColor: '#131c31', borderTopLeftRadius: 24, borderTopRightRadius: 24, gap: 4, maxHeight: '92%', padding: 20 },
  sheetTitle:      { color: '#f8fafc', fontSize: 20, fontWeight: '700', marginBottom: 12 },
  input:           { backgroundColor: '#0b1120', borderColor: '#2a3c61', borderRadius: 12, borderWidth: 1, color: '#f8fafc', fontSize: 15, paddingHorizontal: 14, paddingVertical: 11 },
  row:             { flexDirection: 'row', gap: 10 },
  toggleOption:    { borderColor: '#2a3c61', borderRadius: 10, borderWidth: 1, paddingHorizontal: 14, paddingVertical: 8 },
  toggleActive:    { backgroundColor: '#f0b23d22', borderColor: '#f0b23d' },
  toggleText:      { color: '#94a3b8', fontSize: 13, fontWeight: '600' },
  toggleTextActive:{ color: '#f0b23d' },
  fieldLabel:      { color: '#dbe4ff', fontSize: 14, fontWeight: '600' },
  fieldError:      { color: '#fca5a5', fontSize: 13 },
  cancelBtn:       { alignItems: 'center', backgroundColor: '#39445f', borderRadius: 12, flex: 1, justifyContent: 'center', paddingVertical: 13 },
  submitBtn:       { alignItems: 'center', backgroundColor: '#2864ff', borderRadius: 12, flex: 2, justifyContent: 'center', paddingVertical: 13 },
  btnText:         { color: '#fff', fontSize: 14, fontWeight: '700' },
});
