<html lang="en">
<head>
    <title>{{__('Paytm')}}</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0, maximum-scale=1.0"/>
    <style>
        .loading_page{
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            background-color: #e15d5d;
            color: #fff;
            font-size: 14px;
            letter-spacing: 2px;
        }
    </style>
</head>
<body>

<p id="loading_page_warning" class="loading_page">Loading Payment app,. do not reload the page .....</p>

<script type="application/javascript" src="{{$bladeData['host']}}/merchantpgpui/checkoutjs/merchants/{{$bladeData['merchant_id']}}.js" crossorigin="anonymous"></script>
<script>
        onScriptLoad();
        function onScriptLoad(){
            console.log('loading')
            var config = {
            "root": "",
            "flow": "DEFAULT",
            "data": {
            "orderId": `{{$bladeData['order_id']}}`, /* update order id */
            "token": `{{$bladeData['txnToken']}}`, /* update token value */
            "tokenType": "TXN_TOKEN",
            "amount": `{{$bladeData['amount']}}` /* update amount */
        },
            "handler": {
                "notifyMerchant": function(eventName,data){
                console.log("notifyMerchant handler function called");
                console.log("eventName => ",eventName);
                console.log("data => ",data);

                if(eventName === 'APP_CLOSED'){
                    //todo:: redirect to cancel page, because user close the paytm app
                    window.location = `{{$bladeData['cancel_url']}}`
                }
            }
        }
    };
       if(window.Paytm && window.Paytm.CheckoutJS){
            window.Paytm.CheckoutJS.onLoad(function excecuteAfterCompleteLoad() {
             // initialze configuration using init method
                window.Paytm.CheckoutJS.init(config).then(function onSuccess() {
                    // after successfully updating configuration, invoke JS Checkout
                    window.Paytm.CheckoutJS.invoke();
                    document.getElementById('loading_page_warning').style.display = 'none';

                }).catch(function onError(error){
                    console.log('redirect to the cancel page')
                    console.log("error => ",error);
                });
            });
           //
           // window.Paytm.CheckoutJS.init({
           //     handler:{
           //         notifyMerchant:function notifyMerchant(eventName,data){
           //             console.log("notify merchant about the payment state");
           //         }
           //     }
           // });
           //
           //
           // window.Paytm.CheckoutJS.init({
           //     handler:{
           //         transactionStatus:function transactionStatus(paymentStatus){
           //             console.log("paymentStatus => ",paymentStatus);
           //         }
           //     }
           // });
        }

    }

</script>
</body>
</html>
