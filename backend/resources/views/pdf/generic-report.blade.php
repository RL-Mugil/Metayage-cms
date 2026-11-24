<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 92px 30px 78px 30px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: {{ count($columns) > 9 ? '7.4' : '8.6' }}px; color: #1f2937; }
  header { position: fixed; top: -78px; left: 0; right: 0; height: 60px; text-align: center; }
  header img { height: 44px; }
  footer { position: fixed; bottom: -64px; left: 0; right: 0; height: 56px; text-align: center; font-size: 8px; color: #374151; border-top: 1px solid #d1d5db; padding-top: 5px; }
  footer .co { color: #15803d; font-weight: bold; font-size: 10px; }
  footer .page:after { content: "Page " counter(page); }
  h1 { font-size: 15px; margin: 0 0 2px; }
  .muted { color: #6b7280; }
  .tiles { width: 100%; border-collapse: separate; border-spacing: 5px; margin: 8px -5px 6px; }
  .tile { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 6px; padding: 6px 7px; }
  .tile b { display: block; font-size: 12px; }
  .tile span { font-size: 7.4px; text-transform: uppercase; color: #6b7280; }
  table.data { width: 100%; border-collapse: collapse; }
  table.data th { background: #1e3a5f; color: #fff; font-size: 7.2px; text-transform: uppercase; padding: 4px 3px; text-align: left; }
  table.data td { padding: 3px 3px; border-bottom: 1px solid #eef2f7; vertical-align: top; word-wrap: break-word; }
  table.data tr:nth-child(even) td { background: #f9fafb; }
  .r { text-align: right; white-space: nowrap; }
  .more { font-size: 7.6px; color: #6b7280; font-style: italic; padding-top: 3px; }
  .seal { margin-top: 12px; width: 260px; margin-left: auto; }
  .seal td { vertical-align: middle; }
  .seal img { width: 52px; height: 52px; }
  .seal .n { font-weight: bold; font-size: 8.6px; color: #92400e; }
  .seal .n.u { color: #475569; }
  .seal .m { font-size: 7.4px; color: #6b7280; line-height: 1.35; }
</style>
</head>
<body>
@php
  $num = fn ($v) => is_numeric($v) ? number_format((float) $v, fmod((float) $v, 1.0) == 0.0 ? 0 : 2) : $v;
@endphp
<header>@if ($letterhead['logo_data_uri'])<img src="{{ $letterhead['logo_data_uri'] }}" alt="">@endif</header>
<footer>
  <div class="co">{{ $letterhead['company_name'] }}</div>
  <div>{{ $letterhead['address'] }}</div>
  <div>☎ {{ $letterhead['phone'] }} | Web: {{ $letterhead['web'] }} &nbsp;·&nbsp; <span class="page"></span></div>
</footer>

<h1>{{ $title }}</h1>
<div class="muted">{{ $filterLine }} · Generated {{ now()->format('d M Y, H:i') }}</div>

<table class="tiles"><tr>
  <td class="tile"><b>{{ number_format($total) }}</b><span>Rows</span></td>
  @foreach ($totals as $column => $value)
    <td class="tile"><b>{{ $num($value) }}</b><span>Total {{ \App\Services\Reports\GenericReport::heading($column) }}</span></td>
  @endforeach
</tr></table>

<table class="data">
  <tr>@foreach ($columns as $c)<th class="{{ in_array($c, $numeric, true) ? 'r' : '' }}">{{ \App\Services\Reports\GenericReport::heading($c) }}</th>@endforeach</tr>
  @forelse ($rows as $row)
    <tr>@foreach ($columns as $c)<td class="{{ in_array($c, $numeric, true) ? 'r' : '' }}">{{ is_scalar($row[$c] ?? null) ? $num($row[$c]) : (isset($row[$c]) ? json_encode($row[$c]) : '') }}</td>@endforeach</tr>
  @empty
    <tr><td colspan="{{ max(1, count($columns)) }}" class="muted">No rows for these filters.</td></tr>
  @endforelse
</table>
@if ($cut > 0)<div class="more">{{ number_format($cut) }} more rows — see the Excel export.</div>@endif

<table class="seal"><tr>
  <td width="58"><img src="{{ $seal }}" alt=""></td>
  <td>
    @if ($signature)
      <div class="n">Digitally signed · {{ $signature['name'] }}</div>
      <div class="m">{{ $signature['role'] }} · {{ $signature['at'] }}<br>Code {{ $signature['code'] }} · verify at {{ $signature['url'] }}</div>
    @else
      <div class="n u">Unsigned draft</div>
      <div class="m">Generated without a signature PIN.</div>
    @endif
  </td>
</tr></table>
</body>
</html>
