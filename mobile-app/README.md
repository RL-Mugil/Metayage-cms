# IPFlow Mobile

Native mobile frontend for IPFlow, built with Expo and React Native against the existing Laravel backend.

## Current foundation

- Expo Router navigation
- Secure token storage with `expo-secure-store`
- Mobile-specific bearer-token auth via `/api/mobile/*`
- React Query setup for authenticated server state
- Expo push token registration support
- Offline queueing for attendance and task mutations
- Mobile-first modules: home, attendance, tasks, inbox, reminders

## Environment

Create `mobile-app/.env` from `.env.example` and set:

```bash
EXPO_PUBLIC_API_BASE_URL=https://mypl-cms.139-59-85-216.sslip.io
EXPO_PUBLIC_APP_VERSION=1.0.0
EXPO_PUBLIC_EAS_PROJECT_ID=YOUR_EAS_PROJECT_ID
```

Backend production also needs:

```bash
EXPO_ACCESS_TOKEN=your_expo_access_token
```

## Commands

```bash
cd mobile-app
npm start
npm run android
```

## Expected next work

1. Run the `mobile_device_tokens` migration in the deployment environment.
2. Set `EXPO_ACCESS_TOKEN` in backend production env.
3. Replace `YOUR_EAS_PROJECT_ID` with the real Expo/EAS project id.
4. Build on a real Android device and verify push registration plus offline replay.
