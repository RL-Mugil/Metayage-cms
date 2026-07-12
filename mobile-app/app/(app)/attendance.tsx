import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
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

import { applyLeave, clockIn, clockOut, getAttendanceLogs, getLeaves } from '../../src/lib/api';
import { enqueueAction, isOfflineLikeError } from '../../src/lib/offline-queue';
import { useAuth } from '../../src/providers/auth-provider';
import type { LeaveType } from '../../src/types/api';

function queueId(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

const LEAVE_TYPES: LeaveType[] = ['Earned', 'Casual', 'Sick', 'Compensatory', 'Unpaid'];

const LEAVE_STATUS_COLORS: Record<string, string> = {
  Pending:  '#f0b23d',
  Approved: '#86efac',
  Rejected: '#f87171',
  Cancelled:'#64748b',
};

function leaveBalanceLabel(key: string): string {
  const map: Record<string, string> = {
    earned_leave: 'Earned',
    casual_leave: 'Casual',
    sick_leave:   'Sick',
    lop_days:     'LOP',
  };
  return map[key] ?? key;
}

export default function AttendanceScreen() {
  const { session } = useAuth();
  const queryClient = useQueryClient();
  const token = session!.token;

  const [showLeaveModal, setShowLeaveModal] = useState(false);
  const [leaveType, setLeaveType] = useState<LeaveType>('Earned');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [reason, setReason] = useState('');

  const attendanceQuery = useQuery({
    queryKey: ['attendance'],
    queryFn: () => getAttendanceLogs(token),
  });

  const leavesQuery = useQuery({
    queryKey: ['leaves'],
    queryFn: () => getLeaves(token),
    staleTime: 60_000,
  });

  const refreshAll = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['attendance'] }),
      queryClient.invalidateQueries({ queryKey: ['leaves'] }),
    ]);
  };

  const clockInMutation = useMutation({
    mutationFn: async () => {
      try {
        return await clockIn(token);
      } catch (error) {
        if (!isOfflineLikeError(error)) throw error;
        await enqueueAction({ id: queueId('clock-in'), kind: 'attendance.clockIn', createdAt: new Date().toISOString() });
        return { message: 'Clock-in queued. It will sync when back online.' };
      }
    },
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['attendance'] });
      Alert.alert('Attendance updated', result.message);
    },
    onError: (error) => Alert.alert('Clock in failed', error instanceof Error ? error.message : 'Unable to clock in.'),
  });

  const clockOutMutation = useMutation({
    mutationFn: async () => {
      try {
        return await clockOut(token);
      } catch (error) {
        if (!isOfflineLikeError(error)) throw error;
        await enqueueAction({ id: queueId('clock-out'), kind: 'attendance.clockOut', createdAt: new Date().toISOString() });
        return { message: 'Clock-out queued. It will sync when back online.' };
      }
    },
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['attendance'] });
      Alert.alert('Attendance updated', result.message);
    },
    onError: (error) => Alert.alert('Clock out failed', error instanceof Error ? error.message : 'Unable to clock out.'),
  });

  const applyLeaveMutation = useMutation({
    mutationFn: () => applyLeave(token, { leave_type: leaveType, from_date: fromDate, to_date: toDate, reason }),
    onSuccess: async () => {
      await refreshAll();
      setShowLeaveModal(false);
      setFromDate(''); setToDate(''); setReason(''); setLeaveType('Earned');
      Alert.alert('Leave Applied', 'Your leave request has been submitted for approval.');
    },
    onError: (error) => Alert.alert('Failed', error instanceof Error ? error.message : 'Could not apply leave.'),
  });

  function handleApplyLeave() {
    if (!fromDate.trim() || !toDate.trim()) {
      Alert.alert('Missing dates', 'Please enter both from and to dates.'); return;
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(fromDate) || !/^\d{4}-\d{2}-\d{2}$/.test(toDate)) {
      Alert.alert('Invalid format', 'Use YYYY-MM-DD format (e.g. 2026-07-20).'); return;
    }
    void applyLeaveMutation.mutateAsync();
  }

  const logs = attendanceQuery.data ?? [];
  const today = logs.find((item) => item.is_today);
  const leaveData = leavesQuery.data;
  const balances = leaveData?.balances;
  const myRequests = (leaveData?.requests ?? []).filter((r) => r.is_mine);

  const refreshing = attendanceQuery.isRefetching || leavesQuery.isRefetching;

  return (
    <SafeAreaView style={styles.safe}>
      <FlatList
        contentContainerStyle={styles.content}
        data={logs}
        keyExtractor={(item) => String(item.id)}
        refreshControl={
          <RefreshControl refreshing={refreshing} onRefresh={() => void refreshAll()} tintColor="#f0b23d" />
        }

        ListHeaderComponent={
          <View style={{ gap: 16 }}>
            {/* Today's attendance card */}
            <View style={styles.todayCard}>
              <View style={styles.todayTop}>
                <Text style={styles.sectionLabel}>Today</Text>
                <Text style={[styles.statusBadge,
                  { backgroundColor: today?.status === 'Present' ? '#1a3d2b' : '#1e1a2e',
                    color: today?.status === 'Present' ? '#86efac' : '#94a3b8' }]}>
                  {today?.status ?? 'No record'}
                </Text>
              </View>
              <Text style={styles.todayTime}>
                {today?.has_open_session
                  ? 'Session in progress'
                  : today?.duration_minutes
                  ? `${Math.floor((today.duration_minutes ?? 0) / 60)}h ${(today.duration_minutes ?? 0) % 60}m recorded`
                  : 'No attendance recorded yet'}
              </Text>
              <View style={styles.clockRow}>
                <Pressable
                  disabled={today?.can_clock_in === false || clockInMutation.isPending}
                  onPress={() => void clockInMutation.mutateAsync()}
                  style={[styles.clockBtn, styles.clockInBtn,
                    (today?.can_clock_in === false || clockInMutation.isPending) && styles.btnDisabled]}
                >
                  {clockInMutation.isPending
                    ? <ActivityIndicator color="#fff" size="small" />
                    : <Text style={styles.clockBtnText}>Clock In</Text>}
                </Pressable>
                <Pressable
                  disabled={today?.can_clock_out === false || clockOutMutation.isPending}
                  onPress={() => void clockOutMutation.mutateAsync()}
                  style={[styles.clockBtn, styles.clockOutBtn,
                    (today?.can_clock_out === false || clockOutMutation.isPending) && styles.btnDisabled]}
                >
                  {clockOutMutation.isPending
                    ? <ActivityIndicator color="#fff" size="small" />
                    : <Text style={styles.clockBtnText}>Clock Out</Text>}
                </Pressable>
              </View>
            </View>

            {/* Leave balance card */}
            {balances != null && (
              <View style={styles.balanceCard}>
                <View style={styles.balanceHeader}>
                  <Text style={styles.sectionLabel}>Leave Balance {balances.year}</Text>
                  <Pressable onPress={() => setShowLeaveModal(true)} style={styles.applyBtn}>
                    <Text style={styles.applyBtnText}>+ Apply Leave</Text>
                  </Pressable>
                </View>
                <View style={styles.balanceRow}>
                  {([
                    ['earned_leave', balances.earned_leave],
                    ['casual_leave', balances.casual_leave],
                    ['sick_leave',   balances.sick_leave],
                    ['lop_days',     balances.lop_days],
                  ] as [string, number][]).map(([key, val]) => (
                    <View key={key} style={styles.balanceItem}>
                      <Text style={[styles.balanceValue, key === 'lop_days' && { color: '#f87171' }]}>{val}</Text>
                      <Text style={styles.balanceKey}>{leaveBalanceLabel(key)}</Text>
                    </View>
                  ))}
                </View>
              </View>
            )}

            {/* If no balance data yet, still show Apply Leave button */}
            {balances == null && (
              <Pressable onPress={() => setShowLeaveModal(true)} style={styles.applyBtnFull}>
                <Text style={styles.applyBtnText}>+ Apply Leave</Text>
              </Pressable>
            )}

            {/* Leave requests */}
            {myRequests.length > 0 && (
              <View style={{ gap: 8 }}>
                <Text style={styles.sectionLabel}>My Leave Requests</Text>
                {myRequests.map((req) => (
                  <View key={req.id} style={styles.leaveCard}>
                    <View style={styles.leaveCardRow}>
                      <Text style={styles.leaveType}>{req.leave_type}</Text>
                      <Text style={[styles.leaveBadge, {
                        backgroundColor: LEAVE_STATUS_COLORS[req.status] + '22',
                        color: LEAVE_STATUS_COLORS[req.status],
                      }]}>{req.status}</Text>
                    </View>
                    <Text style={styles.leaveDates}>{req.from_date} → {req.to_date} ({req.total_days} day{req.total_days !== 1 ? 's' : ''})</Text>
                    {req.reason ? <Text style={styles.leaveReason} numberOfLines={2}>{req.reason}</Text> : null}
                    {req.comments ? <Text style={styles.leaveComment}>Manager: {req.comments}</Text> : null}
                  </View>
                ))}
              </View>
            )}

            {/* Attendance history heading */}
            <Text style={styles.sectionLabel}>Attendance History</Text>
          </View>
        }

        renderItem={({ item }) => (
          <View style={styles.logCard}>
            <View style={styles.logRow}>
              <Text style={styles.logDate}>{item.attendance_date}</Text>
              <Text style={[styles.logStatus,
                { color: item.status === 'Present' ? '#86efac' : item.status === 'Absent' ? '#f87171' : '#f0b23d' }]}>
                {item.status}
              </Text>
            </View>
            <Text style={styles.logMeta}>
              {Math.floor((item.duration_minutes ?? 0) / 60)}h {(item.duration_minutes ?? 0) % 60}m · {item.session_count} session{item.session_count === 1 ? '' : 's'}
            </Text>
            {item.sessions.map((s, idx) => (
              <Text key={`${item.id}-${idx}`} style={styles.sessionLine}>
                {s.in} → {s.out ?? 'Open'}{s.duration_minutes != null ? ` (${s.duration_minutes} min)` : ''}
              </Text>
            ))}
          </View>
        )}

        ListEmptyComponent={
          attendanceQuery.isLoading
            ? <ActivityIndicator color="#f0b23d" style={{ marginTop: 32 }} />
            : <View style={styles.empty}><Text style={styles.emptyText}>No attendance records found</Text></View>
        }
      />

      {/* Apply Leave Modal */}
      <Modal visible={showLeaveModal} animationType="slide" transparent presentationStyle="overFullScreen">
        <Pressable style={styles.backdrop} onPress={() => setShowLeaveModal(false)} />
        <View style={styles.sheet}>
          <View style={styles.sheetHandle} />
          <ScrollView showsVerticalScrollIndicator={false}>
            <Text style={styles.sheetTitle}>Apply Leave</Text>

            {/* Leave type chips */}
            <Text style={styles.fieldLabel}>Leave Type</Text>
            <View style={styles.chipRow}>
              {LEAVE_TYPES.map((t) => (
                <Pressable
                  key={t}
                  onPress={() => setLeaveType(t)}
                  style={[styles.chip, leaveType === t && styles.chipActive]}
                >
                  <Text style={[styles.chipText, leaveType === t && styles.chipTextActive]}>{t}</Text>
                </Pressable>
              ))}
            </View>

            {/* From date */}
            <Text style={styles.fieldLabel}>From Date</Text>
            <TextInput
              style={styles.input}
              placeholder="YYYY-MM-DD"
              placeholderTextColor="#4a5c80"
              value={fromDate}
              onChangeText={setFromDate}
              keyboardType="numbers-and-punctuation"
            />

            {/* To date */}
            <Text style={styles.fieldLabel}>To Date</Text>
            <TextInput
              style={styles.input}
              placeholder="YYYY-MM-DD"
              placeholderTextColor="#4a5c80"
              value={toDate}
              onChangeText={setToDate}
              keyboardType="numbers-and-punctuation"
            />

            {/* Reason */}
            <Text style={styles.fieldLabel}>Reason</Text>
            <TextInput
              style={[styles.input, styles.textArea]}
              placeholder="Brief reason for leave..."
              placeholderTextColor="#4a5c80"
              value={reason}
              onChangeText={setReason}
              multiline
              numberOfLines={3}
              textAlignVertical="top"
            />

            <Pressable
              onPress={handleApplyLeave}
              disabled={applyLeaveMutation.isPending}
              style={[styles.submitBtn, applyLeaveMutation.isPending && { opacity: 0.6 }]}
            >
              {applyLeaveMutation.isPending
                ? <ActivityIndicator color="#040d1a" />
                : <Text style={styles.submitBtnText}>Submit Leave Request</Text>}
            </Pressable>

            <Pressable onPress={() => setShowLeaveModal(false)} style={styles.cancelLink}>
              <Text style={styles.cancelLinkText}>Cancel</Text>
            </Pressable>
          </ScrollView>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe:           { flex: 1, backgroundColor: '#040d1a' },
  content:        { gap: 12, padding: 16, paddingBottom: 32 },

  sectionLabel:   { color: '#64748b', fontSize: 11, fontWeight: '700', letterSpacing: 0.8, textTransform: 'uppercase', marginBottom: 4 },

  // Today card
  todayCard:      {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 20,
    borderTopColor: 'rgba(255,255,255,0.08)',
    borderWidth: 1,
    elevation: 6,
    gap: 10,
    padding: 20,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.35,
    shadowRadius: 14,
  },
  todayTop:       { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  statusBadge:    { borderRadius: 10, fontSize: 12, fontWeight: '700', paddingHorizontal: 10, paddingVertical: 4 },
  todayTime:      { color: '#475569', fontSize: 15, fontWeight: '500' },
  clockRow:       { flexDirection: 'row', gap: 12, marginTop: 4 },
  clockBtn:       { alignItems: 'center', borderRadius: 16, flex: 1, justifyContent: 'center', minHeight: 50 },
  clockInBtn:     { backgroundColor: 'rgba(96,165,250,0.2)', borderColor: 'rgba(96,165,250,0.4)', borderWidth: 1 },
  clockOutBtn:    { backgroundColor: 'rgba(134,239,172,0.15)', borderColor: 'rgba(134,239,172,0.35)', borderWidth: 1 },
  btnDisabled:    { opacity: 0.35 },
  clockBtnText:   { color: '#f1f5f9', fontSize: 15, fontWeight: '700' },

  // Balance card
  balanceCard:    {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 20,
    borderTopColor: 'rgba(255,255,255,0.07)',
    borderWidth: 1,
    elevation: 6,
    gap: 14,
    padding: 20,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.3,
    shadowRadius: 14,
  },
  balanceHeader:  { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  balanceRow:     { flexDirection: 'row', gap: 8 },
  balanceItem:    { alignItems: 'center', backgroundColor: 'rgba(255,255,255,0.04)', borderRadius: 12, flex: 1, paddingVertical: 12 },
  balanceValue:   { color: '#f0b23d', fontSize: 22, fontWeight: '800' },
  balanceKey:     { color: '#334155', fontSize: 10, fontWeight: '700', letterSpacing: 0.6, marginTop: 2, textTransform: 'uppercase' },

  applyBtn:       { backgroundColor: '#f0b23d', borderRadius: 10, paddingHorizontal: 14, paddingVertical: 8 },
  applyBtnFull:   { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 16, paddingVertical: 15 },
  applyBtnText:   { color: '#040d1a', fontSize: 14, fontWeight: '800' },

  // Leave request cards
  leaveCard:      {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 16,
    borderWidth: 1,
    elevation: 4,
    gap: 6,
    padding: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.25,
    shadowRadius: 10,
  },
  leaveCardRow:   { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  leaveType:      { color: '#f1f5f9', fontSize: 14, fontWeight: '700' },
  leaveBadge:     { borderRadius: 8, fontSize: 12, fontWeight: '700', paddingHorizontal: 10, paddingVertical: 3 },
  leaveDates:     { color: '#475569', fontSize: 13 },
  leaveReason:    { color: '#334155', fontSize: 12 },
  leaveComment:   { color: '#f0b23d', fontSize: 12, fontStyle: 'italic' },

  // Log cards
  logCard:        {
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
  logRow:         { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  logDate:        { color: '#f1f5f9', fontSize: 15, fontWeight: '700' },
  logStatus:      { fontSize: 13, fontWeight: '700' },
  logMeta:        { color: '#475569', fontSize: 13 },
  sessionLine:    { color: '#334155', fontSize: 12 },

  empty:          { alignItems: 'center', paddingVertical: 40 },
  emptyText:      { color: '#334155', fontSize: 14 },

  // Modal / Sheet
  backdrop:       { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(4,13,26,0.92)' },
  sheet:          { backgroundColor: '#0b1829', borderTopLeftRadius: 28, borderTopRightRadius: 28, borderColor: 'rgba(100,160,255,0.15)', borderWidth: 1, bottom: 0, left: 0, maxHeight: '90%', padding: 24, paddingBottom: 40, position: 'absolute', right: 0 },
  sheetHandle:    { alignSelf: 'center', backgroundColor: 'rgba(100,160,255,0.2)', borderRadius: 3, height: 4, marginBottom: 20, width: 40 },
  sheetTitle:     { color: '#f1f5f9', fontSize: 20, fontWeight: '800', marginBottom: 20 },

  fieldLabel:     { color: '#334155', fontSize: 11, fontWeight: '700', letterSpacing: 1, marginBottom: 8, marginTop: 16, textTransform: 'uppercase' },
  input:          { backgroundColor: 'rgba(255,255,255,0.04)', borderColor: 'rgba(100,160,255,0.2)', borderRadius: 12, borderWidth: 1, color: '#f1f5f9', fontSize: 15, padding: 14 },
  textArea:       { height: 88, lineHeight: 22 },

  chipRow:        { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip:           { backgroundColor: 'rgba(255,255,255,0.04)', borderColor: 'rgba(100,160,255,0.2)', borderRadius: 10, borderWidth: 1, paddingHorizontal: 14, paddingVertical: 9 },
  chipActive:     { backgroundColor: '#f0b23d', borderColor: '#f0b23d' },
  chipText:       { color: '#475569', fontSize: 13, fontWeight: '600' },
  chipTextActive: { color: '#040d1a', fontWeight: '800' },

  submitBtn:      { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 16, marginTop: 24, paddingVertical: 16 },
  submitBtnText:  { color: '#040d1a', fontSize: 16, fontWeight: '800' },
  cancelLink:     { alignItems: 'center', marginTop: 12, paddingVertical: 12 },
  cancelLinkText: { color: '#334155', fontSize: 14 },
});
