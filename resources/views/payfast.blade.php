<html>
<head>
    <title>{{__('Payfast Payment Gateway')}}</title>
</head>
<body>
<form action="{{ $payfastUrl }}" method="post">
    @foreach($pfData as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <button type="submit">{{ __('Redirecting Please Wait...') }}</button>
</form>
<script>
    (function(){
        "use strict";
        var submitBtn = document.querySelector('button[type="submit"]');
        submitBtn.innerHTML = "{{__('Redirecting Please Wait...')}}";
        submitBtn.style.color = "#fff";
        submitBtn.style.backgroundColor = "#c54949";
        submitBtn.style.border = "none";

        document.addEventListener('DOMContentLoaded', function (){
            // Simulate user click via event dispatch
            var event = new MouseEvent('click', {
                view: window,
                bubbles: true,
                cancelable: true
            });
            submitBtn.dispatchEvent(event);
        }, false);
    })();
</script>

</body>
</html>
