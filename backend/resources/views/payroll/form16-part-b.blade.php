<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Form 16 — Part B</title>
<style>
  body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
  table { width: 100%; border-collapse: collapse; }
  .info-table td { border: 1px solid #999; padding: 5px 8px; vertical-align: top; }
  .info-label { color: #555; font-size: 9px; white-space: nowrap; }
  .company-name { font-size: 16px; font-weight: bold; }
  .title-bar { text-align: center; font-size: 12px; font-weight: bold; padding: 6px 0; background: #eef1f5; border: 1px solid #999; border-bottom: none; margin-top: 10px; }
  .sub-bar { text-align: left; font-size: 10px; font-weight: bold; padding: 4px 8px; background: #f7f7f7; border: 1px solid #999; border-top: none; }
  .disclaimer { font-size: 8.5px; color: #a05a00; background: #fff8e8; border: 1px solid #f0d090; padding: 5px 8px; margin-top: 2px; }
  .part-b-table td { border: 1px solid #999; padding: 3px 6px; vertical-align: top; }
  .part-b-table .no-col { width: 4%; text-align: center; }
  .part-b-table .desc-col { width: 56%; }
  .part-b-table .amt-col { width: 20%; text-align: right; font-variant-numeric: tabular-nums; }
  .part-b-table .clause-row td { padding-left: 14px; }
  .section-row td { font-weight: bold; background: #eef1f5; }
  .total-row td { font-weight: bold; background: #f7f7f7; }
  .net-row td { font-weight: bold; background: #eafaf0; }
  .via-table td { border: 1px solid #999; padding: 3px 6px; }
  .via-table .desc-col { width: 60%; }
  .via-table .amt-col { width: 13.33%; text-align: right; font-variant-numeric: tabular-nums; }
  .via-header td { font-weight: bold; background: #f7f7f7; text-align: center; font-size: 9px; }
  .verification-table td { border: 1px solid #999; padding: 6px 8px; }
  .footer { margin-top: 10px; font-size: 8.5px; color: #333; }
</style>
</head>
<body>

  <table class="info-table">
    <tr>
      <td colspan="2" style="width:45%"><div class="company-name">{{ $letterhead['company_name'] }}</div></td>
      <td colspan="6"><span class="info-label">Registered Address:</span><br>{{ $letterhead['company_address'] }}</td>
    </tr>
    <tr>
      <td colspan="2"><span class="info-label">Employee Name</span><br>{{ $employee->full_name }}</td>
      <td colspan="2"><span class="info-label">Employee Code</span><br>{{ $employee->employee_code }}</td>
      <td colspan="2"><span class="info-label">PAN</span><br>{{ $employee->pan_tax_id ?? '—' }}</td>
      <td colspan="2"><span class="info-label">Financial Year</span><br>{{ $fiscalYearLabel }}</td>
    </tr>
  </table>

  @if (isset($tax['error']))
    <div class="disclaimer" style="margin-top:8px;color:#a00;background:#fdeeee;border-color:#e0a0a0;">{{ $tax['error'] }}</div>
  @else
    @php
      /** Renders a line-item amount: blank placeholder for untracked/not-applicable items, formatted Rs. figure otherwise. */
      $rs = function ($value) {
          if ($value === null) {
              return 'Rs. ...';
          }
          $sign = $value < 0 ? '-' : '';
          return $sign . 'Rs. ' . number_format(abs($value), 2);
      };
      $li = $lineItems;
    @endphp

    <div class="title-bar">"Part B (Annexure)</div>
    <div class="sub-bar">Details of Salary Paid and any other income and tax deducted</div>
    <div class="disclaimer">
      Company-prepared tax computation in the CBDT-prescribed Part B (Annexure) format, for reference and TDS planning.
      Not a substitute for the official Form 16 — since May 2019, a legally valid Form 16 (Part A and Part B) can only be
      issued via the Income Tax Department's TRACES portal. Section citations below are under the <strong>Income Tax Act,
      2025</strong> (as amended by the Finance Act, 2026 — in force from 1 April 2026, replacing the Income Tax Act, 1961);
      the erstwhile 1961-Act section is noted alongside each for reference during the transition. Figures marked "Rs. ..."
      are not tracked by this system or not applicable under the employee's chosen regime.
      @if ($tax['figures_basis'] === 'provisional') Based on declared (not yet verified) investment proofs. @else Based on verified Proof of Investments. @endif
    </div>

    <table class="part-b-table" style="margin-top:6px">
      <tr class="section-row"><td class="no-col">1.</td><td colspan="3">Gross Salary</td></tr>
      <tr><td class="no-col">(a)</td><td class="desc-col">Salary as per provisions contained in section 15 (erstwhile section 17(1))</td><td class="amt-col">{{ $rs($li['1a']) }}</td><td class="amt-col"></td></tr>
      <tr><td class="no-col">(b)</td><td class="desc-col">Value of perquisites under section 17 (erstwhile section 17(2)) (as per Form No. 12BA, wherever applicable)</td><td class="amt-col">{{ $rs($li['1b']) }}</td><td class="amt-col"></td></tr>
      <tr><td class="no-col">(c)</td><td class="desc-col">Profits in lieu of salary under section 18 (erstwhile section 17(3)) (as per Form No. 12BA, wherever applicable)</td><td class="amt-col">{{ $rs($li['1c']) }}</td><td class="amt-col"></td></tr>
      <tr class="total-row"><td class="no-col">(d)</td><td class="desc-col">Total</td><td class="amt-col"></td><td class="amt-col">{{ $rs($li['1d']) }}</td></tr>
      <tr><td class="no-col">(e)</td><td class="desc-col">Reported total amount of salary received from other employer(s)</td><td class="amt-col"></td><td class="amt-col">{{ $rs($li['1e']) }}</td></tr>

      <tr class="section-row"><td class="no-col">2.</td><td colspan="3"><em>Less:</em> Allowances to the extent exempt under section 11 and Schedule III (erstwhile section 10)</td></tr>
      <tr><td class="no-col">(a)</td><td class="desc-col">Travel concession or assistance under section 11, Schedule III Sl. No. 8 (erstwhile section 10(5))</td><td class="amt-col">{{ $rs($li['2a']) }}</td><td class="amt-col"></td></tr>
      <tr><td class="no-col">(b)</td><td class="desc-col">Death-cum-retirement gratuity under section 19(1), Table Sl. No. 3-6 (erstwhile section 10(10))</td><td class="amt-col">{{ $rs($li['2b']) }}</td><td class="amt-col"></td></tr>
      <tr><td class="no-col">(c)</td><td class="desc-col">Commuted value of pension under section 19(1), Table Sl. No. 7-9 (erstwhile section 10(10A))</td><td class="amt-col">{{ $rs($li['2c']) }}</td><td class="amt-col"></td></tr>
      <tr><td class="no-col">(d)</td><td class="desc-col">Cash equivalent of leave salary encashment under section 19(1), Table Sl. No. 13-14 (erstwhile section 10(10AA))</td><td class="amt-col">{{ $rs($li['2d']) }}</td><td class="amt-col"></td></tr>
      <tr><td class="no-col">(e)</td><td class="desc-col">House rent allowance under section 11, Schedule III Sl. No. 11 (erstwhile section 10(13A))</td><td class="amt-col">{{ $rs($li['2e']) }}</td><td class="amt-col"></td></tr>
      <tr><td class="no-col">(f)</td><td class="desc-col" colspan="3">Amount of any other exemption under section 11 and Schedule III (erstwhile section 10)</td></tr>
      <tr class="clause-row"><td class="no-col"></td><td class="desc-col">clause …</td><td class="amt-col">{{ $rs($li['2g']) }}</td><td class="amt-col"></td></tr>
      <tr class="total-row"><td class="no-col">(g)</td><td class="desc-col">Total amount of any other exemption under section 11</td><td class="amt-col">{{ $rs($li['2g']) }}</td><td class="amt-col"></td></tr>
      <tr class="total-row"><td class="no-col">(h)</td><td class="desc-col">Total amount of exemption claimed under section 11 [2(a)+2(b)+2(c)+2(d)+2(e)+2(g)]</td><td class="amt-col"></td><td class="amt-col">{{ $rs($li['2h']) }}</td></tr>

      <tr class="total-row"><td class="no-col">3.</td><td class="desc-col">Total amount of salary received from current employer [1(d)-2(h)]</td><td class="amt-col"></td><td class="amt-col">{{ $rs($li['3']) }}</td></tr>

      <tr class="section-row"><td class="no-col">4.</td><td colspan="3"><em>Less:</em> Deductions under section 19 (erstwhile section 16)</td></tr>
      <tr><td class="no-col">(a)</td><td class="desc-col">Standard deduction under section 19(1), Table Sl. No. 2 (erstwhile section 16(ia))</td><td class="amt-col">{{ $rs($li['4a']) }}</td><td class="amt-col"></td></tr>
      <tr><td class="no-col">(b)</td><td class="desc-col">Entertainment allowance under section 19 (erstwhile section 16(ii))</td><td class="amt-col">{{ $rs($li['4b']) }}</td><td class="amt-col"></td></tr>
      <tr><td class="no-col">(c)</td><td class="desc-col">Tax on employment under section 19(1), Table Sl. No. 1 (erstwhile section 16(iii))</td><td class="amt-col">{{ $rs($li['4c']) }}</td><td class="amt-col"></td></tr>
      <tr class="total-row"><td class="no-col">5.</td><td class="desc-col">Total amount of deductions under section 19 [4(a)+4(b)+4(c)]</td><td class="amt-col"></td><td class="amt-col">{{ $rs($li['5']) }}</td></tr>

      <tr class="total-row"><td class="no-col">6.</td><td class="desc-col">Income chargeable under the head "Salaries" [(3+1(e)-5]</td><td class="amt-col"></td><td class="amt-col">{{ $rs($li['6']) }}</td></tr>

      <tr class="section-row"><td class="no-col">7.</td><td colspan="3">Add: Any other income reported by the employee as per section 392(4) (erstwhile section 192(2B))</td></tr>
      <tr><td class="no-col">(a)</td><td class="desc-col">Income (or admissible loss) from house property reported by employee offered for TDS</td><td class="amt-col">{{ $rs($li['7a']) }}</td><td class="amt-col"></td></tr>
      <tr><td class="no-col">(b)</td><td class="desc-col">Income under the head Other Sources offered for TDS</td><td class="amt-col">{{ $rs($li['7b']) }}</td><td class="amt-col"></td></tr>
      <tr class="total-row"><td class="no-col">8.</td><td class="desc-col">Total amount of other income reported by the employee [7(a)+7(b)]</td><td class="amt-col"></td><td class="amt-col">{{ $rs($li['8']) }}</td></tr>

      <tr class="total-row"><td class="no-col">9.</td><td class="desc-col">Gross total income (6+8)</td><td class="amt-col"></td><td class="amt-col">{{ $rs($li['9']) }}</td></tr>

      <tr class="section-row"><td class="no-col">10.</td><td colspan="3">Deductions under Chapter VIII (erstwhile Chapter VI-A)</td></tr>
    </table>

    <table class="via-table">
      <tr class="via-header"><td class="desc-col"></td><td class="amt-col">Gross Amount</td><td class="amt-col">Deductible Amount</td></tr>
      <tr><td class="desc-col">(a) Deduction in respect of life insurance premia, contributions to provident fund etc. under section 123 (erstwhile section 80C)</td><td class="amt-col">{{ $rs($li['10a']) }}</td><td class="amt-col">{{ $rs($li['10a']) }}</td></tr>
      <tr><td class="desc-col">(b) Deduction in respect of contribution to certain pension funds (erstwhile section 80CCC)</td><td class="amt-col">{{ $rs($li['10b']) }}</td><td class="amt-col">{{ $rs($li['10b']) }}</td></tr>
      <tr><td class="desc-col">(c) Deduction in respect of contribution by taxpayer to pension scheme under section 124(3) (erstwhile section 80CCD (1))</td><td class="amt-col">{{ $rs($li['10c']) }}</td><td class="amt-col">{{ $rs($li['10c']) }}</td></tr>
      <tr class="total-row"><td class="desc-col">(d) Total deduction under section 123 and 124(3) (erstwhile section 80C, 80CCC and 80CCD(1))</td><td class="amt-col">{{ $rs($li['10d']) }}</td><td class="amt-col">{{ $rs($li['10d']) }}</td></tr>
      <tr><td class="desc-col">(e) Deductions in respect of amount paid/deposited to notified pension scheme under section 124(3)/(4) (erstwhile section 80CCD (1B))</td><td class="amt-col">{{ $rs($li['10e']) }}</td><td class="amt-col">{{ $rs($li['10e']) }}</td></tr>
      <tr><td class="desc-col">(f) Deduction in respect of contribution by Employer to pension scheme under section 124(1)/(2) (erstwhile section 80CCD (2))</td><td class="amt-col">{{ $rs($li['10f']) }}</td><td class="amt-col">{{ $rs($li['10f']) }}</td></tr>
      <tr><td class="desc-col">(g) Deduction in respect of health insurance premia under section 126 (erstwhile section 80D)</td><td class="amt-col">{{ $rs($li['10g']) }}</td><td class="amt-col">{{ $rs($li['10g']) }}</td></tr>
      <tr><td class="desc-col">(h) Deduction in respect of interest on loan taken for higher education under section 129 (erstwhile section 80E)</td><td class="amt-col">{{ $rs($li['10h']) }}</td><td class="amt-col">{{ $rs($li['10h']) }}</td></tr>
    </table>

    <table class="via-table" style="margin-top:-1px">
      <tr class="via-header"><td class="desc-col"></td><td class="amt-col">Gross Amount</td><td class="amt-col">Qualifying Amount</td><td class="amt-col">Deductible Amount</td></tr>
      <tr><td class="desc-col">(i) Total Deduction in respect of donations to certain funds, charitable institutions, etc. under section 133 (erstwhile section 80G)</td><td class="amt-col">{{ $rs($li['10i']) }}</td><td class="amt-col">{{ $rs($li['10i']) }}</td><td class="amt-col">{{ $rs($li['10i']) }}</td></tr>
      <tr><td class="desc-col">(j) Deduction in respect of interest on deposits in savings account under section 153 (erstwhile section 80TTA)</td><td class="amt-col">{{ $rs($li['10j']) }}</td><td class="amt-col">{{ $rs($li['10j']) }}</td><td class="amt-col">{{ $rs($li['10j']) }}</td></tr>
      @forelse ($li['10k_items'] as $other)
        <tr><td class="desc-col">(k) Amount deductible under any other provision(s) of Chapter VIII — {{ $other['label'] }}</td><td class="amt-col">{{ $rs($other['amount']) }}</td><td class="amt-col">{{ $rs($other['amount']) }}</td><td class="amt-col">{{ $rs($other['amount']) }}</td></tr>
      @empty
        <tr><td class="desc-col">(k) Amount deductible under any other provision(s) of Chapter VIII — section …</td><td class="amt-col">{{ $rs(null) }}</td><td class="amt-col">{{ $rs(null) }}</td><td class="amt-col">{{ $rs(null) }}</td></tr>
      @endforelse
      <tr class="total-row"><td class="desc-col">(l) Total of amount deductible under any other provision(s) of Chapter VIII</td><td class="amt-col">{{ $rs($li['10l']) }}</td><td class="amt-col">{{ $rs($li['10l']) }}</td><td class="amt-col">{{ $rs($li['10l']) }}</td></tr>
    </table>

    <table class="part-b-table" style="margin-top:-1px">
      <tr class="total-row"><td class="no-col">11.</td><td class="desc-col" colspan="2">Aggregate of deductible amount under Chapter VIII [10(a)+10(b)+10(c)+10(d)+10(e)+10(f)+10(g)+10(h)+10(i)+10(j)+10(l)]</td><td class="amt-col">{{ $rs($li['11']) }}</td></tr>
      <tr class="total-row"><td class="no-col">12.</td><td class="desc-col" colspan="2">Total taxable income (9-11)</td><td class="amt-col">{{ $rs($li['12']) }}</td></tr>
      <tr><td class="no-col">13.</td><td class="desc-col" colspan="2">Tax on total income</td><td class="amt-col">{{ $rs($li['13']) }}</td></tr>
      <tr><td class="no-col">14.</td><td class="desc-col" colspan="2">Rebate under section 156, if applicable (erstwhile section 87A)</td><td class="amt-col">{{ $rs($li['14']) }}</td></tr>
      <tr><td class="no-col">15.</td><td class="desc-col" colspan="2">Surcharge, wherever applicable</td><td class="amt-col">{{ $rs($li['15']) }}</td></tr>
      <tr><td class="no-col">16.</td><td class="desc-col" colspan="2">Health and education cess</td><td class="amt-col">{{ $rs($li['16']) }}</td></tr>
      <tr class="total-row"><td class="no-col">17.</td><td class="desc-col" colspan="2">Tax payable (13+15+16-14)</td><td class="amt-col">{{ $rs($li['17']) }}</td></tr>
      <tr><td class="no-col">18.</td><td class="desc-col" colspan="2"><em>Less:</em> Relief under section 157 (attach details) (erstwhile section 89)</td><td class="amt-col">{{ $rs($li['18']) }}</td></tr>
      <tr class="net-row"><td class="no-col">19.</td><td class="desc-col" colspan="2">Net tax payable (17-18)</td><td class="amt-col">{{ $rs($li['19']) }}</td></tr>
    </table>

    <table class="verification-table" style="margin-top:6px">
      <tr>
        <td colspan="2" style="font-weight:bold;text-align:center;">Verification</td>
      </tr>
      <tr>
        <td colspan="2">
          I, {{ $letterhead['signatory_name'] ?: '……………………………….' }}, working in the capacity of
          {{ $letterhead['signatory_designation'] ?: '……………………………….' }} do hereby certify that the information given above
          is true, complete and correct and is based on the books of account, documents, TDS statements, and other available records.
        </td>
      </tr>
      <tr>
        <td style="width:50%">Place……………………………………</td>
        <td>(Signature of person responsible for deduction of tax)</td>
      </tr>
      <tr>
        <td>Date: {{ $generatedAt->format('d M Y') }}</td>
        <td>Full Name: {{ $letterhead['signatory_name'] ?: '…………………………' }}</td>
      </tr>
    </table>
  @endif

  <div class="footer">
    <p>Prepared by: {{ $letterhead['company_name'] }} Payroll Team &nbsp;&nbsp;&nbsp; Electronically Generated at {{ $generatedAt->format('h:i A') }} UTC on {{ $generatedAt->format('d M Y') }}</p>
  </div>

</body>
</html>
