import { Ionicons } from '@expo/vector-icons';
import { Tabs } from 'expo-router';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ProfileButton } from '../../src/components/ProfileButton';
import { useAuth } from '../../src/providers/auth-provider';

const TAB_ICONS: Record<string, { focused: string; unfocused: string }> = {
  index:      { focused: 'home',                unfocused: 'home-outline' },
  clients:    { focused: 'people',              unfocused: 'people-outline' },
  projects:   { focused: 'briefcase',           unfocused: 'briefcase-outline' },
  invoices:   { focused: 'receipt',             unfocused: 'receipt-outline' },
  portfolio:  { focused: 'layers',              unfocused: 'layers-outline' },
  lifecycle:  { focused: 'git-branch',          unfocused: 'git-branch-outline' },
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
  portfolio:  'Portfolio',
  lifecycle:  'Lifecycle',
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
          const color = isFocused ? '#f0b23d' : '#64748b';
          const bg = isFocused ? '#f0b23d1a' : 'transparent';

          const onPress = () => {
            const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
            if (!isFocused && !event.defaultPrevented) {
              navigation.navigate(route.name);
            }
          };

          return (
            <Pressable
              key={route.key}
              onPress={onPress}
              style={[styles.tabItem, { backgroundColor: bg }]}
              accessibilityRole="tab"
              accessibilityState={{ selected: isFocused }}
            >
              <Ionicons name={(isFocused ? icons.focused : icons.unfocused) as never} size={20} color={color} />
              <Text style={[styles.tabLabel, { color }]}>{label}</Text>
              {isFocused ? <View style={styles.tabIndicator} /> : null}
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

  const headerOpts = {
    headerStyle: { backgroundColor: '#040d1a' },
    headerTintColor: '#f8fafc',
    headerTitleStyle: { fontWeight: '700' as const, fontSize: 17 },
    headerShadowVisible: false,
    headerRight: () => <ProfileButton />,
  };

  return (
    <Tabs
      tabBar={(props) => <ScrollableTabBar {...props} />}
      screenOptions={headerOpts}
    >
      <Tabs.Screen name="index"      options={{ title: 'IPFlow' }} />
      <Tabs.Screen name="clients"    options={{ title: 'Clients', headerShown: false }} />
      <Tabs.Screen name="projects"   options={{ title: 'Projects', headerShown: false }} />
      <Tabs.Screen name="invoices"   options={{ title: 'Invoices', headerShown: false }} />
      <Tabs.Screen name="portfolio"  options={{ title: 'Portfolio', headerShown: false }} />
      <Tabs.Screen name="lifecycle"  options={{ title: 'Lifecycle', headerShown: false }} />
      <Tabs.Screen name="tasks"      options={{ title: 'Tasks' }} />
      <Tabs.Screen name="attendance" options={{ title: 'Attendance' }} />
      <Tabs.Screen name="inbox"      options={{ title: 'Inbox' }} />
      <Tabs.Screen name="reminders"  options={{ title: 'Reminders' }} />
    </Tabs>
  );
}

const styles = StyleSheet.create({
  tabBarWrapper:  { backgroundColor: '#080e1a', borderTopColor: '#1a2540', borderTopWidth: 1 },
  tabBarScroll:   { flexGrow: 0 },
  tabBarContent:  { alignItems: 'center', flexDirection: 'row', paddingHorizontal: 8, paddingVertical: 4 },
  tabItem:        { alignItems: 'center', borderRadius: 12, gap: 3, justifyContent: 'center', minWidth: 68, paddingHorizontal: 10, paddingVertical: 8, position: 'relative' },
  tabLabel:       { fontSize: 10, fontWeight: '600', letterSpacing: 0.2 },
  tabIndicator:   { backgroundColor: '#f0b23d', borderRadius: 2, bottom: 4, height: 3, position: 'absolute', width: 20 },
});
