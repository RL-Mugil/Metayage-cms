// Read-only load test for IPFlow + canpatentit.com (k6).
//
//   k6 run -e TOKENS_FILE=tokens.json -e PROFILE=smoke  loadtest.js     (5 users, 1 minute)
//   k6 run -e TOKENS_FILE=tokens.json -e PROFILE=full   loadtest.js     (0 -> 500 users, 30 minutes)
//
// GET requests only: nothing is created, changed, emailed or sent to Zoho. A separate "health" scenario polls
// /api/health every 5 s; if the server stops answering properly the whole run aborts (kill switch).
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const API = __ENV.API || 'https://login.myipstrategy.com/api';
const SITE = __ENV.SITE || 'https://canpatentit.com';
const TOKENS = JSON.parse(open(__ENV.TOKENS_FILE || 'tokens.json'));
const PROFILE = __ENV.PROFILE || 'smoke';

const healthOk = new Rate('health_ok');
const healthMs = new Trend('health_ms', true);
const rateLimited = new Rate('rate_limited');

const profiles = {
  smoke: [{ duration: '20s', target: 5 }, { duration: '40s', target: 5 }, { duration: '10s', target: 0 }],
  full: [{ duration: '10m', target: 500 }, { duration: '15m', target: 500 }, { duration: '5m', target: 0 }],
};

export const options = {
  scenarios: {
    users: { executor: 'ramping-vus', startVUs: 0, stages: profiles[PROFILE], gracefulRampDown: '30s', exec: 'user' },
    health: { executor: 'constant-vus', vus: 1, duration: PROFILE === 'full' ? '30m' : '70s', exec: 'health' },
  },
  thresholds: {
    // Kill switches: stop the whole test if real users are failing or slow, or the server stops answering.
    'http_req_failed{scenario:users}': [{ threshold: 'rate<0.05', abortOnFail: true, delayAbortEval: '2m' }],
    'http_req_duration{scenario:users}': [{ threshold: 'p(95)<3000', abortOnFail: true, delayAbortEval: '3m' }],
    health_ok: [{ threshold: 'rate>0.8', abortOnFail: true, delayAbortEval: '1m' }],
    health_ms: [{ threshold: 'p(95)<5000', abortOnFail: true, delayAbortEval: '1m' }],
    // Per-endpoint submetrics (informational, never abort) so the summary lists each endpoint separately.
    'http_req_duration{name:dashboard}': ['max>=0'],
    'http_req_duration{name:clients_list}': ['max>=0'],
    'http_req_duration{name:clients_page3}': ['max>=0'],
    'http_req_duration{name:projects_list}': ['max>=0'],
    'http_req_duration{name:tasks_list}': ['max>=0'],
    'http_req_duration{name:invoices_list}': ['max>=0'],
    'http_req_duration{name:notifications}': ['max>=0'],
    'http_req_duration{name:report_client_portfolio}': ['max>=0'],
    'http_req_duration{name:search}': ['max>=0'],
    'http_req_duration{name:canpatentit_home}': ['max>=0'],
  },
  summaryTrendStats: ['avg', 'p(50)', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

// [weight, name, path]  (weights approximate what staff do: lists and dashboards, occasional reports)
const MIX = [
  [10, 'dashboard', '/dashboard/metrics'],
  [20, 'clients_list', '/clients?page=1&per_page=25'],
  [8, 'clients_page3', '/clients?page=3&per_page=25'],
  [20, 'projects_list', '/projects?page=1&per_page=25'],
  [15, 'tasks_list', '/tasks?page=1&per_page=25'],
  [10, 'invoices_list', '/financial/invoices?page=1&per_page=25'],
  [10, 'notifications', '/notifications'],
  [3, 'report_client_portfolio', '/reports/data?type=client-portfolio'],
  [2, 'search', '/search?q=patent'],
];
const TOTAL = MIX.reduce((a, m) => a + m[0], 0);

function pick() {
  let r = Math.random() * TOTAL;
  for (const m of MIX) { r -= m[0]; if (r <= 0) return m; }
  return MIX[0];
}

export function user() {
  const token = TOKENS[(__VU - 1) % TOKENS.length];
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

  // ~1 in 8 visits is a public canpatentit.com page (cached static files behind nginx).
  if (Math.random() < 0.12) {
    const r = http.get(`${SITE}/`, { tags: { name: 'canpatentit_home' } });
    check(r, { 'site 200': (x) => x.status === 200 });
  } else {
    const [, name, path] = pick();
    const r = http.get(`${API}${path}`, { headers, tags: { name } });
    rateLimited.add(r.status === 429);
    check(r, { 'status 200': (x) => x.status === 200 });
  }
  sleep(2 + Math.random() * 4); // think time 2-6 s
}

export function health() {
  const r = http.get(`${API}/health`, { tags: { name: 'health' }, timeout: '8s' });
  healthOk.add(r.status === 200);
  healthMs.add(r.timings.duration);
  sleep(5);
}
