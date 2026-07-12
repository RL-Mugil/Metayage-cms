import { Stack } from 'expo-router';

import { ProfileButton } from '../../../src/components/ProfileButton';

const headerOpts = {
  headerStyle: { backgroundColor: '#040d1a' },
  headerTintColor: '#f8fafc',
  headerTitleStyle: { fontWeight: '700' as const, fontSize: 17 },
  headerShadowVisible: false,
  headerRight: () => <ProfileButton />,
};

export default function InvoicesLayout() {
  return (
    <Stack screenOptions={headerOpts}>
      <Stack.Screen name="index" options={{ title: 'Invoices' }} />
    </Stack>
  );
}
