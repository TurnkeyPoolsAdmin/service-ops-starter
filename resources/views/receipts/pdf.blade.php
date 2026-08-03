<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Receipt - {{ $entityName }}</title>
</head>
{{-- dompdf-safe markup: inline styles only, plain tables, no external assets/fonts. --}}
<body style="font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1f2933; margin: 0; padding: 0;">

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="background-color: #111827; padding: 28px 36px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="vertical-align: top;">
                            <div style="font-size: 21px; font-weight: bold; color: #ffffff;">{{ $entityName }}</div>
                            @if($locationName && $locationName !== $entityName)
                                <div style="font-size: 12px; color: #d1d5db; margin-top: 2px;">{{ $locationName }}</div>
                            @endif
                            @foreach($entityAddressLines as $line)
                                <div style="font-size: 11px; color: #9ca3af; margin-top: 2px;">{{ $line }}</div>
                            @endforeach
                        </td>
                        <td style="vertical-align: top; text-align: right;">
                            <div style="font-size: 20px; font-weight: bold; color: #ffffff; letter-spacing: 1px;">RECEIPT</div>
                            <div style="font-size: 11px; color: #9ca3af; margin-top: 6px;">Job #{{ $jobId }}</div>
                            <div style="font-size: 11px; color: #9ca3af;">{{ $receiptDate }}</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 32px 36px;">

                @if($isFullyPaid)
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 22px;">
                        <tr>
                            <td style="background-color: #dcfce7; border: 1px solid #16a34a; border-radius: 4px; padding: 10px 14px; text-align: center; font-size: 13px; font-weight: bold; color: #15803d; letter-spacing: .5px;">
                                PAID IN FULL
                            </td>
                        </tr>
                    </table>
                @elseif($balanceDue)
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 22px;">
                        <tr>
                            <td style="background-color: #fef2f2; border: 1px solid #dc2626; border-radius: 4px; padding: 10px 14px; text-align: center; font-size: 13px; font-weight: bold; color: #b91c1c; letter-spacing: .5px;">
                                BALANCE DUE: {{ $balanceDue }}
                            </td>
                        </tr>
                    </table>
                @endif

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
                    <tr>
                        <td style="vertical-align: top; width: 50%; padding-right: 16px;">
                            <div style="font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin-bottom: 5px;">Billed to</div>
                            <div style="font-size: 14px; font-weight: bold; color: #111827;">{{ $customerName ?: '-' }}</div>
                        </td>
                        <td style="vertical-align: top; width: 50%; padding-left: 16px; border-left: 1px solid #e5e7eb;">
                            <div style="font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin-bottom: 5px;">Vehicle</div>
                            <div style="font-size: 13px; color: #111827;">{{ $vehicle ?: '-' }}</div>
                            <div style="font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin-top: 10px; margin-bottom: 5px;">Service</div>
                            <div style="font-size: 13px; color: #111827;">{{ $service ?: '-' }}</div>
                        </td>
                    </tr>
                </table>

                <table style="width: 100%; border-collapse: collapse; margin-bottom: 4px;">
                    <tr>
                        <td style="background-color: #f9fafb; border-bottom: 2px solid #111827; padding: 8px 10px; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280;">Description</td>
                        <td style="background-color: #f9fafb; border-bottom: 2px solid #111827; padding: 8px 10px; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; text-align: right;">Amount</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">{{ $service ?: 'Auto glass service' }}</td>
                        <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">{{ $quote ?? '-' }}</td>
                    </tr>
                </table>

                <table style="width: 100%; border-collapse: collapse; margin-top: 26px; margin-bottom: 4px;">
                    <tr>
                        <td style="background-color: #f9fafb; border-bottom: 2px solid #111827; padding: 8px 10px; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; width: 28%;">Date</td>
                        <td style="background-color: #f9fafb; border-bottom: 2px solid #111827; padding: 8px 10px; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; width: 42%;">Method</td>
                        <td style="background-color: #f9fafb; border-bottom: 2px solid #111827; padding: 8px 10px; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; width: 30%; text-align: right;">Amount</td>
                    </tr>
                    @forelse($payments as $payment)
                        <tr>
                            <td style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb;">{{ $payment['date'] }}</td>
                            <td style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb;">{{ $payment['method'] }}</td>
                            <td style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb; text-align: right;">{{ $payment['amount'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" style="padding: 8px 10px; border-bottom: 1px solid #e5e7eb; color: #9ca3af;">No payments recorded.</td>
                        </tr>
                    @endforelse
                </table>

                <table style="width: 100%; border-collapse: collapse; margin-top: 14px;">
                    <tr>
                        <td style="width: 60%;"></td>
                        <td style="width: 40%;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 6px 10px; font-size: 13px; font-weight: bold; color: #111827; border-top: 2px solid #111827;">Total paid</td>
                                    <td style="padding: 6px 10px; text-align: right; font-size: 13px; font-weight: bold; color: #111827; border-top: 2px solid #111827;">{{ $amountPaid }}</td>
                                </tr>
                                @if($balanceDue)
                                    <tr>
                                        <td style="padding: 4px 10px; font-size: 12px; font-weight: bold; color: #b91c1c;">Balance due</td>
                                        <td style="padding: 4px 10px; text-align: right; font-size: 12px; font-weight: bold; color: #b91c1c;">{{ $balanceDue }}</td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>
                </table>

                <table style="width: 100%; border-collapse: collapse; margin-top: 48px;">
                    <tr>
                        <td style="border-top: 1px solid #e5e7eb; padding-top: 14px; text-align: center; font-size: 10px; color: #9ca3af;">
                            Thank you for your business.
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>

</body>
</html>
