import { Tabs } from 'expo-router';

import { useAuth } from '../../src/providers/auth-provider';

export default function AppLayout() {
  const { session } = useAuth();

  if (!session) {
    return null;
  }

  return (
    <Tabs
      screenOptions={{
        headerStyle: { backgroundColor: '#0d1321' },
        headerTintColor: '#f8fafc',
        tabBarStyle: { backgroundColor: '#0d1321', borderTopColor: '#1f2b46' },
        tabBarActiveTintColor: '#f0b23d',
        tabBarInactiveTintColor: '#94a3b8',
      }}
    >
      <Tabs.Screen name="index" options={{ title: 'Home', headerTitle: 'IPFlow Mobile' }} />
      <Tabs.Screen name="attendance" options={{ title: 'Attendance' }} />
      <Tabs.Screen name="tasks" options={{ title: 'Tasks' }} />
      <Tabs.Screen name="inbox" options={{ title: 'Inbox' }} />
      <Tabs.Screen name="reminders" options={{ title: 'Reminders' }} />
    </Tabs>
  );
}
