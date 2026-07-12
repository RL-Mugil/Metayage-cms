import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ActivityIndicator, Alert, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useState } from 'react';

import {
  dismissNotification,
  getApprovals,
  getNotifications,
  markAllNotificationsRead,
  markNotificationRead,
  resolveApproval,
} from '../../src/lib/api';
import { useAuth } from '../../src/providers/auth-provider';

export default function InboxScreen() {
  const { session } = useAuth();
  const queryClient = useQueryClient();
  const [mode, setMode] = useState<'approvals' | 'notifications'>('approvals');
  const token = session!.token;

  const approvalsQuery = useQuery({
    queryKey: ['approvals'],
    queryFn: () => getApprovals(token),
  });
  const notificationsQuery = useQuery({
    queryKey: ['notifications'],
    queryFn: () => getNotifications(token),
  });

  const resolveMutation = useMutation({
    mutationFn: resolveApproval.bind(null, token),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['approvals'] });
      await queryClient.invalidateQueries({ queryKey: ['notifications'] });
    },
    onError: (error) => {
      Alert.alert('Approval action failed', error instanceof Error ? error.message : 'Unable to resolve approval.');
    },
  });

  const markReadMutation = useMutation({
    mutationFn: (id: number) => markNotificationRead(token, id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['notifications'] });
    },
  });

  const markAllMutation = useMutation({
    mutationFn: () => markAllNotificationsRead(token),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['notifications'] });
    },
  });

  const dismissMutation = useMutation({
    mutationFn: (id: number) => dismissNotification(token, id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['notifications'] });
    },
  });

  const refreshing = mode === 'approvals' ? approvalsQuery.isRefetching : notificationsQuery.isRefetching;

  return (
    <SafeAreaView style={styles.safeArea}>
      <View style={styles.segmented}>
        <Pressable onPress={() => setMode('approvals')} style={[styles.segment, mode === 'approvals' && styles.activeSegment]}>
          <Text style={styles.segmentText}>Approvals</Text>
        </Pressable>
        <Pressable onPress={() => setMode('notifications')} style={[styles.segment, mode === 'notifications' && styles.activeSegment]}>
          <Text style={styles.segmentText}>Notifications</Text>
        </Pressable>
      </View>

      {mode === 'approvals' ? (
        <FlatList
          contentContainerStyle={styles.content}
          data={approvalsQuery.data ?? []}
          keyExtractor={(item) => `approval-${item.id}`}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void approvalsQuery.refetch()} tintColor="#f0b23d" />}
          renderItem={({ item }) => (
            <View style={styles.card}>
              <Text style={styles.title}>{item.title ?? item.type}</Text>
              <Text style={styles.status}>{item.status.toUpperCase()}</Text>
              <Text style={styles.meta}>{item.requester} | {item.submitted}</Text>
              <Text style={styles.body}>{item.description}</Text>
              {item.can_resolve ? (
                <View style={styles.actions}>
                  <Pressable
                    onPress={() => void resolveMutation.mutateAsync({ type: item.type, id: item.id, action: 'Approved' })}
                    style={styles.approveButton}
                  >
                    <Text style={styles.actionText}>Approve</Text>
                  </Pressable>
                  <Pressable
                    onPress={() => void resolveMutation.mutateAsync({ type: item.type, id: item.id, action: 'Rejected' })}
                    style={styles.rejectButton}
                  >
                    <Text style={styles.actionText}>Reject</Text>
                  </Pressable>
                </View>
              ) : null}
            </View>
          )}
          ListEmptyComponent={
            approvalsQuery.isLoading ? (
              <View style={styles.centered}>
                <ActivityIndicator color="#f0b23d" />
              </View>
            ) : (
              <View style={styles.centered}>
                <Text style={styles.empty}>No approvals need mobile action right now.</Text>
              </View>
            )
          }
        />
      ) : (
        <FlatList
          contentContainerStyle={styles.content}
          data={notificationsQuery.data ?? []}
          keyExtractor={(item) => `notification-${item.id}`}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void notificationsQuery.refetch()} tintColor="#f0b23d" />}
          ListHeaderComponent={
            <Pressable onPress={() => void markAllMutation.mutateAsync()} style={styles.readAllButton}>
              <Text style={styles.actionText}>Mark all as read</Text>
            </Pressable>
          }
          renderItem={({ item }) => (
            <View style={[styles.card, !item.read && styles.unreadCard]}>
              <Text style={styles.title}>{item.title}</Text>
              <Text style={styles.meta}>{item.type} | {item.created_at}</Text>
              <Text style={styles.body}>{item.description}</Text>
              <View style={styles.actions}>
                {!item.read ? (
                  <Pressable onPress={() => void markReadMutation.mutateAsync(item.id)} style={styles.approveButton}>
                    <Text style={styles.actionText}>Mark read</Text>
                  </Pressable>
                ) : null}
                <Pressable onPress={() => void dismissMutation.mutateAsync(item.id)} style={styles.rejectButton}>
                  <Text style={styles.actionText}>Dismiss</Text>
                </Pressable>
              </View>
            </View>
          )}
          ListEmptyComponent={
            notificationsQuery.isLoading ? (
              <View style={styles.centered}>
                <ActivityIndicator color="#f0b23d" />
              </View>
            ) : (
              <View style={styles.centered}>
                <Text style={styles.empty}>No notifications in your inbox.</Text>
              </View>
            )
          }
        />
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: {
    flex: 1,
    backgroundColor: '#040d1a',
  },
  segmented: {
    flexDirection: 'row',
    gap: 10,
    paddingHorizontal: 20,
    paddingTop: 16,
  },
  segment: {
    alignItems: 'center',
    backgroundColor: 'rgba(255,255,255,0.04)',
    borderColor: 'rgba(100,160,255,0.2)',
    borderRadius: 14,
    borderWidth: 1,
    flex: 1,
    justifyContent: 'center',
    minHeight: 44,
  },
  activeSegment: {
    backgroundColor: '#f0b23d',
    borderColor: '#f0b23d',
  },
  segmentText: {
    color: '#475569',
    fontSize: 14,
    fontWeight: '700',
  },
  content: {
    gap: 12,
    padding: 20,
  },
  card: {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 20,
    borderWidth: 1,
    elevation: 5,
    gap: 7,
    padding: 18,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 5 },
    shadowOpacity: 0.3,
    shadowRadius: 12,
  },
  unreadCard: {
    borderColor: 'rgba(240,178,61,0.4)',
  },
  title: {
    color: '#f1f5f9',
    fontSize: 16,
    fontWeight: '700',
  },
  status: {
    color: '#f0b23d',
    fontSize: 12,
    fontWeight: '700',
  },
  meta: {
    color: '#475569',
    fontSize: 13,
  },
  body: {
    color: '#94a3b8',
    fontSize: 14,
    lineHeight: 20,
  },
  actions: {
    flexDirection: 'row',
    gap: 10,
    marginTop: 8,
  },
  approveButton: {
    alignItems: 'center',
    backgroundColor: 'rgba(134,239,172,0.12)',
    borderColor: 'rgba(134,239,172,0.3)',
    borderRadius: 12,
    borderWidth: 1,
    flex: 1,
    justifyContent: 'center',
    minHeight: 42,
  },
  rejectButton: {
    alignItems: 'center',
    backgroundColor: 'rgba(248,113,113,0.1)',
    borderColor: 'rgba(248,113,113,0.3)',
    borderRadius: 12,
    borderWidth: 1,
    flex: 1,
    justifyContent: 'center',
    minHeight: 42,
  },
  readAllButton: {
    alignItems: 'center',
    backgroundColor: 'rgba(240,178,61,0.15)',
    borderColor: 'rgba(240,178,61,0.3)',
    borderRadius: 14,
    borderWidth: 1,
    justifyContent: 'center',
    minHeight: 44,
    marginBottom: 8,
  },
  actionText: {
    color: '#f1f5f9',
    fontSize: 13,
    fontWeight: '700',
  },
  centered: {
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 220,
  },
  empty: {
    color: '#334155',
    fontSize: 15,
  },
});
