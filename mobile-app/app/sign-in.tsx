import { zodResolver } from '@hookform/resolvers/zod';
import { useRouter } from 'expo-router';
import { Controller, useForm } from 'react-hook-form';
import { useEffect } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { z } from 'zod';

import { useAuth } from '../src/providers/auth-provider';

const schema = z.object({
  email: z.string().email('Enter a valid email address.'),
  password: z.string().min(1, 'Password is required.'),
});

type FormValues = z.infer<typeof schema>;

export default function SignInScreen() {
  const router = useRouter();
  const { signIn, session, isHydrating } = useAuth();
  const {
    control,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      email: '',
      password: '',
    },
  });

  useEffect(() => {
    if (!isHydrating && session) {
      router.replace('/(app)');
    }
  }, [isHydrating, router, session]);

  const onSubmit = handleSubmit(async (values) => {
    await signIn(values);
  });

  return (
    <SafeAreaView style={styles.safeArea}>
      <View style={styles.container}>
        <View style={styles.header}>
          <Text style={styles.eyebrow}>IPFlow Mobile</Text>
          <Text style={styles.title}>Sign in to your firm workspace</Text>
          <Text style={styles.subtitle}>
            Start with mobile-native workflows: attendance, tasks, approvals, reminders, and notifications.
          </Text>
        </View>

        <View style={styles.card}>
          <Controller
            control={control}
            name="email"
            render={({ field: { onChange, onBlur, value } }) => (
              <View style={styles.field}>
                <Text style={styles.label}>Email</Text>
                <TextInput
                  autoCapitalize="none"
                  autoComplete="email"
                  keyboardType="email-address"
                  onBlur={onBlur}
                  onChangeText={onChange}
                  placeholder="you@company.com"
                  placeholderTextColor="#7c8aa5"
                  style={styles.input}
                  value={value}
                />
                {errors.email ? <Text style={styles.error}>{errors.email.message}</Text> : null}
              </View>
            )}
          />

          <Controller
            control={control}
            name="password"
            render={({ field: { onChange, onBlur, value } }) => (
              <View style={styles.field}>
                <Text style={styles.label}>Password</Text>
                <TextInput
                  autoCapitalize="none"
                  onBlur={onBlur}
                  onChangeText={onChange}
                  placeholder="Enter your password"
                  placeholderTextColor="#7c8aa5"
                  secureTextEntry
                  style={styles.input}
                  value={value}
                />
                {errors.password ? <Text style={styles.error}>{errors.password.message}</Text> : null}
              </View>
            )}
          />

          <Pressable disabled={isSubmitting} onPress={onSubmit} style={styles.button}>
            {isSubmitting ? <ActivityIndicator color="#ffffff" /> : <Text style={styles.buttonText}>Sign in</Text>}
          </Pressable>
        </View>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: {
    flex: 1,
    backgroundColor: '#0d1321',
  },
  container: {
    flex: 1,
    justifyContent: 'center',
    paddingHorizontal: 24,
    gap: 24,
  },
  header: {
    gap: 10,
  },
  eyebrow: {
    color: '#f0b23d',
    fontSize: 13,
    fontWeight: '700',
    letterSpacing: 1,
    textTransform: 'uppercase',
  },
  title: {
    color: '#f8fafc',
    fontSize: 30,
    fontWeight: '700',
    lineHeight: 36,
  },
  subtitle: {
    color: '#9fb0d3',
    fontSize: 15,
    lineHeight: 22,
  },
  card: {
    backgroundColor: '#131c31',
    borderColor: '#21304f',
    borderRadius: 24,
    borderWidth: 1,
    padding: 20,
    gap: 16,
  },
  field: {
    gap: 8,
  },
  label: {
    color: '#dbe4ff',
    fontSize: 14,
    fontWeight: '600',
  },
  input: {
    backgroundColor: '#0b1120',
    borderColor: '#2a3c61',
    borderRadius: 16,
    borderWidth: 1,
    color: '#f8fafc',
    fontSize: 16,
    paddingHorizontal: 16,
    paddingVertical: 14,
  },
  error: {
    color: '#fca5a5',
    fontSize: 13,
  },
  button: {
    alignItems: 'center',
    backgroundColor: '#2864ff',
    borderRadius: 16,
    justifyContent: 'center',
    minHeight: 54,
    marginTop: 8,
  },
  buttonText: {
    color: '#ffffff',
    fontSize: 16,
    fontWeight: '700',
  },
});
