<html lang="en">
<head>
    <title>{{__('CoinPayments')}}</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
<div class="coinpaymenbt-wrapper">
    <form action="{{$bladeData['host']}}" method="post" target="_top">
        @php $ipn_id = \Str::random(60);
            \Log::info('ipn id   '.$ipn_id);
        @endphp
        <input type="hidden" name="cmd" value="_pay">
        {{-- ipn related input field --}}
        <input type="hidden" name="ipn_version" value="{{$bladeData['ipn_version']}}">
        <input type="hidden" name="ipn_type" value="button">
        <input type="hidden" name="ipn_mode" value="hmac">
        <input type="hidden" name="ipn_id" value="{{$ipn_id}}">
        <input type="hidden" name="ipn_url" value="{{$args['ipn_url']}}">

        <input type="hidden" name="reset" value="1">
        <input type="hidden" name="want_shipping" value="0">
        <input type="hidden" name="merchant" value="{{$bladeData['merchant']}}">
        <input type="hidden" name="currency" value="{{$bladeData['currency']}}">
        <input type="hidden" name="amountf" value="{{$bladeData['amount']}}">
        <input type="hidden" name="item_name" value="{{$args['title']}}">
        <input type="hidden" name="first_name" value="{{$args['name']}}">{{-- max: 32 character --}}
        <input type="hidden" name="last_name" value=" "> {{-- max: 32 character --}}
        <input type="hidden" name="email" value="{{$args['email']}}">
        <input type="hidden" name="allow_extra" value="1">
        <input type="hidden" name="custom" value="{{$bladeData['custom']}}">

        {{--        <input type="hidden" name="allow_currencies" value="LTC"> --}}{{-- test mode currency --}}
        <input type="hidden" name="allow_currencies" value="{{$bladeData['allow_currencies']}}">
        <input type="hidden" name="success_url" value="{{$args['success_url']}}">
        <input type="hidden" name="cancel_url" value="{{$args['cancel_url']}}">

        <button id="renderBtn">{{__('Pay Now')}}</button>
    </form>
</div>

<script>
    (function(){
        "use strict";

        var submitBtn = document.querySelector('#renderBtn');
        submitBtn.innerHTML = "{{__('Redirecting Please Wait...')}}";
        submitBtn.style.color = "#fff";
        submitBtn.style.backgroundColor = "#c54949";
        submitBtn.style.border = "none";
        document.addEventListener('DOMContentLoaded',function (){
            submitBtn.dispatchEvent(new MouseEvent('click'));
        },false);

    })();
</script>
</body>
</html>
