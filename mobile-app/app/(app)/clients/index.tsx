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

import { createClient, getClients, getUsers } from '../../../src/lib/api';
import { useAuth } from '../../../src/providers/auth-provider';
import type { Client, ClientStatus } from '../../../src/types/api';

const STATUS_FILTERS = [
  { label: 'All', value: '' },
  { label: 'Active', value: 'Active' },
  { label: 'Prospect', value: 'Prospect' },
  { label: 'Inactive', value: 'Inactive' },
  { label: 'On Hold', value: 'On Hold' },
];

const STATUS_META: Record<ClientStatus, { color: string; bg: string }> = {
  Active:   { color: '#22c55e', bg: 'rgba(34,197,94,0.1)' },
  Inactive: { color: '#64748b', bg: 'rgba(100,116,139,0.1)' },
  Prospect: { color: '#f0b23d', bg: 'rgba(240,178,61,0.1)' },
  'On Hold':{ color: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
};

const GST_COLORS: Record<string, string> = {
  B2B: '#3b82f6', B2C: '#a855f7', Export: '#14b8a6', Unregistered: '#64748b',
};

const createSchema = z.object({
  legal_name: z.string().min(2, 'Legal name is required.'),
  company_name: z.string().optional(),
  client_type: z.enum(['individual', 'organization']),
  nationality: z.string().min(1, 'Select nationality.'),
  contact_name: z.string().optional(),
  contact_email: z.string().email('Enter a valid email.').optional().or(z.literal('')),
  phone: z.string().optional(),
  account_manager_id: z.number().optional(),
});

type CreateFormValues = z.infer<typeof createSchema>;

function avatarLetter(name: string): string {
  return (name.trim()[0] ?? '?').toUpperCase();
}

function avatarColor(name: string): string {
  const colors = ['#3b82f6', '#a855f7', '#14b8a6', '#f0b23d', '#ef4444', '#22c55e', '#fb923c'];
  let h = 0;
  for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) % colors.length;
  return colors[h];
}

export default function ClientsScreen() {
  const { session } = useAuth();
  const router = useRouter();
  const queryClient = useQueryClient();
  const token = session!.token;

  const [search, setSearch] = useState('');
  const [activeFilter, setActiveFilter] = useState('');
  const [showCreate, setShowCreate] = useState(false);

  const form = useForm<CreateFormValues>({
    resolver: zodResolver(createSchema),
    defaultValues: { legal_name: '', company_name: '', client_type: 'organization', nationality: 'India', contact_name: '', contact_email: '', phone: '' },
  });

  const clientsQuery = useQuery({
    queryKey: ['clients', search, activeFilter],
    queryFn: () => getClients(token, { search: search || undefined, status: activeFilter || undefined }),
    staleTime: 30_000,
  });

  const usersQuery = useQuery({
    queryKey: ['users'],
    queryFn: () => getUsers(token),
    staleTime: 60_000,
  });

  const createMutation = useMutation({
    mutationFn: (values: CreateFormValues) =>
      createClient(token, {
        legal_name: values.legal_name,
        company_name: values.company_name || undefined,
        client_type: values.client_type,
        nationality: values.nationality,
        contact_name: values.contact_name || undefined,
        contact_email: values.contact_email || undefined,
        phone: values.phone || undefined,
        account_manager_id: values.account_manager_id,
        status: 'Active',
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['clients'] });
      setShowCreate(false);
      form.reset();
      Alert.alert('Client created', 'New client has been added.');
    },
    onError: (e) => Alert.alert('Failed', e instanceof Error ? e.message : 'Could not create client.'),
  });

  const submitCreate = form.handleSubmit((values) => void createMutation.mutateAsync(values));

  const renderClient = ({ item }: { item: Client }) => {
    const displayName = item.company_name || item.legal_name;
    const meta = STATUS_META[item.status] ?? { color: '#64748b', bg: 'rgba(100,116,139,0.1)' };
    const gstColor = GST_COLORS[item.gst_type] ?? '#64748b';
    const avatarBg = avatarColor(displayName);

    return (
      <Pressable
        style={({ pressed }) => [s.card, pressed && s.cardPressed]}
        onPress={() => router.push(`/clients/${item.id}`)}
      >
        <View style={[s.cardAvatar, { backgroundColor: avatarBg + '22' }]}>
          <Text style={[s.avatarText, { color: avatarBg }]}>{avatarLetter(displayName)}</Text>
        </View>
        <View style={s.cardBody}>
          <View style={s.cardTop}>
            <Text style={s.clientName} numberOfLines={1}>{displayName}</Text>
            <View style={[s.statusBadge, { backgroundColor: meta.bg }]}>
              <Text style={[s.statusText, { color: meta.color }]}>{item.status}</Text>
            </View>
          </View>
          {item.company_name && item.legal_name !== item.company_name
            ? <Text style={s.legalName} numberOfLines={1}>{item.legal_name}</Text>
            : null}
          <View style={s.cardMeta}>
            <Text style={s.codeText}>{item.client_code}</Text>
            <View style={[s.gstTag, { backgroundColor: gstColor + '1a' }]}>
              <Text style={[s.gstText, { color: gstColor }]}>{item.gst_type}</Text>
            </View>
            {item.account_manager ? <Text style={s.manager}>{item.account_manager.name}</Text> : null}
          </View>
          {item.contact_email ? <Text style={s.email} numberOfLines={1}>{item.contact_email}</Text> : null}
        </View>
        <Text style={s.chevron}>›</Text>
      </Pressable>
    );
  };

  return (
    <SafeAreaView style={s.safe}>
      {/* Search */}
      <View style={s.searchRow}>
        <TextInput
          style={s.search}
          placeholder="Search by name, code, email…"
          placeholderTextColor="#334155"
          value={search}
          onChangeText={setSearch}
          returnKeyType="search"
        />
      </View>

      {/* Filters */}
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={s.filterBar} contentContainerStyle={s.filterContent}>
        {STATUS_FILTERS.map((f) => (
          <Pressable key={f.value} onPress={() => setActiveFilter(f.value)} style={[s.chip, activeFilter === f.value && s.chipActive]}>
            <Text style={[s.chipText, activeFilter === f.value && s.chipTextActive]}>{f.label}</Text>
          </Pressable>
        ))}
      </ScrollView>

      {/* List */}
      <FlatList
        data={clientsQuery.data?.data ?? []}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={s.list}
        refreshControl={<RefreshControl refreshing={clientsQuery.isRefetching} onRefresh={() => void clientsQuery.refetch()} tintColor="#f0b23d" />}
        renderItem={renderClient}
        ListEmptyComponent={
          clientsQuery.isLoading
            ? <ActivityIndicator color="#f0b23d" style={{ marginTop: 48 }} />
            : <Text style={s.empty}>No clients found.</Text>
        }
      />

      {/* FAB */}
      <Pressable style={s.fab} onPress={() => setShowCreate(true)}>
        <Text style={s.fabText}>+</Text>
      </Pressable>

      {/* Create Modal */}
      <Modal animationType="slide" transparent visible={showCreate} onRequestClose={() => setShowCreate(false)}>
        <Pressable style={s.backdrop} onPress={() => setShowCreate(false)} />
        <View style={s.sheet}>
          <View style={s.sheetHandle} />
          <Text style={s.sheetTitle}>New Client</Text>
          <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={{ gap: 16, paddingBottom: 20 }}>
            <Field label="Legal name *" error={form.formState.errors.legal_name?.message}>
              <Controller control={form.control} name="legal_name" render={({ field }) => (
                <TextInput style={s.input} placeholder="Registered legal name" placeholderTextColor="#334155" value={field.value} onChangeText={field.onChange} />
              )} />
            </Field>
            <Field label="Trading name">
              <Controller control={form.control} name="company_name" render={({ field }) => (
                <TextInput style={s.input} placeholder="Brand / trading name" placeholderTextColor="#334155" value={field.value} onChangeText={field.onChange} />
              )} />
            </Field>
            <Field label="Type">
              <Controller control={form.control} name="client_type" render={({ field }) => (
                <View style={s.toggleRow}>
                  {(['organization', 'individual'] as const).map((t) => (
                    <Pressable key={t} onPress={() => field.onChange(t)} style={[s.toggleOpt, field.value === t && s.toggleActive]}>
                      <Text style={[s.toggleText, field.value === t && s.toggleActiveText]}>{t === 'organization' ? 'Organisation' : 'Individual'}</Text>
                    </Pressable>
                  ))}
                </View>
              )} />
            </Field>
            <Field label="Nationality">
              <Controller control={form.control} name="nationality" render={({ field }) => (
                <View style={s.toggleRow}>
                  {(['India', 'Foreign'] as const).map((n) => (
                    <Pressable key={n} onPress={() => field.onChange(n)} style={[s.toggleOpt, field.value === n && s.toggleActive]}>
                      <Text style={[s.toggleText, field.value === n && s.toggleActiveText]}>{n}</Text>
                    </Pressable>
                  ))}
                </View>
              )} />
            </Field>
            <Field label="Contact name">
              <Controller control={form.control} name="contact_name" render={({ field }) => (
                <TextInput style={s.input} placeholder="Primary contact person" placeholderTextColor="#334155" value={field.value} onChangeText={field.onChange} />
              )} />
            </Field>
            <Field label="Contact email" error={form.formState.errors.contact_email?.message}>
              <Controller control={form.control} name="contact_email" render={({ field }) => (
                <TextInput style={s.input} placeholder="contact@company.com" placeholderTextColor="#334155" keyboardType="email-address" autoCapitalize="none" value={field.value} onChangeText={field.onChange} />
              )} />
            </Field>
            <Field label="Phone">
              <Controller control={form.control} name="phone" render={({ field }) => (
                <TextInput style={s.input} placeholder="+91 9876543210" placeholderTextColor="#334155" keyboardType="phone-pad" value={field.value} onChangeText={field.onChange} />
              )} />
            </Field>
            <Field label="Account manager">
              <Controller control={form.control} name="account_manager_id" render={({ field }) => (
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                  {(usersQuery.data ?? []).filter((u) => ['super_admin', 'partner', 'manager'].includes(u.role)).map((u) => (
                    <Pressable key={u.id} onPress={() => field.onChange(u.id)} style={[s.toggleOpt, field.value === u.id && s.toggleActive]}>
                      <Text style={[s.toggleText, field.value === u.id && s.toggleActiveText]}>{u.name}</Text>
                    </Pressable>
                  ))}
                </ScrollView>
              )} />
            </Field>
            <View style={s.btnRow}>
              <Pressable style={s.cancelBtn} onPress={() => { setShowCreate(false); form.reset(); }}>
                <Text style={s.cancelBtnText}>Cancel</Text>
              </Pressable>
              <Pressable style={[s.submitBtn, createMutation.isPending && { opacity: 0.6 }]} onPress={() => void submitCreate()}>
                <Text style={s.submitBtnText}>{createMutation.isPending ? 'Saving…' : 'Create Client'}</Text>
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
  search:         {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.15)',
    borderRadius: 14,
    borderWidth: 1,
    color: '#f1f5f9',
    fontSize: 15,
    paddingHorizontal: 16,
    paddingVertical: 13,
  },

  filterBar:      { flexGrow: 0 },
  filterContent:  { flexDirection: 'row', gap: 8, paddingHorizontal: 16, paddingVertical: 10 },
  chip:           {
    backgroundColor: 'rgba(255,255,255,0.04)',
    borderColor: 'rgba(255,255,255,0.1)',
    borderRadius: 100,
    borderWidth: 1,
    flexShrink: 0,
    paddingHorizontal: 18,
    paddingVertical: 10,
  },
  chipActive:     { backgroundColor: '#f0b23d', borderColor: '#f0b23d' },
  chipText:       { color: 'rgba(255,255,255,0.4)', fontSize: 14, fontWeight: '600' },
  chipTextActive: { color: '#040d1a', fontWeight: '800' },

  list:           { gap: 10, padding: 16, paddingBottom: 100 },

  card:           {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 20,
    borderTopColor: 'rgba(255,255,255,0.08)',
    borderWidth: 1,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    padding: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.35,
    shadowRadius: 14,
    elevation: 6,
  },
  cardPressed:    { transform: [{ scale: 0.98 }], opacity: 0.85 },
  cardAvatar:     { alignItems: 'center', borderRadius: 14, height: 48, justifyContent: 'center', width: 48 },
  avatarText:     { fontSize: 20, fontWeight: '800' },
  cardBody:       { flex: 1, gap: 5 },
  cardTop:        { alignItems: 'center', flexDirection: 'row', gap: 8, justifyContent: 'space-between' },
  clientName:     { color: '#f1f5f9', flex: 1, fontSize: 15, fontWeight: '700' },
  legalName:      { color: '#475569', fontSize: 12 },
  statusBadge:    { borderRadius: 8, paddingHorizontal: 10, paddingVertical: 4 },
  statusText:     { fontSize: 11, fontWeight: '800', letterSpacing: 0.3 },
  cardMeta:       { alignItems: 'center', flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 2 },
  codeText:       { color: '#334155', fontFamily: 'monospace', fontSize: 11, fontWeight: '700' },
  gstTag:         { borderRadius: 6, paddingHorizontal: 8, paddingVertical: 3 },
  gstText:        { fontSize: 11, fontWeight: '700' },
  manager:        { color: '#475569', fontSize: 12 },
  email:          { color: '#334155', fontSize: 12 },
  chevron:        { color: '#1e3a5f', fontSize: 24, fontWeight: '300' },

  empty:          { color: '#334155', marginTop: 48, textAlign: 'center' },

  fab:            { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 30, bottom: 28, elevation: 8, height: 60, justifyContent: 'center', position: 'absolute', right: 20, shadowColor: '#f0b23d', shadowOffset: { width: 0, height: 6 }, shadowOpacity: 0.45, shadowRadius: 14, width: 60 },
  fabText:        { color: '#040d1a', fontSize: 30, fontWeight: '700', lineHeight: 34 },

  backdrop:       { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(2,8,22,0.85)' },
  sheet:          { backgroundColor: '#0b1829', borderColor: 'rgba(100,160,255,0.12)', borderTopLeftRadius: 28, borderTopRightRadius: 28, borderWidth: 1, bottom: 0, left: 0, maxHeight: '90%', padding: 24, paddingBottom: 0, position: 'absolute', right: 0 },
  sheetHandle:    { alignSelf: 'center', backgroundColor: '#1e3a5f', borderRadius: 3, height: 4, marginBottom: 20, width: 40 },
  sheetTitle:     { color: '#f1f5f9', fontSize: 22, fontWeight: '800', marginBottom: 20 },

  fieldLabel:     { color: '#64748b', fontSize: 11, fontWeight: '700', letterSpacing: 0.8, textTransform: 'uppercase' },
  input:          { backgroundColor: '#0d1e38', borderColor: 'rgba(100,160,255,0.15)', borderRadius: 14, borderWidth: 1, color: '#f1f5f9', fontSize: 15, paddingHorizontal: 16, paddingVertical: 13 },
  fieldError:     { color: '#f87171', fontSize: 12 },

  toggleRow:      { flexDirection: 'row', gap: 8 },
  toggleOpt:      { backgroundColor: '#0d1e38', borderColor: 'rgba(100,160,255,0.15)', borderRadius: 12, borderWidth: 1, paddingHorizontal: 16, paddingVertical: 10 },
  toggleActive:   { backgroundColor: '#f0b23d', borderColor: '#f0b23d' },
  toggleText:     { color: '#475569', fontSize: 13, fontWeight: '600' },
  toggleActiveText:{ color: '#040d1a', fontWeight: '800' },

  btnRow:         { flexDirection: 'row', gap: 10, paddingVertical: 20 },
  cancelBtn:      { alignItems: 'center', backgroundColor: '#0d1e38', borderRadius: 14, flex: 1, justifyContent: 'center', paddingVertical: 14 },
  cancelBtnText:  { color: '#475569', fontSize: 15, fontWeight: '700' },
  submitBtn:      { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 14, flex: 2, justifyContent: 'center', paddingVertical: 14 },
  submitBtnText:  { color: '#040d1a', fontSize: 15, fontWeight: '800' },
});
