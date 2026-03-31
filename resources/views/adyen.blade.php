<!DOCTYPE html>
<html>

<head>
    <title>Adyen Payment</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet"
        href="https://checkoutshopper-{{ $environment }}.adyen.com/checkoutshopper/sdk/5.64.0/adyen.css" />
</head>

<body style="background:#f9f9f9; font-family:sans-serif; padding: 2rem;">

    <div id="dropin-container"
        style="max-width:500px;margin:2rem auto;background:white;padding:2rem;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
    </div>

    <div id="error-container" style="display:none; color:red; text-align:center; margin-top:1rem;">
        <p id="error-message"></p>
    </div>

    <script src="https://checkoutshopper-{{ $environment }}.adyen.com/checkoutshopper/sdk/5.64.0/adyen.js"></script>

    <script>
        async function initializePayment() {
            try {
                const sessionData = @json($adyenSession);
                const clientKey = @json($adyenClientKey);
                const environment = @json($environment);

                if (!clientKey) throw new Error("Missing client key.");
                if (!sessionData || !sessionData.id) throw new Error("Invalid or missing session.");

                const checkout = await AdyenCheckout({
                    environment: environment,
                    clientKey: clientKey,
                    session: sessionData,
                    analytics: {
                        enabled: false
                    },
                    paymentMethodsConfiguration: {
                        card: {
                            hasHolderName: true,
                            holderNameRequired: true,
                            billingAddressRequired: true,
                            enableStoreDetails: true
                        }
                    },
                    onPaymentCompleted: (result, component) => {
                        if (result?.resultCode === 'Authorised' && sessionData.returnUrl) {
                            window.location.href = sessionData.returnUrl;
                        }
                    },
                    onError: (error, component) => {
                        showError(error?.message || "Payment failed");
                    }
                });

                const dropin = checkout.create('dropin', {
                    openFirstPaymentMethod: true,
                    showStoredPaymentMethods: true,
                    showPayButton: true
                });

                dropin.mount('#dropin-container');
            } catch (error) {
                showError("Initialization failed: " + error.message);
            }
        }

        function showError(message) {
            const errorContainer = document.getElementById('error-container');
            const errorMessage = document.getElementById('error-message');
            errorMessage.textContent = message;
            errorContainer.style.display = 'block';
        }

        document.addEventListener("DOMContentLoaded", () => {
            initializePayment();
        });
    </script>
</body>

</html>
