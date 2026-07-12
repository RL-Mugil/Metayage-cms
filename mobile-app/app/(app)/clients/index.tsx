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

const STATUS_FILTERS: Array<{ label: string; value: string }> = [
  { label: 'All', value: '' },
  { label: 'Active', value: 'Active' },
  { label: 'Prospect', value: 'Prospect' },
  { label: 'Inactive', value: 'Inactive' },
];

const GST_COLORS: Record<string, string> = {
  B2B: '#2864ff',
  B2C: '#7c3aed',
  Export: '#0d9488',
  Unregistered: '#64748b',
};

const STATUS_COLORS: Record<ClientStatus, string> = {
  Active: '#16a34a',
  Inactive: '#64748b',
  Prospect: '#d97706',
  'On Hold': '#dc2626',
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

function statusColor(status: ClientStatus) {
  return STATUS_COLORS[status] ?? '#64748b';
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
      Alert.alert('Client created', 'The new client has been added.');
    },
    onError: (error) => Alert.alert('Failed', error instanceof Error ? error.message : 'Could not create client.'),
  });

  const submitCreate = form.handleSubmit((values) => void createMutation.mutateAsync(values));

  const renderClient = ({ item }: { item: Client }) => (
    <Pressable style={styles.card} onPress={() => router.push(`/clients/${item.id}`)}>
      <View style={styles.cardHeader}>
        <Text style={styles.code}>{item.client_code}</Text>
        <View style={[styles.badge, { backgroundColor: statusColor(item.status) + '33', borderColor: statusColor(item.status) }]}>
          <Text style={[styles.badgeText, { color: statusColor(item.status) }]}>{item.status}</Text>
        </View>
      </View>
      <Text style={styles.name}>{item.company_name || item.legal_name}</Text>
      {item.company_name && item.legal_name !== item.company_name ? (
        <Text style={styles.sub}>{item.legal_name}</Text>
      ) : null}
      <View style={styles.row}>
        <View style={[styles.gstBadge, { backgroundColor: (GST_COLORS[item.gst_type] ?? '#64748b') + '33' }]}>
          <Text style={[styles.gstText, { color: GST_COLORS[item.gst_type] ?? '#94a3b8' }]}>{item.gst_type}</Text>
        </View>
        {item.account_manager ? <Text style={styles.manager}>{item.account_manager.name}</Text> : null}
      </View>
      {item.contact_email ? <Text style={styles.contact}>{item.contact_email}</Text> : null}
    </Pressable>
  );

  return (
    <SafeAreaView style={styles.safe}>
      {/* Search */}
      <View style={styles.searchRow}>
        <TextInput
          style={styles.search}
          placeholder="Search clients…"
          placeholderTextColor="#7c8aa5"
          value={search}
          onChangeText={setSearch}
          returnKeyType="search"
        />
      </View>

      {/* Filters */}
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.filterBar} contentContainerStyle={styles.filterContent}>
        {STATUS_FILTERS.map((f) => (
          <Pressable
            key={f.value}
            onPress={() => setActiveFilter(f.value)}
            style={[styles.chip, activeFilter === f.value && styles.chipActive]}
          >
            <Text style={[styles.chipText, activeFilter === f.value && styles.chipTextActive]}>{f.label}</Text>
          </Pressable>
        ))}
      </ScrollView>

      {/* List */}
      <FlatList
        data={clientsQuery.data?.data ?? []}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={styles.list}
        refreshControl={<RefreshControl refreshing={clientsQuery.isRefetching} onRefresh={() => void clientsQuery.refetch()} tintColor="#f0b23d" />}
        renderItem={renderClient}
        ListEmptyComponent={
          clientsQuery.isLoading
            ? <ActivityIndicator color="#f0b23d" style={{ marginTop: 40 }} />
            : <Text style={styles.empty}>No clients found.</Text>
        }
      />

      {/* FAB */}
      <Pressable style={styles.fab} onPress={() => setShowCreate(true)}>
        <Text style={styles.fabText}>+</Text>
      </Pressable>

      {/* Create Modal */}
      <Modal animationType="slide" transparent visible={showCreate} onRequestClose={() => setShowCreate(false)}>
        <View style={styles.backdrop}>
          <View style={styles.sheet}>
            <Text style={styles.sheetTitle}>New Client</Text>

            <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={{ gap: 14 }}>
              <Field label="Legal name *" error={form.formState.errors.legal_name?.message}>
                <Controller control={form.control} name="legal_name" render={({ field }) => (
                  <TextInput style={styles.input} placeholder="Registered legal name" placeholderTextColor="#7c8aa5" value={field.value} onChangeText={field.onChange} />
                )} />
              </Field>

              <Field label="Trading name">
                <Controller control={form.control} name="company_name" render={({ field }) => (
                  <TextInput style={styles.input} placeholder="Brand / trading name" placeholderTextColor="#7c8aa5" value={field.value} onChangeText={field.onChange} />
                )} />
              </Field>

              <Field label="Type">
                <Controller control={form.control} name="client_type" render={({ field }) => (
                  <View style={styles.toggle}>
                    {(['organization', 'individual'] as const).map((t) => (
                      <Pressable key={t} onPress={() => field.onChange(t)} style={[styles.toggleOption, field.value === t && styles.toggleActive]}>
                        <Text style={[styles.toggleText, field.value === t && styles.toggleTextActive]}>{t === 'organization' ? 'Organisation' : 'Individual'}</Text>
                      </Pressable>
                    ))}
                  </View>
                )} />
              </Field>

              <Field label="Nationality">
                <Controller control={form.control} name="nationality" render={({ field }) => (
                  <View style={styles.toggle}>
                    {(['India', 'Foreign'] as const).map((n) => (
                      <Pressable key={n} onPress={() => field.onChange(n)} style={[styles.toggleOption, field.value === n && styles.toggleActive]}>
                        <Text style={[styles.toggleText, field.value === n && styles.toggleTextActive]}>{n}</Text>
                      </Pressable>
                    ))}
                  </View>
                )} />
              </Field>

              <Field label="Contact name">
                <Controller control={form.control} name="contact_name" render={({ field }) => (
                  <TextInput style={styles.input} placeholder="Primary contact person" placeholderTextColor="#7c8aa5" value={field.value} onChangeText={field.onChange} />
                )} />
              </Field>

              <Field label="Contact email" error={form.formState.errors.contact_email?.message}>
                <Controller control={form.control} name="contact_email" render={({ field }) => (
                  <TextInput style={styles.input} placeholder="contact@company.com" placeholderTextColor="#7c8aa5" keyboardType="email-address" autoCapitalize="none" value={field.value} onChangeText={field.onChange} />
                )} />
              </Field>

              <Field label="Phone">
                <Controller control={form.control} name="phone" render={({ field }) => (
                  <TextInput style={styles.input} placeholder="+91 9876543210" placeholderTextColor="#7c8aa5" keyboardType="phone-pad" value={field.value} onChangeText={field.onChange} />
                )} />
              </Field>

              <Field label="Account manager">
                <Controller control={form.control} name="account_manager_id" render={({ field }) => (
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                    {(usersQuery.data ?? [])
                      .filter((u) => ['super_admin', 'partner', 'manager'].includes(u.role))
                      .map((u) => (
                        <Pressable key={u.id} onPress={() => field.onChange(u.id)} style={[styles.toggleOption, field.value === u.id && styles.toggleActive]}>
                          <Text style={[styles.toggleText, field.value === u.id && styles.toggleTextActive]}>{u.name}</Text>
                        </Pressable>
                      ))}
                  </ScrollView>
                )} />
              </Field>
            </ScrollView>

            <View style={[styles.row, { marginTop: 16 }]}>
              <Pressable style={styles.cancelBtn} onPress={() => { setShowCreate(false); form.reset(); }}>
                <Text style={styles.btnText}>Cancel</Text>
              </Pressable>
              <Pressable style={styles.submitBtn} onPress={() => void submitCreate()}>
                <Text style={styles.btnText}>{createMutation.isPending ? 'Saving…' : 'Create client'}</Text>
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
  cardHeader:      { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  code:            { color: '#9fb0d3', fontFamily: 'monospace', fontSize: 13, fontWeight: '700' },
  badge:           { borderRadius: 8, borderWidth: 1, paddingHorizontal: 8, paddingVertical: 2 },
  badgeText:       { fontSize: 12, fontWeight: '700' },
  name:            { color: '#f8fafc', fontSize: 16, fontWeight: '700' },
  sub:             { color: '#9fb0d3', fontSize: 13 },
  row:             { alignItems: 'center', flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  gstBadge:        { borderRadius: 6, paddingHorizontal: 8, paddingVertical: 2 },
  gstText:         { fontSize: 11, fontWeight: '700' },
  manager:         { color: '#94a3b8', fontSize: 12 },
  contact:         { color: '#7c8aa5', fontSize: 13 },
  empty:           { color: '#94a3b8', marginTop: 40, textAlign: 'center' },
  fab:             { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 28, bottom: 24, elevation: 6, height: 56, justifyContent: 'center', position: 'absolute', right: 24, shadowColor: '#f0b23d', shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.4, shadowRadius: 8, width: 56 },
  fabText:         { color: '#0d1321', fontSize: 28, fontWeight: '700', lineHeight: 32 },
  backdrop:        { backgroundColor: 'rgba(6,10,18,0.85)', flex: 1, justifyContent: 'flex-end' },
  sheet:           { backgroundColor: '#131c31', borderTopLeftRadius: 24, borderTopRightRadius: 24, gap: 4, maxHeight: '90%', padding: 20 },
  sheetTitle:      { color: '#f8fafc', fontSize: 20, fontWeight: '700', marginBottom: 12 },
  input:           { backgroundColor: '#0b1120', borderColor: '#2a3c61', borderRadius: 12, borderWidth: 1, color: '#f8fafc', fontSize: 15, paddingHorizontal: 14, paddingVertical: 11 },
  toggle:          { flexDirection: 'row', gap: 8 },
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
