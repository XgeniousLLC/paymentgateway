<html>
<head>
    <title>{{ __('PayU Payment Gateway') }}</title>
</head>
<body>
<form id="payu_form" action="{{ $payuUrl }}" method="post">
    @foreach($payuData as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <button type="submit">{{ __('Redirecting Please Wait...') }}</button>
</form>
<script>
    (function () {
        "use strict";
        var submitBtn = document.getElementById('payu_form').querySelector('button[type="submit"]');
        submitBtn.style.color           = "#fff";
        submitBtn.style.backgroundColor = "#c54949";
        submitBtn.style.border          = "none";
        submitBtn.innerHTML             = "{{ __('Redirecting Please Wait...') }}";

        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('payu_form').submit();
        }, false);
    })();
</script>
</body>
</html>
