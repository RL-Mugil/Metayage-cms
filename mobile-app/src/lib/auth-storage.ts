import * as SecureStore from 'expo-secure-store';

const KEY = 'ipflow.mobile.session';
const PUSH_KEY = 'ipflow.mobile.push-token';

export async function readSession(): Promise<string | null> {
  return SecureStore.getItemAsync(KEY);
}

export async function writeSession(value: string): Promise<void> {
  await SecureStore.setItemAsync(KEY, value);
}

export async function clearSession(): Promise<void> {
  await SecureStore.deleteItemAsync(KEY);
}

export async function readPushToken(): Promise<string | null> {
  return SecureStore.getItemAsync(PUSH_KEY);
}

export async function writePushToken(value: string): Promise<void> {
  await SecureStore.setItemAsync(PUSH_KEY, value);
}

export async function clearPushToken(): Promise<void> {
  await SecureStore.deleteItemAsync(PUSH_KEY);
}
