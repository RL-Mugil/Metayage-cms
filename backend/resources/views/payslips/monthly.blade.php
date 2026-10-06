<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Payslip</title>
<style>
  body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1a1a1a; }
  table { width: 100%; border-collapse: collapse; }
  .info-table td { border: 1px solid #999; padding: 5px 8px; vertical-align: top; }
  .info-label { color: #555; font-size: 9px; white-space: nowrap; }
  .company-name { font-size: 20px; font-weight: bold; }
  .company-address { font-size: 9px; color: #444; }
  .section-title { font-weight: bold; background: #eef1f5; padding: 5px 8px; border: 1px solid #999; }
  .line-table td { border: 1px solid #999; padding: 5px 8px; }
  .amount { text-align: right; font-variant-numeric: tabular-nums; }
  .sub-label { color: #777; font-size: 9px; }
  .totals td { font-weight: bold; background: #f7f7f7; }
  .net-row td { font-weight: bold; background: #eafaf0; font-size: 12px; }
  .footer { margin-top: 24px; font-size: 10px; color: #333; }
  .footer p { margin: 3px 0; }
</style>
</head>
<body>

  <table class="info-table">
    <tr>
      <td colspan="2" style="width:45%">
        <div class="company-name">{{ $letterhead['company_name'] }}</div>
      </td>
      <td colspan="6">
        <span class="info-label">Registered Address:</span><br>
        {{ $letterhead['company_address'] }}
      </td>
    </tr>
    <tr>
      <td><span class="info-label">Employee #</span><br>{{ $slip->employee_code ?? '—' }}</td>
      <td><span class="info-label">PAN</span><br>{{ $employee->pan_tax_id ?? '—' }}</td>
      <td><span class="info-label">NPS #</span><br>{{ $employee->nps_number ?? '—' }}</td>
      <td colspan="2"><span class="info-label">Date of Birth</span><br>{{ optional($employee->date_of_birth)->format('d/m/Y') ?? '—' }}</td>
      <td colspan="3"><span class="info-label">Date of Joining</span><br>{{ optional($employee->date_of_joining)->format('d/m/Y') ?? '—' }}</td>
    </tr>
    <tr>
      <td><span class="info-label">Bank</span><br>{{ $employee->bank_name ?? '—' }}</td>
      <td><span class="info-label">Account</span><br>{{ $employee->bank_account_number ?? '—' }}</td>
      <td><span class="info-label">IFSC</span><br>{{ $employee->bank_ifsc_code ?? '—' }}</td>
      <td colspan="5"><span class="info-label">Period</span><br>{{ $run->period->format('F Y') }} &middot; {{ $run->status }}</td>
    </tr>
    <tr>
      <td colspan="2"><span class="info-label">Name</span><br>{{ $employee->full_name }}</td>
      <td colspan="2"><span class="info-label">Designation</span><br>{{ $employee->designation->title ?? '—' }}</td>
      <td colspan="2"><span class="info-label">Work Mail</span><br>{{ $employee->work_email ?? '—' }}</td>
      <td colspan="2"><span class="info-label">Phone</span><br>{{ $employee->phone ?? '—' }}</td>
    </tr>
  </table>

  <table class="line-table" style="margin-top:10px">
    <tr><td class="section-title" colspan="2">Earnings</td></tr>
    @foreach ($earnings as $c)
      <tr>
        <td>{{ $c->name }}@if($percentageLabel($c))<br><span class="sub-label">({{ $percentageLabel($c) }})</span>@endif</td>
        <td class="amount" style="width:120px">{{ number_format((float) $c->amount, 2) }}</td>
      </tr>
    @endforeach

    @if ($ctcAddons->count())
      <tr><td class="section-title" colspan="2">CTC Add-ons (employer cost — not deducted from your pay)</td></tr>
      @foreach ($ctcAddons as $c)
        <tr>
          <td>{{ $c->name }}@if($percentageLabel($c))<br><span class="sub-label">({{ $percentageLabel($c) }})</span>@endif</td>
          <td class="amount">{{ number_format((float) $c->amount, 2) }}</td>
        </tr>
      @endforeach
      <tr class="totals"><td>Total CTC</td><td class="amount">{{ number_format($totalCtc, 2) }}</td></tr>
    @endif

    <tr><td class="section-title" colspan="2">Deductions</td></tr>
    @if ((float) $slip->lop_deduction > 0)
      <tr><td>Loss of Pay ({{ $slip->lop_days }} days)</td><td class="amount">{{ number_format((float) $slip->lop_deduction, 2) }}</td></tr>
    @endif
    @if ((float) $slip->pf_employee > 0)
      <tr><td>Provident Fund (PF)</td><td class="amount">{{ number_format((float) $slip->pf_employee, 2) }}</td></tr>
    @endif
    @if ((float) $slip->esi_employee > 0)
      <tr><td>ESI</td><td class="amount">{{ number_format((float) $slip->esi_employee, 2) }}</td></tr>
    @endif
    <tr><td>Professional Tax</td><td class="amount">{{ number_format((float) $slip->professional_tax, 2) }}</td></tr>
    <tr><td>Income Tax (TDS)</td><td class="amount">{{ number_format((float) $slip->tds, 2) }}</td></tr>
    @if ((float) $slip->educational_cess > 0)
      <tr><td>Educational Cess</td><td class="amount">{{ number_format((float) $slip->educational_cess, 2) }}</td></tr>
    @endif
    @if ((float) $slip->health_insurance > 0)
      <tr><td>Health Insurance</td><td class="amount">{{ number_format((float) $slip->health_insurance, 2) }}</td></tr>
    @endif
    @if ((float) $slip->other_deductions > 0)
      <tr><td>Other Deductions</td><td class="amount">{{ number_format((float) $slip->other_deductions, 2) }}</td></tr>
    @endif
    <tr class="totals"><td>Total Deductions</td><td class="amount">{{ number_format((float) $slip->total_deductions, 2) }}</td></tr>

    <tr class="net-row"><td>Net Pay</td><td class="amount">{{ number_format($netPayBeforeReimbursement, 2) }}</td></tr>
    @if ((float) $slip->reimbursements > 0)
      <tr><td>Reimbursements</td><td class="amount">{{ number_format((float) $slip->reimbursements, 2) }}</td></tr>
      <tr class="net-row"><td>Credited to Bank Account</td><td class="amount">{{ number_format($creditedToBank, 2) }}</td></tr>
    @endif
  </table>

  <div class="footer">
    <p>EMPLOYEE'S SIGNATURE: ____________________________</p>
    <p><strong>{{ strtoupper($employee->full_name) }}</strong> — {{ $employee->designation->title ?? '' }}</p>
    <p>For any enquiries: accounts@myipstrategy.com</p>
    @if ($internalSignature && $internalSignature->signedBy)
      <div style="margin-top:18px;margin-left:auto;width:245px;border:1px solid #39a96b;border-radius:6px;padding:8px;color:#146c3a;background:#f0fff5;text-align:right">
        <div style="font-size:24px;font-weight:bold;color:#168447">✓</div>
        <div style="font-size:10px;font-weight:bold">INTERNALLY SIGNED &amp; VERIFIED</div>
        <div style="font-size:9px">Accounts: {{ $internalSignature->signedBy->name }}</div>
        <div style="font-size:8px">{{ $internalSignature->signed_at?->format('d M Y H:i T') }} · {{ $internalSignature->verification_code }}</div>
        <div style="font-size:8px">Internal approval signature — not a statutory DSC</div>
      </div>
    @else
      <p>Internal Accounts signature pending. This document is not verified for employee release.</p>
    @endif
  </div>

</body>
</html>
