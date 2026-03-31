<html lang="en">
<head>
    <title>{{__('Cashfree')}}</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
</head>
<body>
<div class="cashfree-payment-wrapper">
    <div class="cashfree-payment-inner-wrapper">
        <button id="renderBtn">Pay now</button>
    </div>
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

        const cashfree = Cashfree({
            mode: `{{$payment_data['env']}}`, //sandbox or production
        });
        document.getElementById("renderBtn").addEventListener("click", () => {
            let checkoutOptions = {
                paymentSessionId: `{{$payment_data['payment_session_id']}}`,
                redirectTarget: "_self",
            };

            cashfree.checkout(checkoutOptions).then((result) => {
              if(result.error){
                    window.location.replace(`{{$payment_data['cancel_url']}}`);
                    // This will be true whenever user clicks on close icon inside the modal or any error happens during the payment
                    // console.log("User has closed the popup, Check for Payment Status");
                }
                if(result.redirect){
                    // This will be true when the payment redirection page couldnt be opened in the same window
                    // This is an exceptional case only when the page is opened inside an inAppBrowser
                    // In this case the customer will be redirected to return url once payment is completed
                     //window.location.replace(`{{$payment_data['success_url']}}`);
                }
                if(result.paymentDetails){
                   window.location.replace(`{{$payment_data['success_url']}}`);
                    // This will be called whenever the payment is completed irrespective of transaction status
                    // console.log("Payment has been completed, Check for Payment Status");
                    // console.log(result.paymentDetails.paymentMessage);
                }
            });
        });
    })();
</script>
</body>
</html>