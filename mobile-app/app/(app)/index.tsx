import { useQuery } from '@tanstack/react-query';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { getApprovals, getAttendanceLogs, getNotifications, getReminders, getTasksForSession } from '../../src/lib/api';
import { getPendingActionCount } from '../../src/lib/offline-queue';
import { useAuth } from '../../src/providers/auth-provider';

export default function HomeScreen() {
  const { session, signOut } = useAuth();
  const token = session!.token;

  const attendanceQuery = useQuery({ queryKey: ['attendance'], queryFn: () => getAttendanceLogs(token) });
  const tasksQuery = useQuery({ queryKey: ['tasks'], queryFn: () => getTasksForSession(token) });
  const approvalsQuery = useQuery({ queryKey: ['approvals'], queryFn: () => getApprovals(token) });
  const notificationsQuery = useQuery({ queryKey: ['notifications'], queryFn: () => getNotifications(token) });
  const remindersQuery = useQuery({ queryKey: ['reminders'], queryFn: () => getReminders(token) });
  const queueQuery = useQuery({ queryKey: ['offline-queue'], queryFn: getPendingActionCount });

  const todayAttendance = (attendanceQuery.data ?? []).find((item) => item.is_today);
  const openTasks = (tasksQuery.data ?? []).filter((item) => item.status !== 'Completed').length;
  const pendingApprovals = (approvalsQuery.data ?? []).filter((item) => item.status === 'pending').length;
  const unreadNotifications = (notificationsQuery.data ?? []).filter((item) => !item.read).length;
  const activeReminders = (remindersQuery.data ?? []).filter((item) => !item.completed).length;
  const pendingActions = queueQuery.data ?? 0;
  const refreshing = [attendanceQuery, tasksQuery, approvalsQuery, notificationsQuery, remindersQuery, queueQuery].some((query) => query.isRefetching);

  const onRefresh = () => {
    void attendanceQuery.refetch();
    void tasksQuery.refetch();
    void approvalsQuery.refetch();
    void notificationsQuery.refetch();
    void remindersQuery.refetch();
    void queueQuery.refetch();
  };

  return (
    <SafeAreaView style={styles.safeArea}>
      <ScrollView contentContainerStyle={styles.container} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#f0b23d" />}>
        <Text style={styles.eyebrow}>Action board</Text>
        <Text style={styles.title}>Welcome back, {session?.user.name}</Text>
        <Text style={styles.subtitle}>
          Mobile should tell you what needs action right now and keep working when the network does not.
        </Text>

        <View style={styles.grid}>
          <View style={styles.card}>
            <Text style={styles.cardLabel}>Attendance</Text>
            <Text style={styles.cardValue}>{todayAttendance?.status ?? 'No record'}</Text>
            <Text style={styles.cardMeta}>{todayAttendance?.has_open_session ? 'A work session is currently open.' : `${todayAttendance?.duration_minutes ?? 0} minutes logged today`}</Text>
          </View>
          <View style={styles.card}>
            <Text style={styles.cardLabel}>Tasks</Text>
            <Text style={styles.cardValue}>{openTasks}</Text>
            <Text style={styles.cardMeta}>Open tasks assigned to you</Text>
          </View>
          <View style={styles.card}>
            <Text style={styles.cardLabel}>Approvals</Text>
            <Text style={styles.cardValue}>{pendingApprovals}</Text>
            <Text style={styles.cardMeta}>Pending items in your inbox</Text>
          </View>
          <View style={styles.card}>
            <Text style={styles.cardLabel}>Notifications</Text>
            <Text style={styles.cardValue}>{unreadNotifications}</Text>
            <Text style={styles.cardMeta}>Unread alerts and updates</Text>
          </View>
          <View style={styles.card}>
            <Text style={styles.cardLabel}>Reminders</Text>
            <Text style={styles.cardValue}>{activeReminders}</Text>
            <Text style={styles.cardMeta}>Active reminders still open</Text>
          </View>
          <View style={styles.card}>
            <Text style={styles.cardLabel}>Offline queue</Text>
            <Text style={styles.cardValue}>{pendingActions}</Text>
            <Text style={styles.cardMeta}>Queued attendance or task actions waiting to sync</Text>
          </View>
        </View>

        <Pressable onPress={() => void signOut()} style={styles.button}>
          <Text style={styles.buttonText}>Sign out</Text>
        </Pressable>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#0d1321' },
  container: { gap: 20, padding: 24 },
  eyebrow: { color: '#f0b23d', fontSize: 13, fontWeight: '700', letterSpacing: 1, textTransform: 'uppercase' },
  title: { color: '#f8fafc', fontSize: 28, fontWeight: '700', lineHeight: 34 },
  subtitle: { color: '#9fb0d3', fontSize: 15, lineHeight: 22 },
  grid: { gap: 14 },
  card: { backgroundColor: '#131c31', borderColor: '#21304f', borderRadius: 20, borderWidth: 1, gap: 6, padding: 20 },
  cardLabel: { color: '#94a3b8', fontSize: 13, fontWeight: '600', textTransform: 'uppercase' },
  cardValue: { color: '#f8fafc', fontSize: 20, fontWeight: '700' },
  cardMeta: { color: '#9fb0d3', fontSize: 14, lineHeight: 20 },
  button: { alignItems: 'center', backgroundColor: '#8b1e3f', borderRadius: 16, justifyContent: 'center', minHeight: 52 },
  buttonText: { color: '#fff', fontSize: 16, fontWeight: '700' },
});
