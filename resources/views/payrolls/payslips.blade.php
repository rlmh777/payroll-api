<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payroll Payslips - {{ $companyName }}</title>
    <style>
        :root {
            --primary: {{ $primaryColor }};
            --secondary: {{ $secondaryColor }};
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 20px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #1f2933;
            background: #eceff3;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
            padding: 14px 18px;
            background: #fff;
            border: 1px solid #d9e2ec;
            border-radius: 6px;
        }

        .toolbar h1 { margin: 0 0 4px; font-size: 18px; }
        .toolbar p { margin: 0; color: #52606d; font-size: 13px; }

        .toolbar button {
            border: none;
            border-radius: 4px;
            background: var(--primary);
            color: #fff;
            padding: 9px 14px;
            font-size: 13px;
            cursor: pointer;
        }

        .payslip-page {
            width: 100%;
            max-width: 920px;
            margin: 0 auto 28px;
            background: #fff;
            border: 1px solid #cfd8e3;
        }

        .payslip-page:not(:last-child) {
            page-break-after: always;
        }

        .layout-table {
            width: 100%;
            border-collapse: collapse;
        }

        .brand-logo {
            width: 96px;
            height: 96px;
            object-fit: contain;
            display: block;
        }

        .brand-logo-placeholder {
            width: 96px;
            height: 96px;
            border: 1px solid #d9e2ec;
            text-align: center;
            color: #9aa5b1;
            font-size: 11px;
            padding: 28px 8px;
        }

        .brand-title {
            text-align: center;
            padding: 18px 12px 10px;
        }

        .brand-title h2 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand-title h3 {
            margin: 6px 0 0;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: #334e68;
        }

        .employee-bar td {
            width: 50%;
            background: var(--primary);
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            padding: 8px 14px;
        }

        .info-table td {
            width: 25%;
            vertical-align: top;
            border-bottom: 1px solid #cfd8e3;
        }

        .info-label {
            background: var(--secondary);
            color: #fff;
            font-weight: 700;
            text-align: center;
            padding: 5px 8px;
            font-size: 11px;
        }

        .info-value {
            background: var(--primary);
            color: #fff;
            font-weight: 700;
            text-align: center;
            padding: 8px;
            min-height: 34px;
        }

        .content-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .content-table > tbody > tr > td {
            vertical-align: top;
            padding: 0;
        }

        .main-table,
        .ytd-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .main-table th,
        .main-table td,
        .ytd-table th,
        .ytd-table td {
            border: 1px solid #b8c4d0;
            padding: 5px 7px;
            vertical-align: top;
        }

        .main-table th,
        .ytd-table th {
            font-weight: 700;
            text-align: center;
        }

        .bg-secondary { background: var(--secondary); color: #fff; }
        .bg-primary { background: var(--primary); color: #fff; }
        .text-right { text-align: right; }
        .label-cell { font-weight: 600; background: #f8fafc; }
        .negative { color: #c81e1e; }
        .line-label { font-size: 11px; color: #334e68; }
        .line-amount { font-weight: 700; }

        .boxed-total {
            border: 2px solid #1f2933;
            font-weight: 700;
            text-align: right;
            padding: 6px 8px;
            background: #fff;
        }

        .net-pay-row td {
            border-top: 2px solid #1f2933;
            font-weight: 700;
            font-size: 13px;
        }

        .footer-table td {
            padding: 12px 16px 16px;
            font-size: 11px;
            color: #52606d;
            vertical-align: top;
        }

        .footer-center { text-align: center; font-style: italic; }
        .footer-right { text-align: right; font-weight: 700; color: #1f2933; }

        @media print {
            @page {
                margin: 10mm;
                size: portrait;
            }

            html,
            body {
                width: 100%;
                height: auto;
                padding: 0;
                margin: 0;
                background: #fff;
                color: #000;
                overflow: visible;
            }

            .toolbar {
                display: none !important;
            }

            .payslip-page {
                border: none;
                max-width: none;
                margin: 0;
                overflow: visible;
            }

            .payslip-page:not(:last-child) {
                page-break-after: always;
            }

            .bg-secondary,
            .bg-primary,
            .employee-bar td,
            .info-label,
            .info-value {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .main-table,
            .ytd-table,
            .content-table,
            .layout-table {
                page-break-inside: auto;
            }

            tr,
            td,
            th {
                page-break-inside: avoid;
            }
        }
    </style>
    <script>
        function printPayslips() {
            const images = Array.from(document.images);
            const pendingImages = images.filter((image) => !image.complete);

            const triggerPrint = () => {
                requestAnimationFrame(() => window.print());
            };

            if (pendingImages.length === 0) {
                triggerPrint();
                return;
            }

            Promise.all(
                pendingImages.map(
                    (image) =>
                        new Promise((resolve) => {
                            image.addEventListener('load', resolve, { once: true });
                            image.addEventListener('error', resolve, { once: true });
                        }),
                ),
            ).then(triggerPrint);
        }
    </script>
</head>
<body>
    <div class="toolbar">
        <div>
            <h1>Payroll Payslips</h1>
            <p>{{ $companyName }} · {{ $payPeriodLabel }}@if ($payDate) · Pay date {{ $payDate }}@endif</p>
        </div>
        <button type="button" onclick="printPayslips()">Print payslips</button>
    </div>

    @foreach ($payslips as $payslip)
        @php
            $detailRows = max(count($payslip['deductionRows']), count($payslip['otherPayRows']), 4);
        @endphp

        <div class="payslip-page">
            <table class="layout-table brand-table">
                <tr>
                    <td style="width: 110px; padding: 18px 12px 10px 20px; vertical-align: middle;">
                        @if (!empty($companyLogoUrl))
                            <img src="{{ $companyLogoUrl }}" alt="{{ $companyName }} logo" class="brand-logo">
                        @else
                            <div class="brand-logo-placeholder">Company<br>Logo</div>
                        @endif
                    </td>
                    <td class="brand-title">
                        <h2>{{ $companyName }}</h2>
                        <h3>PAYROLL SLIP</h3>
                    </td>
                </tr>
            </table>

            <table class="layout-table employee-bar">
                <tr>
                    <td>Employee ID: {{ $payslip['employeeCode'] ?? '—' }}</td>
                    <td>Employee: {{ $payslip['employeeName'] ?? 'Unknown employee' }}</td>
                </tr>
            </table>

            <table class="layout-table info-table">
                <tr>
                    <td>
                        <div class="info-label">Payroll #</div>
                        <div class="info-value">{{ $payslip['payrollNumber'] }}</div>
                    </td>
                    <td>
                        <div class="info-label">Payroll Date</div>
                        <div class="info-value">{{ $payDate ?? '—' }}</div>
                    </td>
                    <td>
                        <div class="info-label">Payment Method</div>
                        <div class="info-value">{{ $payslip['paymentMethodLabel'] ?? '—' }}</div>
                    </td>
                    <td>
                        <div class="info-label">Soc Sec #</div>
                        <div class="info-value">{{ $payslip['socialSecurityNumber'] ?? '—' }}</div>
                    </td>
                </tr>
            </table>

            <table class="content-table">
                <tr>
                    <td style="width: 78%;">
                        <table class="main-table">
                            <thead>
                                <tr>
                                    <th class="bg-secondary" colspan="4">Pay Period</th>
                                    <th class="bg-secondary">Deductions</th>
                                    <th class="bg-secondary">Other Pay</th>
                                </tr>
                                <tr>
                                    <th class="bg-primary"></th>
                                    <th class="bg-primary">Hours</th>
                                    <th class="bg-primary">Rate</th>
                                    <th class="bg-primary">Amount</th>
                                    <th class="bg-primary">Deductions</th>
                                    <th class="bg-primary">Other Pay</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($payslip['earningsRows'] as $earning)
                                    <tr>
                                        <td class="label-cell">{{ $earning['label'] }}</td>
                                        <td class="text-right">{{ number_format($earning['hours'], 2) }}</td>
                                        <td class="text-right">{{ number_format($earning['rate'], 2) }}</td>
                                        <td class="text-right">{{ number_format($earning['amount'], 2) }}</td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                @endforeach

                                <tr>
                                    <td colspan="3" class="text-right label-cell"><strong>Earnings</strong></td>
                                    <td class="text-right boxed-total">{{ number_format($payslip['payPeriodEarningsTotal'], 2) }}</td>
                                    <td></td>
                                    <td></td>
                                </tr>

                                @for ($i = 0; $i < $detailRows; $i++)
                                    @php
                                        $deduction = $payslip['deductionRows'][$i] ?? null;
                                        $otherPay = $payslip['otherPayRows'][$i] ?? null;
                                    @endphp
                                    <tr>
                                        <td colspan="4"></td>
                                        <td class="text-right">
                                            @if ($deduction)
                                                <div class="line-label">{{ $deduction['label'] }}</div>
                                                <div class="line-amount negative">-{{ number_format($deduction['amount'], 2) }}</div>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if ($otherPay)
                                                <div class="line-label">{{ $otherPay['label'] }}</div>
                                                <div class="line-amount">{{ number_format($otherPay['amount'], 2) }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                @endfor

                                <tr>
                                    <td colspan="4"></td>
                                    <td class="text-right negative">
                                        <div class="line-label">Total Deductions</div>
                                        <div class="line-amount">-{{ number_format($payslip['totalDeductions'], 2) }}</div>
                                    </td>
                                    <td class="text-right">
                                        <div class="line-label">Total Other Pay</div>
                                        <div class="line-amount">{{ number_format($payslip['totalOtherPay'], 2) }}</div>
                                    </td>
                                </tr>

                                <tr>
                                    <td colspan="4"></td>
                                    <td class="text-right negative" colspan="2">
                                        <div class="line-label">Net Adjustment</div>
                                        <div class="line-amount">{{ number_format($payslip['netAdjustment'], 2) }}</div>
                                    </td>
                                </tr>

                                <tr class="net-pay-row">
                                    <td colspan="4" class="text-right">NET PAY</td>
                                    <td colspan="2" class="text-right boxed-total">${{ number_format($payslip['netPay'], 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                    <td style="width: 22%;">
                        <table class="ytd-table">
                            <thead>
                                <tr>
                                    <th class="bg-secondary">YTD</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-right">
                                        <div class="line-label">Earnings</div>
                                        <div class="line-amount">{{ number_format($payslip['ytdEarnings'], 2) }}</div>
                                        <div class="line-label" style="margin-top: 12px;">Income Tax</div>
                                        <div class="line-amount negative">{{ number_format($payslip['ytdIncomeTax'], 2) }}</div>
                                        <div class="line-label" style="margin-top: 12px;">Social Security</div>
                                        <div class="line-amount negative">{{ number_format($payslip['ytdSocialSecurity'], 2) }}</div>
                                        <div class="line-label" style="margin-top: 12px;">Adjustments</div>
                                        <div class="line-amount {{ $payslip['ytdOtherAdjustments'] < 0 ? 'negative' : '' }}">
                                            {{ number_format($payslip['ytdOtherAdjustments'], 2) }}
                                        </div>
                                        <div class="line-label" style="margin-top: 16px;">Net Pay</div>
                                        <div class="line-amount">${{ number_format($payslip['netPay'], 2) }}</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
            </table>

            <table class="layout-table footer-table">
                <tr>
                    <td style="width: 30%;">{{ $generatedAt }}</td>
                    <td class="footer-center" style="width: 40%;">
                        @if (!empty($payPeriodGroupName))
                            {{ $payPeriodGroupName }}@if (!empty($frequencyName)) · {{ $frequencyName }}@endif
                        @endif
                    </td>
                    <td class="footer-right" style="width: 30%;">Not VALID for external use</td>
                </tr>
            </table>
        </div>
    @endforeach
</body>
</html>
