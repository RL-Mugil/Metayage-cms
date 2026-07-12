import { Redirect } from 'expo-router';

import { useAuth } from '../src/providers/auth-provider';

export default function IndexScreen() {
  const { session, isHydrating } = useAuth();

  if (isHydrating) {
    return null;
  }

  return <Redirect href={session ? '/(app)' : '/sign-in'} />;
}
