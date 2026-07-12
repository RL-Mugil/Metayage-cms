import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ActivityIndicator, Alert, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { clockIn, clockOut, getAttendanceLogs } from '../../src/lib/api';
import { enqueueAction, isOfflineLikeError } from '../../src/lib/offline-queue';
import { useAuth } from '../../src/providers/auth-provider';

function queueId(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

export default function AttendanceScreen() {
  const { session } = useAuth();
  const queryClient = useQueryClient();
  const token = session!.token;

  const attendanceQuery = useQuery({
    queryKey: ['attendance'],
    queryFn: () => getAttendanceLogs(token),
  });

  const refreshAttendance = async () => {
    await queryClient.invalidateQueries({ queryKey: ['attendance'] });
  };

  const clockInMutation = useMutation({
    mutationFn: async () => {
      try {
        return await clockIn(token);
      } catch (error) {
        if (!isOfflineLikeError(error)) {
          throw error;
        }

        await enqueueAction({
          id: queueId('clock-in'),
          kind: 'attendance.clockIn',
          createdAt: new Date().toISOString(),
        });

        return { message: 'Clock-in queued. It will sync when the device is back online.' };
      }
    },
    onSuccess: async (result) => {
      await refreshAttendance();
      Alert.alert('Attendance updated', result.message);
    },
    onError: (error) => {
      Alert.alert('Clock in failed', error instanceof Error ? error.message : 'Unable to clock in.');
    },
  });

  const clockOutMutation = useMutation({
    mutationFn: async () => {
      try {
        return await clockOut(token);
      } catch (error) {
        if (!isOfflineLikeError(error)) {
          throw error;
        }

        await enqueueAction({
          id: queueId('clock-out'),
          kind: 'attendance.clockOut',
          createdAt: new Date().toISOString(),
        });

        return { message: 'Clock-out queued. It will sync when the device is back online.' };
      }
    },
    onSuccess: async (result) => {
      await refreshAttendance();
      Alert.alert('Attendance updated', result.message);
    },
    onError: (error) => {
      Alert.alert('Clock out failed', error instanceof Error ? error.message : 'Unable to clock out.');
    },
  });

  const logs = attendanceQuery.data ?? [];
  const today = logs.find((item) => item.is_today);

  return (
    <SafeAreaView style={styles.safeArea}>
      <FlatList
        contentContainerStyle={styles.content}
        data={logs}
        keyExtractor={(item) => String(item.id)}
        refreshControl={
          <RefreshControl
            refreshing={attendanceQuery.isRefetching}
            onRefresh={() => void attendanceQuery.refetch()}
            tintColor="#f0b23d"
          />
        }
        ListHeaderComponent={
          <View style={styles.headerCard}>
            <Text style={styles.headerTitle}>Today</Text>
            <Text style={styles.headerValue}>{today?.status ?? 'No attendance record yet'}</Text>
            <Text style={styles.headerMeta}>
              {today?.has_open_session
                ? 'A work session is currently open.'
                : `${today?.duration_minutes ?? 0} minutes recorded today.`}
            </Text>
            <View style={styles.actions}>
              <Pressable
                disabled={today?.can_clock_in === false || clockInMutation.isPending}
                onPress={() => void clockInMutation.mutateAsync()}
                style={[styles.primaryButton, (today?.can_clock_in === false || clockInMutation.isPending) && styles.disabledButton]}
              >
                {clockInMutation.isPending ? <ActivityIndicator color="#fff" /> : <Text style={styles.actionText}>Clock in</Text>}
              </Pressable>
              <Pressable
                disabled={today?.can_clock_out === false || clockOutMutation.isPending}
                onPress={() => void clockOutMutation.mutateAsync()}
                style={[styles.secondaryButton, (today?.can_clock_out === false || clockOutMutation.isPending) && styles.disabledButton]}
              >
                {clockOutMutation.isPending ? <ActivityIndicator color="#fff" /> : <Text style={styles.actionText}>Clock out</Text>}
              </Pressable>
            </View>
          </View>
        }
        renderItem={({ item }) => (
          <View style={styles.card}>
            <Text style={styles.date}>{item.attendance_date}</Text>
            <Text style={styles.status}>{item.status}</Text>
            <Text style={styles.meta}>
              {item.duration_minutes} minutes across {item.session_count} session{item.session_count === 1 ? '' : 's'}
            </Text>
            {item.sessions.map((sessionItem, index) => (
              <Text key={`${item.id}-${index}`} style={styles.sessionLine}>
                {sessionItem.in} to {sessionItem.out ?? 'Open'} ({sessionItem.duration_minutes ?? 0} min)
              </Text>
            ))}
          </View>
        )}
      />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#0d1321' },
  content: { gap: 12, padding: 20 },
  headerCard: {
    backgroundColor: '#131c31',
    borderColor: '#21304f',
    borderRadius: 20,
    borderWidth: 1,
    gap: 8,
    marginBottom: 16,
    padding: 20,
  },
  headerTitle: { color: '#94a3b8', fontSize: 13, fontWeight: '600', textTransform: 'uppercase' },
  headerValue: { color: '#f8fafc', fontSize: 24, fontWeight: '700' },
  headerMeta: { color: '#9fb0d3', fontSize: 14, lineHeight: 20 },
  actions: { flexDirection: 'row', gap: 12, marginTop: 8 },
  primaryButton: { alignItems: 'center', backgroundColor: '#2864ff', borderRadius: 14, flex: 1, justifyContent: 'center', minHeight: 48 },
  secondaryButton: { alignItems: 'center', backgroundColor: '#2c4a24', borderRadius: 14, flex: 1, justifyContent: 'center', minHeight: 48 },
  disabledButton: { opacity: 0.45 },
  actionText: { color: '#fff', fontSize: 15, fontWeight: '700' },
  card: { backgroundColor: '#131c31', borderColor: '#21304f', borderRadius: 18, borderWidth: 1, gap: 5, padding: 18 },
  date: { color: '#f8fafc', fontSize: 16, fontWeight: '700' },
  status: { color: '#f0b23d', fontSize: 14, fontWeight: '700' },
  meta: { color: '#9fb0d3', fontSize: 14 },
  sessionLine: { color: '#dbe4ff', fontSize: 13 },
});
