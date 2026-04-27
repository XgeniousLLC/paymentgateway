<html>
<head>
    <title>{{__('Razorpay Payment')}}</title>
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background-color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .payment-loader {
            text-align: center;
            background: #fff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border-left-color: #3399cc;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-bottom: 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        h2 { margin: 10px 0; color: #333; font-size: 1.25rem; }
        p { color: #666; font-size: 0.9rem; }
    </style>
</head>
<body>

<div class="stripe-payment-wrapper">
    <div class="payment-loader" id="loader-ui">
        <div class="spinner"></div>
        <h2>{{__('Processing Payment...')}}</h2>
        <p>{{__('Please wait while we connect to Razorpay. Do not refresh this page.')}}</p>
    </div>

    <div class="srtipe-payment-inner-wrapper" style="display: none;">
        <form action="{{$razorpay_data['route']}}" method="POST" id="razorpay-form">
            <input type="hidden" name="order_id" value="{{$razorpay_data['order_id']}}" />

            {{-- Hidden fields to capture Razorpay response --}}
            <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
            <input type="hidden" name="razorpay_subscription_id" id="razorpay_subscription_id">
            <input type="hidden" name="razorpay_signature" id="razorpay_signature">

            <script src="https://checkout.razorpay.com/v1/checkout.js"
                    data-key="{{ $razorpay_data['api_key']}}"
                    @if(isset($razorpay_data['subscription_id']) && !empty($razorpay_data['subscription_id']))
                        {{-- Subscription Flow (Auto Pay) --}}
                        data-subscription_id="{{$razorpay_data['subscription_id']}}"
                    @else
                        {{-- Standard One-time Payment Flow --}}
                        data-amount="{{ceil($razorpay_data['price'] * 100)}}"
                    data-currency="{{$razorpay_data['currency']}}"
                    @endif
                    data-buttontext="{{'Pay Now'}}"
                    data-name="{{$razorpay_data['title']}}"
                    data-description="{{$razorpay_data['description']}}"
                    data-image=""
                    data-prefill.name=""
                    data-prefill.email=""
                    data-theme.color="#3399cc">
            </script>
            <input type="hidden" name="_token" value="{{csrf_token()}}">
        </form>
    </div>
</div>

<script>
    (function(){
        "use strict";

        document.addEventListener('DOMContentLoaded', function () {
            // Extract Razorpay script element
            var rzpScript = document.querySelector('script[src*="checkout.razorpay.com"]');

            if (!rzpScript) {
                console.error('Razorpay script not found');
                return;
            }

            // Get all data attributes from script
            var options = {};

            // Standard payment options
            options.key = rzpScript.getAttribute('data-key');
            options.amount = rzpScript.getAttribute('data-amount');
            options.currency = rzpScript.getAttribute('data-currency');
            options.name = rzpScript.getAttribute('data-name');
            options.description = rzpScript.getAttribute('data-description');
            options.image = rzpScript.getAttribute('data-image') || '';
            options.prefill = {
                name: rzpScript.getAttribute('data-prefill.name') || '',
                email: rzpScript.getAttribute('data-prefill.email') || ''
            };
            options.theme = {
                color: rzpScript.getAttribute('data-theme.color')
            };

            // ADD NOTES
            options.notes = @json($razorpay_data['notes'] ?? []);

            // Subscription options if applicable
            var subscriptionId = rzpScript.getAttribute('data-subscription_id');
            if (subscriptionId) {
                options.subscription_id = subscriptionId;
            }

            // Handler for payment success
            options.handler = function (response) {
                document.getElementById('razorpay_payment_id').value = response.razorpay_payment_id;
                document.getElementById('razorpay_signature').value = response.razorpay_signature;

                if (response.razorpay_subscription_id) {
                    document.getElementById('razorpay_subscription_id').value = response.razorpay_subscription_id;
                }

                document.getElementById('razorpay-form').submit();
            };

            // Error handler
            options.modal = {
                ondismiss: function() {
                    console.log('Payment modal closed');
                }
            };

            // Razorpay instance
            var rzp = new Razorpay(options);

            // Auto-trigger payment
            var checkExist = setInterval(function() {
                var submitBtn = document.querySelector('input[type="submit"]');
                if (submitBtn) {
                    submitBtn.style.display = "none";

                    // Trigger Razorpay checkout
                    rzp.open();
                    clearInterval(checkExist);

                    submitBtn.addEventListener('click', function () {
                        var loaderH2 = document.querySelector('#loader-ui h2');
                        if (loaderH2) {
                            loaderH2.innerText = "{{__('Do Not Close This page..')}}";
                        }
                    });
                }
            }, 100);

            // Timeout fallback
            setTimeout(function() {
                clearInterval(checkExist);
            }, 10000);
        }, false);
    })();
</script>
</body>
</html>
