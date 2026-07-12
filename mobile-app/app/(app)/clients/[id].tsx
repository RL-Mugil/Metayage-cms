import { useQuery } from '@tanstack/react-query';
import { useLocalSearchParams, useNavigation } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { getClient } from '../../../src/lib/api';
import { useAuth } from '../../../src/providers/auth-provider';
import type { ClientStatus } from '../../../src/types/api';

const STATUS_COLORS: Record<ClientStatus, string> = {
  Active: '#16a34a',
  Inactive: '#64748b',
  Prospect: '#d97706',
  'On Hold': '#dc2626',
};

function daysUntil(dateStr?: string | null): string {
  if (!dateStr) return '';
  const diff = Math.ceil((Date.parse(dateStr) - Date.now()) / 86_400_000);
  if (diff < 0) return 'Overdue';
  if (diff === 0) return 'Due today';
  return `${diff}d left`;
}

export default function ClientDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { session } = useAuth();
  const navigation = useNavigation();
  const token = session!.token;
  const [tab, setTab] = useState<'info' | 'projects'>('info');

  const query = useQuery({
    queryKey: ['client', id],
    queryFn: () => getClient(token, Number(id)),
  });

  const client = query.data;

  useEffect(() => {
    if (client) {
      navigation.setOptions({ title: client.company_name || client.legal_name });
    }
  }, [client, navigation]);

  if (query.isLoading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color="#f0b23d" size="large" />
      </View>
    );
  }

  if (!client) {
    return (
      <View style={styles.center}>
        <Text style={styles.empty}>Client not found.</Text>
      </View>
    );
  }

  const sc = STATUS_COLORS[client.status as ClientStatus] ?? '#64748b';

  return (
    <SafeAreaView style={styles.safe} edges={['bottom']}>
      {/* Header card */}
      <View style={styles.header}>
        <View style={styles.headerRow}>
          <Text style={styles.code}>{client.client_code}</Text>
          <View style={[styles.badge, { backgroundColor: sc + '33', borderColor: sc }]}>
            <Text style={[styles.badgeText, { color: sc }]}>{client.status}</Text>
          </View>
        </View>
        <Text style={styles.name}>{client.company_name || client.legal_name}</Text>
        {client.company_name && client.legal_name !== client.company_name
          ? <Text style={styles.sub}>{client.legal_name}</Text>
          : null}
        <Text style={styles.gstChip}>{client.gst_type}</Text>
      </View>

      {/* Segment tabs */}
      <View style={styles.segmentBar}>
        {(['info', 'projects'] as const).map((t) => (
          <Pressable key={t} onPress={() => setTab(t)} style={[styles.segment, tab === t && styles.segmentActive]}>
            <Text style={[styles.segmentText, tab === t && styles.segmentTextActive]}>
              {t === 'info' ? 'Info' : `Projects (${client.projects?.length ?? 0})`}
            </Text>
          </Pressable>
        ))}
      </View>

      {tab === 'info' ? (
        <ScrollView contentContainerStyle={styles.section}>
          <InfoRow label="Account manager" value={client.account_manager?.name} />
          <InfoRow label="Email" value={client.contact_email} />
          <InfoRow label="Phone" value={client.phone} />
          <InfoRow label="Address" value={[client.address, client.state].filter(Boolean).join(', ')} />
          <InfoRow label="PAN" value={client.pan_number} mono />

          {client.contacts?.length > 0 ? (
            <>
              <Text style={styles.sectionTitle}>Contacts</Text>
              {client.contacts.map((c) => (
                <View key={c.id} style={styles.contactCard}>
                  <Text style={styles.contactName}>{c.name}</Text>
                  {c.title ? <Text style={styles.contactMeta}>{c.title}</Text> : null}
                  {c.email ? <Text style={styles.contactMeta}>{c.email}</Text> : null}
                  {c.phone ? <Text style={styles.contactMeta}>{c.phone}</Text> : null}
                </View>
              ))}
            </>
          ) : null}
        </ScrollView>
      ) : (
        <FlatList
          data={client.projects ?? []}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={styles.section}
          renderItem={({ item }) => {
            const dl = daysUntil(item.hard_deadline);
            const overdue = dl === 'Overdue';
            return (
              <View style={styles.projectCard}>
                <View style={styles.headerRow}>
                  <Text style={styles.docket}>{item.docket_number}</Text>
                  <Text style={[styles.deadline, overdue && styles.deadlineRed]}>{dl}</Text>
                </View>
                <Text style={styles.projectName}>{item.project_name}</Text>
                <Text style={styles.statusLabel}>{item.status}</Text>
              </View>
            );
          }}
          ListEmptyComponent={<Text style={styles.empty}>No projects linked to this client.</Text>}
        />
      )}
    </SafeAreaView>
  );
}

function InfoRow({ label, value, mono }: { label: string; value?: string | null; mono?: boolean }) {
  if (!value) return null;
  return (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      <Text style={[styles.infoValue, mono && styles.mono]}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  safe:            { backgroundColor: '#040d1a', flex: 1 },
  center:          { alignItems: 'center', backgroundColor: '#040d1a', flex: 1, justifyContent: 'center' },
  header:          {
    backgroundColor: '#0b1829',
    borderBottomColor: 'rgba(100,160,255,0.1)',
    borderBottomWidth: 1,
    gap: 8,
    padding: 20,
  },
  headerRow:       { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  code:            { color: '#60a5fa', fontFamily: 'monospace', fontSize: 13, fontWeight: '700' },
  badge:           { borderRadius: 8, paddingHorizontal: 10, paddingVertical: 3 },
  badgeText:       { fontSize: 12, fontWeight: '700' },
  name:            { color: '#f1f5f9', fontSize: 22, fontWeight: '800', letterSpacing: -0.5 },
  sub:             { color: '#475569', fontSize: 14, fontWeight: '500' },
  gstChip:         { alignSelf: 'flex-start', backgroundColor: 'rgba(96,165,250,0.12)', borderRadius: 6, color: '#60a5fa', fontSize: 12, fontWeight: '700', paddingHorizontal: 8, paddingVertical: 3 },
  segmentBar:      { backgroundColor: '#0b1829', borderBottomColor: 'rgba(100,160,255,0.1)', borderBottomWidth: 1, flexDirection: 'row' },
  segment:         { flex: 1, paddingVertical: 14, alignItems: 'center' },
  segmentActive:   { borderBottomColor: '#f0b23d', borderBottomWidth: 2 },
  segmentText:     { color: '#334155', fontSize: 14, fontWeight: '700' },
  segmentTextActive: { color: '#f0b23d' },
  section:         { gap: 10, padding: 16 },
  sectionTitle:    { color: '#334155', fontSize: 11, fontWeight: '800', letterSpacing: 1.2, marginTop: 8, textTransform: 'uppercase' },
  infoRow:         { borderBottomColor: 'rgba(100,160,255,0.06)', borderBottomWidth: 1, flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 12 },
  infoLabel:       { color: '#475569', flex: 1, fontSize: 14 },
  infoValue:       { color: '#f1f5f9', flex: 2, fontSize: 14, fontWeight: '600', textAlign: 'right' },
  mono:            { fontFamily: 'monospace' },
  contactCard:     {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 16,
    borderWidth: 1,
    elevation: 4,
    gap: 4,
    padding: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.25,
    shadowRadius: 10,
  },
  contactName:     { color: '#f1f5f9', fontSize: 15, fontWeight: '700' },
  contactMeta:     { color: '#475569', fontSize: 13 },
  projectCard:     {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 16,
    borderWidth: 1,
    elevation: 4,
    gap: 5,
    padding: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.25,
    shadowRadius: 10,
  },
  docket:          { color: '#60a5fa', fontFamily: 'monospace', fontSize: 12, fontWeight: '700' },
  deadline:        { color: '#475569', fontSize: 12 },
  deadlineRed:     { color: '#f87171', fontWeight: '600' },
  projectName:     { color: '#f1f5f9', fontSize: 15, fontWeight: '700' },
  statusLabel:     { color: '#475569', fontSize: 13 },
  empty:           { color: '#334155', fontSize: 14, marginTop: 24, textAlign: 'center' },
});
