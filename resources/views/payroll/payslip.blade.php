@php
    $rp = fn ($value) => 'Rp ' . number_format((float) $value, 0, ',', '.');
    $gross = (float) $payroll->base_salary + (float) $payroll->total_earnings;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Slip Gaji {{ $period }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 18px; margin: 0; }
        .sub { color: #666; margin-top: 2px; }
        .head { border-bottom: 2px solid #222; padding-bottom: 8px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 2px 0; }
        .info td.label { width: 90px; color: #555; }
        .section { margin-top: 16px; }
        .section h2 { font-size: 12px; margin: 0 0 4px 0; background: #eee; padding: 5px 8px; }
        .rows td { padding: 5px 8px; border-bottom: 1px solid #ddd; }
        .rows td.amount { text-align: right; width: 140px; }
        .rows tr.total td { font-weight: bold; border-top: 1px solid #222; border-bottom: none; }
        .net { margin-top: 18px; background: #222; color: #fff; padding: 10px 12px; }
        .net td { color: #fff; font-size: 13px; font-weight: bold; }
        .net td.amount { text-align: right; }
        .foot { margin-top: 24px; color: #888; font-size: 9px; }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ $company }}</h1>
        <div class="sub">SLIP GAJI &middot; Periode {{ $period }}</div>
    </div>

    <table class="info">
        <tr>
            <td class="label">NIP</td><td>: {{ $employee->nip }}</td>
            <td class="label">Departemen</td><td>: {{ $employee->department?->name }}</td>
        </tr>
        <tr>
            <td class="label">Nama</td><td>: {{ $employee->full_name }}</td>
            <td class="label">Jabatan</td><td>: {{ $employee->position?->name }}</td>
        </tr>
    </table>

    <div class="section">
        <h2>Pendapatan</h2>
        <table class="rows">
            <tr><td>Gaji Pokok</td><td class="amount">{{ $rp($payroll->base_salary) }}</td></tr>
            @foreach ($earnings as $item)
                <tr><td>{{ $item->name }}</td><td class="amount">{{ $rp($item->amount) }}</td></tr>
            @endforeach
            <tr class="total"><td>Total Pendapatan</td><td class="amount">{{ $rp($gross) }}</td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Potongan</h2>
        <table class="rows">
            @forelse ($deductions as $item)
                <tr><td>{{ $item->name }}</td><td class="amount">{{ $rp($item->amount) }}</td></tr>
            @empty
                <tr><td>Tidak ada potongan</td><td class="amount">{{ $rp(0) }}</td></tr>
            @endforelse
            <tr class="total"><td>Total Potongan</td><td class="amount">{{ $rp($payroll->total_deductions) }}</td></tr>
        </table>
    </div>

    <table class="net">
        <tr>
            <td>GAJI BERSIH (TAKE HOME PAY)</td>
            <td class="amount">{{ $rp($payroll->net_salary) }}</td>
        </tr>
    </table>

    <div class="foot">Dokumen ini dibuat otomatis oleh sistem dan sah tanpa tanda tangan.</div>
</body>
</html>