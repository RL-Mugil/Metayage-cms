{{-- Small 3D seal + three short lines: who signed, title and time, verification code with link. --}}
<table style="margin-top:14px;margin-left:auto;width:300px;border-collapse:collapse;font-family:DejaVu Sans,sans-serif">
  <tr>
    <td style="width:58px;vertical-align:middle;border:0;padding:0"><img src="{{ $seal }}" alt="" style="width:52px;height:52px"></td>
    <td style="vertical-align:middle;border:0;padding:0 0 0 4px">
      @if ($sig)
        <div style="font-weight:bold;font-size:9px;color:#92400e">Digitally signed · {{ $sig['name'] }}</div>
        <div style="font-size:7.6px;color:#4b5563;line-height:1.4">{{ $sig['designation'] }} · {{ $sig['at'] }}<br>
          Verification code {{ $sig['code'] }}<br><span style="color:#1d4ed8">{{ $sig['url'] }}</span></div>
      @else
        <div style="font-weight:bold;font-size:9px;color:#475569">Unsigned draft</div>
        <div style="font-size:7.6px;color:#6b7280">Not signed by the payroll signatory — not for release to the employee.</div>
      @endif
    </td>
  </tr>
</table>
