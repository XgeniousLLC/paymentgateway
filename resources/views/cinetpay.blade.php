<html>
<head>
    <title>{{__('CinetPay Payment Gateway')}}</title>
    <meta http-equiv="refresh" content="0;url={{ $payment_url ?? '/' }}">
</head>
<body>
    <p>{{__('Redirecting to CinetPay, please wait...')}}</p>
    @isset($payment_url)
        <script>window.location.href = "{{ $payment_url }}";</script>
    @endisset
</body>
</html>
