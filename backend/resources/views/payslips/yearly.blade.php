<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Payslip Summary</title>
<style>
  body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #1a1a1a; }
  table { width: 100%; border-collapse: collapse; }
  .info-table td { border: 1px solid #999; padding: 4px 6px; vertical-align: top; }
  .info-label { color: #555; font-size: 8px; white-space: nowrap; }
  .company-name { font-size: 17px; font-weight: bold; }
  .section-title { font-weight: bold; background: #eef1f5; padding: 4px 6px; border: 1px solid #999; }
  .grid-table td, .grid-table th { border: 1px solid #999; padding: 3px 5px; }
  .grid-table th { background: #f0f0f0; font-size: 8px; }
  .label-col { width: 140px; }
  .amount { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .na { color: #aaa; text-align: center; }
  .totals td { font-weight: bold; background: #f7f7f7; }
  .net-row td { font-weight: bold; background: #eafaf0; }
  .footer { margin-top: 16px; font-size: 9px; color: #333; }
</style>
</head>
<body>

  <table class="info-table">
    <tr>
      <td colspan="2" style="width:45%"><div class="company-name">{{ $letterhead['company_name'] }}</div></td>
      <td colspan="6"><span class="info-label">Registered Address:</span><br>{{ $letterhead['company_address'] }}</td>
    </tr>
    <tr>
      <td><span class="info-label">Employee #</span><br>{{ $employee->employee_code }}</td>
      <td><span class="info-label">PAN</span><br>{{ $employee->pan_tax_id ?? '—' }}</td>
      <td><span class="info-label">NPS #</span><br>{{ $employee->nps_number ?? '—' }}</td>
      <td colspan="2"><span class="info-label">Date of Birth</span><br>{{ optional($employee->date_of_birth)->format('d/m/Y') ?? '—' }}</td>
      <td colspan="3"><span class="info-label">Date of Joining</span><br>{{ optional($employee->date_of_joining)->format('d/m/Y') ?? '—' }}</td>
    </tr>
    <tr>
      <td colspan="2"><span class="info-label">Name</span><br>{{ $employee->full_name }}</td>
      <td colspan="2"><span class="info-label">Designation</span><br>{{ $employee->designation->title ?? '—' }}</td>
      <td colspan="4"><span class="info-label">Period</span><br>{{ $periodLabel }}</td>
    </tr>
  </table>

  <table class="grid-table" style="margin-top:8px">
    <thead>
      <tr>
        <th class="label-col"></th>
        @foreach ($months as $m)<th class="amount">{{ strtoupper($m['label']) }}</th>@endforeach
        <th class="amount">Total</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="label-col">Hours put in</td>
        @foreach ($months as $m)
          <td class="{{ $m['hours_display'] ? 'amount' : 'na' }}">{{ $m['hours_display'] ?? 'NA' }}</td>
        @endforeach
        <td class="na"></td>
      </tr>

      @foreach ($earningRows as $row)
        <tr>
          <td class="label-col">{{ $row['label'] }}</td>
          @foreach ($row['values'] as $v)
            <td class="{{ $v === null ? 'na' : 'amount' }}">{{ $v === null ? 'NA' : number_format($v, 2) }}</td>
          @endforeach
          <td class="amount">{{ number_format($row['total'], 2) }}</td>
        </tr>
      @endforeach

      @if (count($ctcAddonRows))
        @foreach ($ctcAddonRows as $row)
          <tr>
            <td class="label-col">{{ $row['label'] }}</td>
            @foreach ($row['values'] as $v)
              <td class="{{ $v === null ? 'na' : 'amount' }}">{{ $v === null ? 'NA' : number_format($v, 2) }}</td>
            @endforeach
            <td class="amount">{{ number_format($row['total'], 2) }}</td>
          </tr>
        @endforeach
        <tr class="totals">
          <td class="label-col">Total CTC</td>
          @foreach ($totalCtcRow['values'] as $v)
            <td class="{{ $v === null ? 'na' : 'amount' }}">{{ $v === null ? 'NA' : number_format($v, 2) }}</td>
          @endforeach
          <td class="amount">{{ number_format($totalCtcRow['total'], 2) }}</td>
        </tr>
      @endif

      @foreach ($deductionRows as $row)
        <tr>
          <td class="label-col">{{ $row['label'] }}</td>
          @foreach ($row['values'] as $v)
            <td class="{{ $v === null ? 'na' : 'amount' }}">{{ $v === null ? 'NA' : number_format($v, 2) }}</td>
          @endforeach
          <td class="amount">{{ number_format($row['total'], 2) }}</td>
        </tr>
      @endforeach
      <tr class="totals">
        <td class="label-col">Total Deductions</td>
        @foreach ($totalDeductionsRow['values'] as $v)
          <td class="{{ $v === null ? 'na' : 'amount' }}">{{ $v === null ? 'NA' : number_format($v, 2) }}</td>
        @endforeach
        <td class="amount">{{ number_format($totalDeductionsRow['total'], 2) }}</td>
      </tr>

      <tr class="net-row">
        <td class="label-col">Net Pay</td>
        @foreach ($netPayRow['values'] as $v)
          <td class="{{ $v === null ? 'na' : 'amount' }}">{{ $v === null ? 'NA' : number_format($v, 2) }}</td>
        @endforeach
        <td class="amount">{{ number_format($netPayRow['total'], 2) }}</td>
      </tr>

      @if ($reimbursementsRow['total'] > 0)
        <tr>
          <td class="label-col">Reimbursements (Others)</td>
          @foreach ($reimbursementsRow['values'] as $v)
            <td class="{{ $v === null ? 'na' : 'amount' }}">{{ $v === null || $v == 0 ? '—' : number_format($v, 2) }}</td>
          @endforeach
          <td class="amount">{{ number_format($reimbursementsRow['total'], 2) }}</td>
        </tr>
        <tr class="net-row">
          <td class="label-col">Credited to Bank Account</td>
          @foreach ($creditedRow['values'] as $v)
            <td class="{{ $v === null ? 'na' : 'amount' }}">{{ $v === null ? 'NA' : number_format($v, 2) }}</td>
          @endforeach
          <td class="amount">{{ number_format($creditedRow['total'], 2) }}</td>
        </tr>
      @endif
    </tbody>
  </table>

  <div class="footer">
    <p>EMPLOYEE'S SIGNATURE: ____________________________ &nbsp;&nbsp;&nbsp; <strong>{{ strtoupper($employee->full_name) }}</strong> — {{ $employee->designation->title ?? '' }}</p>
    <p>For any enquiries: accounts@myipstrategy.com &nbsp;&nbsp;&nbsp; Electronically Generated at {{ $generatedAt->format('h:i A') }} UTC on {{ $generatedAt->format('d M Y') }}</p>
    @if ($internalSignature && $internalSignature->signedBy)
      <div style="position:relative;margin-top:12px;margin-left:auto;width:275px;height:62px;border:1px dotted #777;padding:5px 56px 4px 7px;color:#222;background:#fff;text-align:left;font-family:DejaVu Sans,sans-serif">
        <div style="font-size:15px;line-height:17px">Signature valid</div>
        <div style="font-size:12px;line-height:16px;white-space:nowrap">Digitally signed by <strong>{{ $internalSignature->signedBy->name }}</strong></div>
        <div style="font-size:10px;line-height:13px">Date: {{ $internalSignature->signed_at?->timezone('Asia/Kolkata')->format('d/m/Y h:i A') }} IST</div>
        <div style="position:absolute;right:5px;top:1px;font-size:57px;line-height:58px;color:#159447;font-family:DejaVu Sans,sans-serif;transform:rotate(-8deg)">&#10003;</div>
      </div>
    @endif
  </div>

</body>
</html>
