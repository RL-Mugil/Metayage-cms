import NetInfo from '@react-native-community/netinfo';
import * as SecureStore from 'expo-secure-store';

import { clockIn, clockOut, logTaskTime, updateTaskStatus } from './api';
import type { QueuedAction } from '../types/api';

const QUEUE_KEY = 'ipflow.mobile.offline-queue';

async function readQueue(): Promise<QueuedAction[]> {
  const value = await SecureStore.getItemAsync(QUEUE_KEY);
  if (!value) {
    return [];
  }

  try {
    return JSON.parse(value) as QueuedAction[];
  } catch {
    await SecureStore.deleteItemAsync(QUEUE_KEY);
    return [];
  }
}

async function writeQueue(queue: QueuedAction[]): Promise<void> {
  await SecureStore.setItemAsync(QUEUE_KEY, JSON.stringify(queue));
}

export function isOfflineLikeError(error: unknown): boolean {
  if (!(error instanceof Error)) {
    return false;
  }

  const message = error.message.toLowerCase();
  return message.includes('network request failed')
    || message.includes('network')
    || message.includes('timed out')
    || message.includes('internet');
}

export async function enqueueAction(action: QueuedAction): Promise<void> {
  const queue = await readQueue();
  queue.push(action);
  await writeQueue(queue);
}

export async function getPendingActionCount(): Promise<number> {
  const queue = await readQueue();
  return queue.length;
}

export async function processPendingActions(token: string): Promise<number> {
  const state = await NetInfo.fetch();
  if (!state.isConnected) {
    return 0;
  }

  const queue = await readQueue();
  if (!queue.length) {
    return 0;
  }

  const remaining: QueuedAction[] = [];
  let processed = 0;

  for (const action of queue) {
    try {
      switch (action.kind) {
        case 'attendance.clockIn':
          await clockIn(token);
          break;
        case 'attendance.clockOut':
          await clockOut(token);
          break;
        case 'tasks.status':
          await updateTaskStatus(token, action.payload.taskId, action.payload.status);
          break;
        case 'tasks.timeLog':
          await logTaskTime(token, {
            taskId: action.payload.taskId,
            projectId: action.payload.projectId,
            durationHours: action.payload.durationHours,
            description: action.payload.description,
          });
          break;
      }

      processed += 1;
    } catch (error) {
      if (isOfflineLikeError(error)) {
        remaining.push(action);
        continue;
      }

      // Validation/business-rule failures should not replay forever.
    }
  }

  await writeQueue(remaining);
  return processed;
}
