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
  { label: 'Completed',   value: 'Completed' },
];

const PATENT_OFFICES = ['IN', 'US', 'EP', 'WO', 'AU', 'CA', 'JP', 'CN', 'KR'];
const PROJECT_TYPES  = ['Patent', 'Trademark', 'Copyright', 'Design', 'Trade Secret'];

const createSchema = z.object({
  client_id:            z.number({ required_error: 'Select a client.' }),
  project_name:         z.string().min(3, 'Project name is required.'),
  patent_office_code:   z.string().min(1, 'Select a patent office.'),
  project_type:         z.string().min(1, 'Select project type.'),
  hard_deadline:        z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Use YYYY-MM-DD.').optional().or(z.literal('')),
  assigned_partner_id:  z.number().optional(),
  assigned_manager_id:  z.number().optional(),
});

type CreateFormValues = z.infer<typeof createSchema>;

function deadlineInfo(dateStr?: string | null): { text: string; color: string; isOverdue: boolean } {
  if (!dateStr) return { text: '', color: '#475569', isOverdue: false };
  const days = Math.ceil((Date.parse(dateStr) - Date.now()) / 86_400_000);
  if (days < 0) return { text: `${Math.abs(days)}d overdue`, color: '#ef4444', isOverdue: true };
  if (days <= 7) return { text: `${days}d left`, color: '#f59e0b', isOverdue: false };
  if (days <= 30) return { text: `${days}d left`, color: '#f0b23d', isOverdue: false };
  return { text: `${days}d left`, color: '#475569', isOverdue: false };
}

const STATUS_META: Record<string, { color: string; bg: string }> = {
  Active:       { color: '#22c55e', bg: 'rgba(34,197,94,0.1)' },
  'In Progress':{ color: '#3b82f6', bg: 'rgba(59,130,246,0.1)' },
  'On Hold':    { color: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
  Completed:    { color: '#14b8a6', bg: 'rgba(20,184,166,0.1)' },
  Closed:       { color: '#64748b', bg: 'rgba(100,116,139,0.1)' },
  Pending:      { color: '#f59e0b', bg: 'rgba(245,158,11,0.1)' },
};

export default function ProjectsScreen() {
  const { session } = useAuth();
  const router = useRouter();
  const queryClient = useQueryClient();
  const token = session!.token;

  const [search, setSearch] = useState('');
  const [activeFilter, setActiveFilter] = useState('');
  const [showCreate, setShowCreate] = useState(false);
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
      Alert.alert('Project created', 'New case has been added.');
    },
    onError: (e) => Alert.alert('Failed', e instanceof Error ? e.message : 'Could not create project.'),
  });

  const submitCreate = form.handleSubmit((values) => void createMutation.mutateAsync(values));

  const renderProject = ({ item }: { item: Project }) => {
    const dl = deadlineInfo(item.hard_deadline);
    const meta = STATUS_META[item.status] ?? { color: '#f59e0b', bg: 'rgba(245,158,11,0.1)' };

    return (
      <Pressable
        style={({ pressed }) => [s.card, dl.isOverdue && s.cardOverdue, pressed && s.cardPressed]}
        onPress={() => router.push(`/projects/${item.id}`)}
      >
        {dl.isOverdue && <View style={s.overdueStripe} />}
        <View style={s.cardContent}>
          <View style={s.cardTop}>
            <Text style={s.docket}>{item.docket_number}</Text>
            <View style={[s.statusBadge, { backgroundColor: meta.bg }]}>
              <Text style={[s.statusText, { color: meta.color }]}>{item.status}</Text>
            </View>
          </View>
          <Text style={s.projectName} numberOfLines={2}>{item.project_name}</Text>
          <Text style={s.clientName}>{item.client.company_name}</Text>
          <View style={s.cardFooter}>
            {item.manager ? <Text style={s.managerName}>{item.manager.name}</Text> : null}
            {dl.text ? (
              <View style={[s.deadlineBadge, { backgroundColor: dl.color + '1a' }]}>
                <Text style={[s.deadlineText, { color: dl.color }]}>{dl.text}</Text>
              </View>
            ) : null}
          </View>
        </View>
      </Pressable>
    );
  };

  return (
    <SafeAreaView style={s.safe}>
      <View style={s.searchRow}>
        <TextInput
          style={s.search}
          placeholder="Search by docket, name, client…"
          placeholderTextColor="#334155"
          value={search}
          onChangeText={setSearch}
          returnKeyType="search"
        />
      </View>

      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={s.filterBar} contentContainerStyle={s.filterContent}>
        {STATUS_FILTERS.map((f) => (
          <Pressable key={f.value} onPress={() => setActiveFilter(f.value)} style={[s.chip, activeFilter === f.value && s.chipActive]}>
            <Text style={[s.chipText, activeFilter === f.value && s.chipTextActive]}>{f.label}</Text>
          </Pressable>
        ))}
      </ScrollView>

      <FlatList
        data={projectsQuery.data?.data ?? []}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={s.list}
        refreshControl={<RefreshControl refreshing={projectsQuery.isRefetching} onRefresh={() => void projectsQuery.refetch()} tintColor="#f0b23d" />}
        renderItem={renderProject}
        ListEmptyComponent={
          projectsQuery.isLoading
            ? <ActivityIndicator color="#f0b23d" style={{ marginTop: 48 }} />
            : <Text style={s.empty}>No projects found.</Text>
        }
      />

      <Pressable style={s.fab} onPress={() => setShowCreate(true)}>
        <Text style={s.fabText}>+</Text>
      </Pressable>

      <Modal animationType="slide" transparent visible={showCreate} onRequestClose={() => setShowCreate(false)}>
        <Pressable style={s.backdrop} onPress={() => setShowCreate(false)} />
        <View style={s.sheet}>
          <View style={s.sheetHandle} />
          <Text style={s.sheetTitle}>New Project</Text>
          <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={{ gap: 16, paddingBottom: 20 }}>
            <Field label="Client *" error={form.formState.errors.client_id?.message}>
              <TextInput style={s.input} placeholder="Search client…" placeholderTextColor="#334155" value={clientSearch} onChangeText={setClientSearch} />
              <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingTop: 6 }}>
                <Controller control={form.control} name="client_id" render={({ field }) => (
                  <>
                    {(clientsQuery.data?.data ?? []).map((c) => (
                      <Pressable key={c.id} onPress={() => field.onChange(c.id)} style={[s.toggleOpt, field.value === c.id && s.toggleActive]}>
                        <Text style={[s.toggleText, field.value === c.id && s.toggleActiveText]}>{c.company_name || c.legal_name}</Text>
                      </Pressable>
                    ))}
                  </>
                )} />
              </ScrollView>
            </Field>
            <Field label="Project name *" error={form.formState.errors.project_name?.message}>
              <Controller control={form.control} name="project_name" render={({ field }) => (
                <TextInput style={s.input} placeholder="e.g. ML Patent Filing" placeholderTextColor="#334155" value={field.value} onChangeText={field.onChange} />
              )} />
            </Field>
            <Field label="Patent office *" error={form.formState.errors.patent_office_code?.message}>
              <Controller control={form.control} name="patent_office_code" render={({ field }) => (
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                  {PATENT_OFFICES.map((o) => (
                    <Pressable key={o} onPress={() => field.onChange(o)} style={[s.toggleOpt, field.value === o && s.toggleActive]}>
                      <Text style={[s.toggleText, field.value === o && s.toggleActiveText]}>{o}</Text>
                    </Pressable>
                  ))}
                </ScrollView>
              )} />
            </Field>
            <Field label="Type *" error={form.formState.errors.project_type?.message}>
              <Controller control={form.control} name="project_type" render={({ field }) => (
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                  {PROJECT_TYPES.map((t) => (
                    <Pressable key={t} onPress={() => field.onChange(t)} style={[s.toggleOpt, field.value === t && s.toggleActive]}>
                      <Text style={[s.toggleText, field.value === t && s.toggleActiveText]}>{t}</Text>
                    </Pressable>
                  ))}
                </ScrollView>
              )} />
            </Field>
            <Field label="Hard deadline (YYYY-MM-DD)" error={form.formState.errors.hard_deadline?.message}>
              <Controller control={form.control} name="hard_deadline" render={({ field }) => (
                <TextInput style={s.input} placeholder="2026-12-31" placeholderTextColor="#334155" keyboardType="numeric" value={field.value} onChangeText={field.onChange} />
              )} />
            </Field>
            <Field label="Partner">
              <Controller control={form.control} name="assigned_partner_id" render={({ field }) => (
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                  {(usersQuery.data ?? []).filter((u) => u.role === 'partner').map((u) => (
                    <Pressable key={u.id} onPress={() => field.onChange(u.id)} style={[s.toggleOpt, field.value === u.id && s.toggleActive]}>
                      <Text style={[s.toggleText, field.value === u.id && s.toggleActiveText]}>{u.name}</Text>
                    </Pressable>
                  ))}
                </ScrollView>
              )} />
            </Field>
            <Field label="Manager">
              <Controller control={form.control} name="assigned_manager_id" render={({ field }) => (
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                  {(usersQuery.data ?? []).filter((u) => u.role === 'manager').map((u) => (
                    <Pressable key={u.id} onPress={() => field.onChange(u.id)} style={[s.toggleOpt, field.value === u.id && s.toggleActive]}>
                      <Text style={[s.toggleText, field.value === u.id && s.toggleActiveText]}>{u.name}</Text>
                    </Pressable>
                  ))}
                </ScrollView>
              )} />
            </Field>
            <View style={s.btnRow}>
              <Pressable style={s.cancelBtn} onPress={() => { setShowCreate(false); form.reset(); setClientSearch(''); }}>
                <Text style={s.cancelBtnText}>Cancel</Text>
              </Pressable>
              <Pressable style={[s.submitBtn, createMutation.isPending && { opacity: 0.6 }]} onPress={() => void submitCreate()}>
                <Text style={s.submitBtnText}>{createMutation.isPending ? 'Saving…' : 'Create Project'}</Text>
              </Pressable>
            </View>
          </ScrollView>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return (
    <View style={{ gap: 8 }}>
      <Text style={s.fieldLabel}>{label}</Text>
      {children}
      {error ? <Text style={s.fieldError}>{error}</Text> : null}
    </View>
  );
}

const s = StyleSheet.create({
  safe:           { backgroundColor: '#040d1a', flex: 1 },
  searchRow:      { paddingHorizontal: 16, paddingTop: 14, paddingBottom: 6 },
  search:         { backgroundColor: '#0b1829', borderColor: 'rgba(100,160,255,0.15)', borderRadius: 14, borderWidth: 1, color: '#f1f5f9', fontSize: 15, paddingHorizontal: 16, paddingVertical: 13 },
  filterBar:      { flexGrow: 0 },
  filterContent:  { flexDirection: 'row', gap: 8, paddingHorizontal: 16, paddingVertical: 10 },
  chip:           { backgroundColor: 'rgba(255,255,255,0.04)', borderColor: 'rgba(255,255,255,0.1)', borderRadius: 100, borderWidth: 1, flexShrink: 0, paddingHorizontal: 18, paddingVertical: 10 },
  chipActive:     { backgroundColor: '#f0b23d', borderColor: '#f0b23d' },
  chipText:       { color: 'rgba(255,255,255,0.4)', fontSize: 14, fontWeight: '600' },
  chipTextActive: { color: '#040d1a', fontWeight: '800' },
  list:           { gap: 10, padding: 16, paddingBottom: 100 },

  card:           { backgroundColor: '#0b1829', borderColor: 'rgba(100,160,255,0.1)', borderRadius: 20, borderTopColor: 'rgba(255,255,255,0.08)', borderWidth: 1, overflow: 'hidden', shadowColor: '#000', shadowOffset: { width: 0, height: 6 }, shadowOpacity: 0.35, shadowRadius: 14, elevation: 6 },
  cardOverdue:    { borderColor: 'rgba(239,68,68,0.3)' },
  cardPressed:    { transform: [{ scale: 0.98 }], opacity: 0.85 },
  overdueStripe:  { backgroundColor: '#ef4444', height: 3 },
  cardContent:    { gap: 7, padding: 16 },

  cardTop:        { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  docket:         { color: '#334155', fontFamily: 'monospace', fontSize: 12, fontWeight: '700' },
  statusBadge:    { borderRadius: 8, paddingHorizontal: 10, paddingVertical: 4 },
  statusText:     { fontSize: 11, fontWeight: '800', letterSpacing: 0.3 },
  projectName:    { color: '#f1f5f9', fontSize: 15, fontWeight: '700', lineHeight: 21 },
  clientName:     { color: '#475569', fontSize: 13 },
  cardFooter:     { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginTop: 4 },
  managerName:    { color: '#334155', fontSize: 12 },
  deadlineBadge:  { borderRadius: 8, paddingHorizontal: 10, paddingVertical: 4 },
  deadlineText:   { fontSize: 12, fontWeight: '700' },

  empty:          { color: '#334155', marginTop: 48, textAlign: 'center' },
  fab:            { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 30, bottom: 28, elevation: 8, height: 60, justifyContent: 'center', position: 'absolute', right: 20, shadowColor: '#f0b23d', shadowOffset: { width: 0, height: 6 }, shadowOpacity: 0.45, shadowRadius: 14, width: 60 },
  fabText:        { color: '#040d1a', fontSize: 30, fontWeight: '700', lineHeight: 34 },

  backdrop:       { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(2,8,22,0.85)' },
  sheet:          { backgroundColor: '#0b1829', borderColor: 'rgba(100,160,255,0.12)', borderTopLeftRadius: 28, borderTopRightRadius: 28, borderWidth: 1, bottom: 0, left: 0, maxHeight: '92%', padding: 24, paddingBottom: 0, position: 'absolute', right: 0 },
  sheetHandle:    { alignSelf: 'center', backgroundColor: '#1e3a5f', borderRadius: 3, height: 4, marginBottom: 20, width: 40 },
  sheetTitle:     { color: '#f1f5f9', fontSize: 22, fontWeight: '800', marginBottom: 20 },

  fieldLabel:     { color: '#64748b', fontSize: 11, fontWeight: '700', letterSpacing: 0.8, textTransform: 'uppercase' },
  input:          { backgroundColor: '#0d1e38', borderColor: 'rgba(100,160,255,0.15)', borderRadius: 14, borderWidth: 1, color: '#f1f5f9', fontSize: 15, paddingHorizontal: 16, paddingVertical: 13 },
  fieldError:     { color: '#f87171', fontSize: 12 },
  toggleOpt:      { backgroundColor: '#0d1e38', borderColor: 'rgba(100,160,255,0.15)', borderRadius: 12, borderWidth: 1, flexShrink: 0, paddingHorizontal: 16, paddingVertical: 10 },
  toggleActive:   { backgroundColor: '#f0b23d', borderColor: '#f0b23d' },
  toggleText:     { color: '#475569', fontSize: 13, fontWeight: '600' },
  toggleActiveText:{ color: '#040d1a', fontWeight: '800' },
  btnRow:         { flexDirection: 'row', gap: 10, paddingVertical: 20 },
  cancelBtn:      { alignItems: 'center', backgroundColor: '#0d1e38', borderRadius: 14, flex: 1, justifyContent: 'center', paddingVertical: 14 },
  cancelBtnText:  { color: '#475569', fontSize: 15, fontWeight: '700' },
  submitBtn:      { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 14, flex: 2, justifyContent: 'center', paddingVertical: 14 },
  submitBtnText:  { color: '#040d1a', fontSize: 15, fontWeight: '800' },
});
