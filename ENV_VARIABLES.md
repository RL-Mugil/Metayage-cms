# Environment Variables — MYPL-CMS

All `.env` files are included alongside this file. This document maps every variable across all services.

---

## backend/.env (Laravel API)

| Variable | Example / Default | Description |
|---|---|---|
| `APP_NAME` | `Laravel` | Application name |
| `APP_ENV` | `local` / `production` | Runtime environment |
| `APP_KEY` | `base64:...` | Laravel encryption key — generate with `php artisan key:generate` |
| `APP_DEBUG` | `true` / `false` | Debug mode — must be `false` in production |
| `APP_URL` | `http://localhost` | Full public URL of the API |
| `APP_LOCALE` | `en` | Default locale |
| `APP_FALLBACK_LOCALE` | `en` | Fallback locale |
| `APP_FAKER_LOCALE` | `en_US` | Faker locale for seeders |
| `APP_MAINTENANCE_DRIVER` | `file` | Maintenance mode driver |
| `BCRYPT_ROUNDS` | `12` | Password hashing rounds |
| `LOG_CHANNEL` | `stack` | Log channel |
| `LOG_STACK` | `single` | Log stack driver |
| `LOG_DEPRECATIONS_CHANNEL` | `null` | Deprecation log channel |
| `LOG_LEVEL` | `debug` | Minimum log level |
| `DB_CONNECTION` | `pgsql` | Database driver (pgsql / sqlite / mysql) |
| `DB_HOST` | `127.0.0.1` | Database host |
| `DB_PORT` | `5432` | Database port |
| `DB_DATABASE` | `ipflow` | Database name |
| `DB_USERNAME` | `postgres` | Database user |
| `DB_PASSWORD` | `password` | Database password |
| `SESSION_DRIVER` | `database` | Session driver |
| `SESSION_LIFETIME` | `120` | Session lifetime in minutes |
| `SESSION_ENCRYPT` | `false` | Encrypt session data |
| `SESSION_PATH` | `/` | Session cookie path |
| `SESSION_DOMAIN` | `null` | Session cookie domain |
| `BROADCAST_CONNECTION` | `reverb` / `log` | WebSocket broadcast driver |
| `FILESYSTEM_DISK` | `local` | Default filesystem disk |
| `QUEUE_CONNECTION` | `database` | Queue driver |
| `CACHE_STORE` | `database` | Cache driver |
| `MEMCACHED_HOST` | `127.0.0.1` | Memcached host |
| `REDIS_CLIENT` | `phpredis` | Redis client library |
| `REDIS_HOST` | `127.0.0.1` | Redis host |
| `REDIS_PASSWORD` | `null` | Redis password |
| `REDIS_PORT` | `6379` | Redis port |
| `MAIL_MAILER` | `smtp` / `log` | Mail driver |
| `MAIL_SCHEME` | `tls` | Mail TLS scheme |
| `MAIL_HOST` | `sandbox.smtp.mailtrap.io` | SMTP host |
| `MAIL_PORT` | `2525` | SMTP port |
| `MAIL_USERNAME` | — | SMTP username |
| `MAIL_PASSWORD` | — | SMTP password |
| `MAIL_FROM_ADDRESS` | `mugilvannan@myipstrategy.com` | From email address |
| `MAIL_FROM_NAME` | `MyIPStrategy` | From name |
| `MONITORING_WEBHOOK` | — | Webhook URL for monitoring alerts |
| `GROQ_API_KEY` | `gsk_...` | **SECRET** — Groq LLM API key (rotate after export) |
| `GROQ_MODEL` | `meta-llama/llama-4-scout-17b-16e-instruct` | Groq model ID |
| `EXPO_ACCESS_TOKEN` | — | Expo push notifications token |
| `AWS_ACCESS_KEY_ID` | — | AWS access key (S3 storage) |
| `AWS_SECRET_ACCESS_KEY` | — | AWS secret key |
| `AWS_DEFAULT_REGION` | `us-east-1` | AWS region |
| `AWS_BUCKET` | — | S3 bucket name |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | Use path-style S3 endpoints |
| `REVERB_APP_ID` | `mypl-ipflow` | Laravel Reverb WebSocket app ID |
| `REVERB_APP_KEY` | `mypl-ipflow-key` | Reverb app key |
| `REVERB_APP_SECRET` | `change-me-in-production` | **SECRET** — Reverb app secret |
| `REVERB_SERVER_HOST` | `127.0.0.1` | Reverb internal server host |
| `REVERB_SERVER_PORT` | `8080` | Reverb internal server port |
| `REVERB_HOST` | `localhost` | Reverb public host (for clients) |
| `REVERB_PORT` | `8080` | Reverb public port |
| `REVERB_SCHEME` | `http` | Reverb scheme (http / https) |
| `REVERB_ALLOWED_ORIGINS` | `localhost` | Allowed CORS origins for Reverb |
| `VITE_APP_NAME` | `${APP_NAME}` | Injected into frontend build |
| `VITE_REVERB_APP_KEY` | `${REVERB_APP_KEY}` | Reverb key for frontend |
| `VITE_REVERB_HOST` | `${REVERB_HOST}` | Reverb host for frontend |
| `VITE_REVERB_PORT` | `${REVERB_PORT}` | Reverb port for frontend |
| `VITE_REVERB_SCHEME` | `${REVERB_SCHEME}` | Reverb scheme for frontend |

---

## mobile-app/.env (Expo React Native)

| Variable | Example / Default | Description |
|---|---|---|
| `EXPO_PUBLIC_API_BASE_URL` | `https://mypl-cms.139-59-85-216.sslip.io` | Backend API base URL |
| `EXPO_PUBLIC_APP_VERSION` | `1.0.0` | App version string |
| `EXPO_PUBLIC_EAS_PROJECT_ID` | `ef9b52a7-d374-43d9-84b4-51e85cbb94a3` | EAS (Expo Application Services) project ID |

---

## ai-sidecar/.env (Python FastAPI Sidecar)

| Variable | Example / Default | Description |
|---|---|---|
| `GEMINI_API_KEY` | — | **SECRET** — Google Gemini API key |
| `GROQ_API_KEY` | — | **SECRET** — Groq API key (same key as backend) |
| `DB_HOST` | `127.0.0.1` | PostgreSQL host |
| `DB_PORT` | `5432` | PostgreSQL port |
| `DB_NAME` | `ipflow` | Database name |
| `DB_USER` | `postgres` | Database user |
| `DB_PASS` | `password` | Database password |
| `PORT` | `8001` | Sidecar HTTP port |

---

## Security Notes

- `GROQ_API_KEY` in `backend/.env` is a live key — **rotate it** after this USB transfer.
- `APP_KEY` in `backend/.env` is a live Laravel encryption key — regenerate on new deployments with `php artisan key:generate`.
- Never commit `.env` (only `.env.example`) to a public GitHub repository.
- Add `.env` to `.gitignore` before pushing to GitHub.
