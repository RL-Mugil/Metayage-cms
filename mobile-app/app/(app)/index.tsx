import { useQuery } from '@tanstack/react-query';
import { ActivityIndicator, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Svg, { Circle, G, Path } from 'react-native-svg';

import { getDashboardMetrics, getLifecycleStats } from '../../src/lib/api';
import { useAuth } from '../../src/providers/auth-provider';

const FINANCE_ROLES = ['super_admin', 'partner', 'manager', 'finance'];

function fmt(amount: number): string {
  if (amount >= 10_000_000) return `₹${(amount / 10_000_000).toFixed(1)}Cr`;
  if (amount >= 100_000) return `₹${(amount / 100_000).toFixed(1)}L`;
  if (amount >= 1_000) return `₹${(amount / 1_000).toFixed(0)}k`;
  return `₹${amount.toFixed(0)}`;
}

const STAGE_ORDER = ['Intake', 'Drafting', 'Filing', 'Examination', 'Object Received', 'Granted', 'Renewal'];
const STAGE_COLORS: Record<string, string> = {
  Intake:            '#60a5fa',
  Drafting:          '#a78bfa',
  Filing:            '#f0b23d',
  Examination:       '#fb923c',
  'Object Received': '#f87171',
  Granted:           '#86efac',
  Renewal:           '#34d399',
};

function getTimeOfDay(): string {
  const h = new Date().getHours();
  if (h < 12) return 'morning';
  if (h < 17) return 'afternoon';
  return 'evening';
}

function firstName(name?: string): string {
  return name?.split(' ')[0] ?? '';
}

function roleLabel(role: string): string {
  const map: Record<string, string> = {
    super_admin: 'Super Admin', partner: 'Partner', manager: 'Manager',
    hr: 'HR', finance: 'Finance', associate: 'Associate', paralegal: 'Paralegal', client: 'Client',
  };
  return map[role] ?? role;
}

// ── SVG Donut Chart ──────────────────────────────────────────────────────────

function polarToXY(cx: number, cy: number, r: number, angleDeg: number) {
  const rad = ((angleDeg - 90) * Math.PI) / 180;
  return { x: cx + r * Math.cos(rad), y: cy + r * Math.sin(rad) };
}

function donutSlicePath(cx: number, cy: number, outerR: number, innerR: number, startAngle: number, endAngle: number): string {
  const s1 = polarToXY(cx, cy, outerR, startAngle);
  const e1 = polarToXY(cx, cy, outerR, endAngle);
  const s2 = polarToXY(cx, cy, innerR, endAngle);
  const e2 = polarToXY(cx, cy, innerR, startAngle);
  const large = endAngle - startAngle > 180 ? 1 : 0;
  return [
    `M ${s1.x} ${s1.y}`,
    `A ${outerR} ${outerR} 0 ${large} 1 ${e1.x} ${e1.y}`,
    `L ${s2.x} ${s2.y}`,
    `A ${innerR} ${innerR} 0 ${large} 0 ${e2.x} ${e2.y}`,
    'Z',
  ].join(' ');
}

interface DonutSlice { value: number; color: string; label: string }

function DonutChart({ slices, size = 160 }: { slices: DonutSlice[]; size?: number }) {
  const cx = size / 2;
  const cy = size / 2;
  const outerR = size / 2 - 8;
  const innerR = outerR * 0.58;
  const total = slices.reduce((s, d) => s + d.value, 0);
  if (total === 0) return null;

  let start = 0;
  const paths: Array<{ d: string; color: string }> = [];
  slices.forEach((sl) => {
    const sweep = (sl.value / total) * 359.99;
    const end = start + sweep;
    paths.push({ d: donutSlicePath(cx, cy, outerR, innerR, start, end), color: sl.color });
    start = end;
  });

  return (
    <Svg width={size} height={size}>
      <G>
        {paths.map((p, i) => (
          <Path key={i} d={p.d} fill={p.color} opacity={0.9} />
        ))}
        <Circle cx={cx} cy={cy} r={innerR - 2} fill="#0b1829" />
      </G>
    </Svg>
  );
}

// ── KPI Card ──────────────────────────────────────────────────────────────────

function KPICard({ label, value, color, delta, glow }: {
  label: string; value: string | number; color: string; delta?: string | null; glow?: boolean;
}) {
  return (
    <View style={[s.kpiCard, glow && { shadowColor: color, shadowOpacity: 0.35, shadowRadius: 12, elevation: 8 }]}>
      <View style={[s.kpiAccent, { backgroundColor: color }]} />
      <Text style={s.kpiLabel}>{label}</Text>
      <Text style={[s.kpiValue, { color }]}>{String(value)}</Text>
      {delta ? <Text style={s.kpiDelta} numberOfLines={2}>{delta}</Text> : null}
    </View>
  );
}

// ── Main Screen ───────────────────────────────────────────────────────────────

export default function DashboardScreen() {
  const { session } = useAuth();
  const token = session!.token;
  const role = session!.user.role;
  const canSeeFinance = FINANCE_ROLES.includes(role);

  const metricsQuery = useQuery({
    queryKey: ['dashboard-metrics'],
    queryFn: () => getDashboardMetrics(token),
    staleTime: 60_000,
  });

  const pipelineQuery = useQuery({
    queryKey: ['lifecycle-stats'],
    queryFn: () => getLifecycleStats(token),
    staleTime: 60_000,
  });

  const metrics = metricsQuery.data?.metrics;
  const statsMap = pipelineQuery.data ?? {};

  const pipelineStages = STAGE_ORDER
    .filter((s) => statsMap[s] != null && (statsMap[s] as number) > 0)
    .concat(Object.keys(statsMap).filter((s) => !STAGE_ORDER.includes(s) && (statsMap[s] as number) > 0))
    .map((s) => ({ stage_name: s, count: statsMap[s] as number }));

  const maxCount = Math.max(...pipelineStages.map((st) => st.count), 1);
  const totalInPipeline = pipelineStages.reduce((sum, st) => sum + st.count, 0);

  const donutSlices: DonutSlice[] = pipelineStages.slice(0, 6).map((st) => ({
    label: st.stage_name,
    value: st.count,
    color: STAGE_COLORS[st.stage_name] ?? '#94a3b8',
  }));

  const refreshing = metricsQuery.isRefetching || pipelineQuery.isRefetching;
  const onRefresh = () => {
    void metricsQuery.refetch();
    void pipelineQuery.refetch();
  };

  return (
    <SafeAreaView style={s.safe} edges={['bottom']}>
      <ScrollView
        contentContainerStyle={s.container}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#f0b23d" />}
        showsVerticalScrollIndicator={false}
      >
        {/* Greeting */}
        <View style={s.greeting}>
          <Text style={s.eyebrow}>Good {getTimeOfDay()}</Text>
          <Text style={s.greetName}>{firstName(session?.user.name)}</Text>
          <View style={s.rolePill}>
            <View style={s.roleDot} />
            <Text style={s.rolePillText}>{roleLabel(role)}</Text>
          </View>
        </View>

        {/* KPI Grid */}
        {metricsQuery.isLoading ? (
          <ActivityIndicator color="#f0b23d" style={{ marginTop: 8 }} />
        ) : (
          <View style={s.kpiGrid}>
            <KPICard label="Active Matters" value={metrics?.active_matters ?? 0} color="#f0b23d" delta={metrics?.active_matters_delta as string | null | undefined} glow />
            <KPICard label="Clients" value={metrics?.clients ?? 0} color="#60a5fa" delta={metrics?.clients_delta as string | null | undefined} />
            <KPICard label="Pending Tasks" value={metrics?.pending_tasks ?? 0} color="#a78bfa" />
            {canSeeFinance && metrics?.wip_balance != null
              ? <KPICard label="WIP Balance" value={fmt(metrics.wip_balance)} color="#fbbf24" />
              : null}
            {canSeeFinance && metrics?.received_payments != null
              ? <KPICard label="Revenue" value={fmt(metrics.received_payments)} color="#86efac" delta={metrics?.revenue_delta as string | null | undefined} />
              : null}
            {canSeeFinance && metrics?.realization_rate != null
              ? <KPICard label="Realization" value={`${Math.round(metrics.realization_rate)}%`} color="#fb923c" />
              : null}
          </View>
        )}

        {/* Pipeline Chart */}
        <View style={s.chartCard}>
          <View style={s.chartHeader}>
            <View>
              <Text style={s.sectionLabel}>Pipeline Distribution</Text>
              <Text style={s.chartSubtitle}>{totalInPipeline} matters in progress</Text>
            </View>
            <View style={s.totalBadge}>
              <Text style={s.totalBadgeText}>{totalInPipeline}</Text>
            </View>
          </View>

          {pipelineQuery.isLoading ? (
            <ActivityIndicator color="#f0b23d" style={{ marginVertical: 24 }} />
          ) : pipelineStages.length === 0 ? (
            <View style={s.emptyChart}>
              <Text style={s.emptyText}>No matters currently in progress</Text>
            </View>
          ) : (
            <View style={s.chartBody}>
              {/* Donut chart + legend */}
              <View style={s.chartRow}>
                <DonutChart slices={donutSlices} size={152} />
                <View style={s.legend}>
                  {pipelineStages.slice(0, 6).map((st) => (
                    <View key={st.stage_name} style={s.legendItem}>
                      <View style={[s.legendDot, { backgroundColor: STAGE_COLORS[st.stage_name] ?? '#94a3b8' }]} />
                      <Text style={s.legendName} numberOfLines={1}>{st.stage_name}</Text>
                      <Text style={[s.legendCount, { color: STAGE_COLORS[st.stage_name] ?? '#94a3b8' }]}>{st.count}</Text>
                    </View>
                  ))}
                </View>
              </View>

              {/* Stacked distribution bar */}
              <View style={s.stackBar}>
                {pipelineStages.map((st) => (
                  <View
                    key={st.stage_name}
                    style={[s.stackSegment, {
                      flex: st.count,
                      backgroundColor: STAGE_COLORS[st.stage_name] ?? '#94a3b8',
                    }]}
                  />
                ))}
              </View>

              {/* Bar breakdown */}
              <View style={s.bars}>
                {pipelineStages.map((st) => {
                  const color = STAGE_COLORS[st.stage_name] ?? '#94a3b8';
                  const pct = (st.count / maxCount) * 100;
                  return (
                    <View key={st.stage_name} style={s.barRow}>
                      <Text style={s.barLabel} numberOfLines={1}>{st.stage_name}</Text>
                      <View style={s.barTrack}>
                        <View style={[s.barFill, { width: `${pct}%` as any, backgroundColor: color + 'dd' }]} />
                      </View>
                      <Text style={[s.barCount, { color }]}>{st.count}</Text>
                    </View>
                  );
                })}
              </View>
            </View>
          )}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const s = StyleSheet.create({
  safe:           { backgroundColor: '#040d1a', flex: 1 },
  container:      { gap: 20, paddingBottom: 40, paddingTop: 4 },

  // Greeting
  greeting:       { gap: 4, paddingHorizontal: 20, paddingTop: 16 },
  eyebrow:        { color: '#334155', fontSize: 13, fontWeight: '600', letterSpacing: 0.5 },
  greetName:      { color: '#f1f5f9', fontSize: 32, fontWeight: '800', letterSpacing: -1 },
  rolePill:       { alignItems: 'center', alignSelf: 'flex-start', backgroundColor: '#0f1e38', borderColor: 'rgba(100,160,255,0.15)', borderRadius: 12, borderWidth: 1, flexDirection: 'row', gap: 6, marginTop: 6, paddingHorizontal: 12, paddingVertical: 5 },
  roleDot:        { backgroundColor: '#f0b23d', borderRadius: 4, height: 6, width: 6 },
  rolePillText:   { color: '#7090c0', fontSize: 12, fontWeight: '700', letterSpacing: 0.3 },

  // KPI Grid
  kpiGrid:        { flexDirection: 'row', flexWrap: 'wrap', gap: 10, paddingHorizontal: 16 },
  kpiCard:        {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 20,
    borderTopColor: 'rgba(255,255,255,0.1)',
    borderWidth: 1,
    flex: 1,
    gap: 5,
    minWidth: '44%',
    overflow: 'hidden',
    padding: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 8 },
    shadowOpacity: 0.4,
    shadowRadius: 16,
    elevation: 6,
  },
  kpiAccent:      { borderRadius: 4, height: 3, marginBottom: 6, width: 28 },
  kpiLabel:       { color: '#334155', fontSize: 10, fontWeight: '800', letterSpacing: 1.2, textTransform: 'uppercase' },
  kpiValue:       { fontSize: 28, fontWeight: '800', letterSpacing: -0.5 },
  kpiDelta:       { color: '#334155', fontSize: 11, lineHeight: 15, marginTop: 2 },

  // Chart card
  chartCard:      {
    backgroundColor: '#0b1829',
    borderColor: 'rgba(100,160,255,0.1)',
    borderRadius: 24,
    borderTopColor: 'rgba(255,255,255,0.08)',
    borderWidth: 1,
    marginHorizontal: 16,
    overflow: 'hidden',
    padding: 20,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 12 },
    shadowOpacity: 0.5,
    shadowRadius: 24,
    elevation: 10,
  },
  chartHeader:    { alignItems: 'flex-start', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 20 },
  sectionLabel:   { color: '#f1f5f9', fontSize: 16, fontWeight: '800' },
  chartSubtitle:  { color: '#334155', fontSize: 12, marginTop: 2 },
  totalBadge:     { alignItems: 'center', backgroundColor: 'rgba(240,178,61,0.12)', borderColor: 'rgba(240,178,61,0.25)', borderRadius: 14, borderWidth: 1, height: 44, justifyContent: 'center', width: 44 },
  totalBadgeText: { color: '#f0b23d', fontSize: 16, fontWeight: '800' },

  chartBody:      { gap: 16 },
  chartRow:       { alignItems: 'center', flexDirection: 'row', gap: 16 },
  legend:         { flex: 1, gap: 8 },
  legendItem:     { alignItems: 'center', flexDirection: 'row', gap: 8 },
  legendDot:      { borderRadius: 4, height: 8, width: 8 },
  legendName:     { color: '#64748b', flex: 1, fontSize: 12, fontWeight: '600' },
  legendCount:    { fontSize: 14, fontWeight: '800', minWidth: 24, textAlign: 'right' },

  stackBar:       { borderRadius: 6, flexDirection: 'row', gap: 2, height: 6, overflow: 'hidden' },
  stackSegment:   { borderRadius: 3 },

  bars:           { gap: 10 },
  barRow:         { alignItems: 'center', flexDirection: 'row', gap: 10 },
  barLabel:       { color: '#64748b', fontSize: 12, fontWeight: '600', width: 110 },
  barTrack:       { backgroundColor: '#0d1321', borderRadius: 4, flex: 1, height: 6, overflow: 'hidden' },
  barFill:        { borderRadius: 4, height: '100%' },
  barCount:       { fontSize: 14, fontWeight: '800', minWidth: 28, textAlign: 'right' },

  emptyChart:     { alignItems: 'center', paddingVertical: 24 },
  emptyText:      { color: '#334155', fontSize: 14 },
});
