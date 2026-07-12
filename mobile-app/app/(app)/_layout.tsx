import { Ionicons } from '@expo/vector-icons';
import { Tabs } from 'expo-router';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { useAuth } from '../../src/providers/auth-provider';

const TAB_ICONS: Record<string, { focused: string; unfocused: string }> = {
  index:      { focused: 'home',                unfocused: 'home-outline' },
  clients:    { focused: 'people',              unfocused: 'people-outline' },
  projects:   { focused: 'briefcase',           unfocused: 'briefcase-outline' },
  invoices:   { focused: 'receipt',             unfocused: 'receipt-outline' },
  tasks:      { focused: 'checkmark-circle',    unfocused: 'checkmark-circle-outline' },
  attendance: { focused: 'time',                unfocused: 'time-outline' },
  inbox:      { focused: 'notifications',       unfocused: 'notifications-outline' },
  reminders:  { focused: 'alarm',               unfocused: 'alarm-outline' },
};

const TAB_LABELS: Record<string, string> = {
  index:      'Home',
  clients:    'Clients',
  projects:   'Projects',
  invoices:   'Invoices',
  tasks:      'Tasks',
  attendance: 'Attendance',
  inbox:      'Inbox',
  reminders:  'Reminders',
};

function ScrollableTabBar({ state, descriptors, navigation }: BottomTabBarProps) {
  return (
    <View style={styles.tabBarWrapper}>
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={styles.tabBarContent}
        style={styles.tabBarScroll}
      >
        {state.routes.map((route, index) => {
          const { options } = descriptors[route.key];
          const isFocused = state.index === index;
          const icons = TAB_ICONS[route.name] ?? { focused: 'ellipse', unfocused: 'ellipse-outline' };
          const label = TAB_LABELS[route.name] ?? (options.title ?? route.name);
          const color = isFocused ? '#f0b23d' : '#94a3b8';

          const onPress = () => {
            const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
            if (!isFocused && !event.defaultPrevented) {
              navigation.navigate(route.name);
            }
          };

          return (
            <Pressable key={route.key} onPress={onPress} style={styles.tabItem} accessibilityRole="tab" accessibilityState={{ selected: isFocused }}>
              <Ionicons name={(isFocused ? icons.focused : icons.unfocused) as never} size={22} color={color} />
              <Text style={[styles.tabLabel, { color }]}>{label}</Text>
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}

export default function AppLayout() {
  const { session } = useAuth();

  if (!session) {
    return null;
  }

  return (
    <Tabs
      tabBar={(props) => <ScrollableTabBar {...props} />}
      screenOptions={{
        headerStyle: { backgroundColor: '#0d1321' },
        headerTintColor: '#f8fafc',
      }}
    >
      <Tabs.Screen name="index"      options={{ title: 'Home', headerTitle: 'IPFlow Mobile' }} />
      <Tabs.Screen name="clients"    options={{ title: 'Clients' }} />
      <Tabs.Screen name="projects"   options={{ title: 'Projects' }} />
      <Tabs.Screen name="invoices"   options={{ title: 'Invoices' }} />
      <Tabs.Screen name="tasks"      options={{ title: 'Tasks' }} />
      <Tabs.Screen name="attendance" options={{ title: 'Attendance' }} />
      <Tabs.Screen name="inbox"      options={{ title: 'Inbox' }} />
      <Tabs.Screen name="reminders"  options={{ title: 'Reminders' }} />
    </Tabs>
  );
}

const styles = StyleSheet.create({
  tabBarWrapper: { backgroundColor: '#0d1321', borderTopColor: '#1f2b46', borderTopWidth: 1 },
  tabBarScroll:  { flexGrow: 0 },
  tabBarContent: { alignItems: 'center', flexDirection: 'row', paddingHorizontal: 4, paddingVertical: 6 },
  tabItem:       { alignItems: 'center', gap: 2, justifyContent: 'center', minWidth: 72, paddingHorizontal: 12, paddingVertical: 6 },
  tabLabel:      { fontSize: 10, fontWeight: '600' },
});
