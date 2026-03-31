<!DOCTYPE html>
<html>
<head>
    <title>{{ __('PayStack Payment') }}</title>
    <script src="https://js.paystack.co/v2/inline.js"></script>
</head>
<body>

<script>
    window.onload = function () {
        setTimeout(function () {
            var handler = PaystackPop.setup({
                key: "{{ $paystack_data['publicKey'] }}",
                email: "{{ $paystack_data['email'] }}",
                amount: {{ $paystack_data['price'] * 100 }},
                currency: "{{ $paystack_data['currency'] }}",
                ref: "{{ $paystack_data['reference'] }}",
                metadata: {
                    custom_fields: [
                        {
                            display_name: "Order ID",
                            variable_name: "order_id",
                            value: "{{ $paystack_data['order_id'] }}"
                        },
                        {
                            display_name: "Track",
                            variable_name: "track",
                            value: "{{ $paystack_data['track'] }}"
                        }
                    ]
                },
                callback: function(response){
                    window.location.href = "{{ $paystack_data['ipn_url'] }}?reference=" + response.reference;
                },
                onClose: function(){
                    window.history.back();
                }
            });

            handler.openIframe();
        }, 1500);
    };
</script>

</body>
</html>
