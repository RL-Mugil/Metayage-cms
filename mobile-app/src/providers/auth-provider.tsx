import { useRouter, useSegments } from 'expo-router';
import { createContext, PropsWithChildren, useContext, useEffect, useState } from 'react';
import { Alert } from 'react-native';
import NetInfo from '@react-native-community/netinfo';

import { getCurrentUser, login, logout, registerPushToken, unregisterPushToken } from '../lib/api';
import { clearPushToken, clearSession, readPushToken, readSession, writePushToken, writeSession } from '../lib/auth-storage';
import { processPendingActions } from '../lib/offline-queue';
import { getDeviceName, getDevicePlatform, registerForPushNotificationsAsync } from '../lib/push-notifications';
import type { MobileCredentials, MobileSession } from '../types/api';

type AuthContextValue = {
  session: MobileSession | null;
  isHydrating: boolean;
  signIn: (credentials: MobileCredentials) => Promise<void>;
  signOut: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: PropsWithChildren) {
  const router = useRouter();
  const segments = useSegments();
  const [session, setSession] = useState<MobileSession | null>(null);
  const [isHydrating, setIsHydrating] = useState(true);

  useEffect(() => {
    void (async () => {
      const stored = await readSession();

      if (!stored) {
        setIsHydrating(false);
        return;
      }

      try {
        const parsed = JSON.parse(stored) as MobileSession;
        const user = await getCurrentUser(parsed.token);
        setSession({ token: parsed.token, user });
      } catch {
        await clearSession();
      } finally {
        setIsHydrating(false);
      }
    })();
  }, []);

  useEffect(() => {
    if (isHydrating) {
      return;
    }

    const inAppGroup = segments[0] === '(app)';

    if (!session && inAppGroup) {
      router.replace('/sign-in');
    }

    if (session && !inAppGroup) {
      router.replace('/(app)');
    }
  }, [isHydrating, router, segments, session]);

  useEffect(() => {
    if (!session?.token) {
      return;
    }

    let active = true;

    const syncDevice = async () => {
      try {
        const pushToken = await registerForPushNotificationsAsync();
        if (!pushToken || !active) {
          return;
        }

        await registerPushToken(session.token, {
          push_token: pushToken,
          platform: getDevicePlatform(),
          device_name: getDeviceName(),
          app_version: process.env.EXPO_PUBLIC_APP_VERSION ?? '1.0.0',
        });
        await writePushToken(pushToken);
      } catch {
        // Push registration failure should never block app usage.
      }
    };

    void syncDevice();
    void processPendingActions(session.token);

    const unsubscribe = NetInfo.addEventListener((state) => {
      if (state.isConnected) {
        void processPendingActions(session.token);
      }
    });

    return () => {
      active = false;
      unsubscribe();
    };
  }, [session?.token]);

  const value: AuthContextValue = {
    session,
    isHydrating,
    async signIn(credentials) {
      try {
        const nextSession = await login(credentials);
        await writeSession(JSON.stringify(nextSession));
        setSession(nextSession);
      } catch (error) {
        Alert.alert('Sign in failed', error instanceof Error ? error.message : 'Unable to sign in.');
        throw error;
      }
    },
    async signOut() {
      try {
        if (session?.token) {
          const pushToken = await readPushToken();
          if (pushToken) {
            await unregisterPushToken(session.token, pushToken).catch(() => undefined);
          }
          await logout(session.token);
        }
      } finally {
        setSession(null);
        await clearPushToken();
        await clearSession();
      }
    },
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext);

  if (!value) {
    throw new Error('useAuth must be used within AuthProvider.');
  }

  return value;
}
