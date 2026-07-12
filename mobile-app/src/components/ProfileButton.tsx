import { useRouter } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { useAuth } from '../providers/auth-provider';

export function ProfileButton() {
  const { session, signOut } = useAuth();
  const router = useRouter();
  const name = session?.user.name ?? '';
  const initials = name
    .split(' ')
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? '')
    .join('');

  const handlePress = () => {
    // Navigate to sign out / settings — for now an inline confirm pattern
    // A proper settings screen would be: router.push('/(app)/settings')
    const { Alert } = require('react-native') as typeof import('react-native');
    Alert.alert(
      name,
      session?.user.email,
      [
        { text: 'Cancel', style: 'cancel' },
        { text: 'Sign out', style: 'destructive', onPress: () => void signOut() },
      ],
    );
  };

  return (
    <Pressable onPress={handlePress} style={styles.btn} accessibilityLabel={`Profile: ${name}`} accessibilityRole="button">
      <View style={styles.avatar}>
        <Text style={styles.initials}>{initials || '?'}</Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  btn:      { marginRight: 16, padding: 4 },
  avatar:   { alignItems: 'center', backgroundColor: '#f0b23d', borderRadius: 18, height: 36, justifyContent: 'center', width: 36 },
  initials: { color: '#0d1321', fontSize: 13, fontWeight: '800', letterSpacing: 0.5 },
});
