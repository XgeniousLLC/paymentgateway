### Adyen Integration Guide

This guide provides a complete working integration of Adyen into your Laravel application using the `XgPaymentGateway` package.

---

## 📦 Prerequisites
- Laravel project
- Composer installed
- Adyen credentials (API Key, Merchant Account, Webhook Secret)
- Client Key (for dropin flow)
- `XgPaymentGateway` package

---

## 🔧 Step 1: Environment Configuration
Add these to your `.env` file:
```env
ADYEN_API_KEY=YOUR_LIVE_API_KEY
ADYEN_MERCHANT_ACCOUNT=YOUR_MERCHANT_ACCOUNT
ADYEN_WEBHOOK_SECRET=YOUR_HMAC_SECRET
ADYEN_CLIENT_KEY=YOUR_CLIENT_KEY  # Required for dropin flow
ADYEN_FLOW_TYPE=dropin            # 'hosted' or 'dropin'
ADYEN_TEST_MODE=on  # Set to empty for production
```

**OR** configure in admin panel:  
**Admin Panel → General Settings → Payment Gateway Settings → Adyen**
```php
'adyen_api_key' => 'required|string|max:255',
'adyen_merchant_account' => 'required|string|max:255',
'adyen_webhook_secret' => 'required|string|max:255',
'adyen_client_key' => 'nullable|string|max:255',
'adyen_flow_type' => 'nullable|string|in:hosted,dropin',
'adyen_test_mode' => 'nullable|string|max:255',
```
---

## 🔁 Step 2: Define Routes
Add to `routes/web.php`:
```php
use Illuminate\Support\Facades\Route;

// Webhook route (POST only)
Route::post('frontend/payments/adyen-webhook', 'PaymentController@adyen_webhook_for_all')
    ->name('adyen.webhook.all');

// Return URL handler
Route::get('frontend/payments/success', 'PaymentController@handle_adyen_return')
    ->name('adyen.ipn.return');
```

---

## 🔐 Step 3: Exclude Webhook Route from CSRF
In `app/Http/Middleware/VerifyCsrfToken.php`:
```php
protected $except = [
    'frontend/payments/adyen-webhook',
];
```

---

## 💸 Step 4: Initialize Payment
Example payment initialization:
```php
use XgPaymentGateway;

$flow_type = get_static_option('adyen_flow_type') ?? 'hosted';
$adyen = XgPaymentGateway::adyen();
$adyen->setApiKey(get_static_option('adyen_api_key') ?? '');
if ($flow_type === 'dropin') {
        $adyen->setClientKey(get_static_option('adyen_client_key') ?? '');
    }
$adyen->setMerchantAccount(get_static_option('adyen_merchant_account') ?? '');
$adyen->setWebhookSecret(get_static_option('adyen_webhook_secret') ?? '');
$adyen->setEnv(get_static_option('adyen_test_mode') == 'on');

$response = $adyen->charge_customer([
    'amount' => 100,  // Amount in currency's smallest unit
    'title' => 'Order Payment',
    'description' => 'Order via Adyen',
    'ipn_url' => route('adyen.webhook.all'),
    'order_id' => 56,
    'track' => \Str::random(36),
    'cancel_url' => route('payment.failed'),
    'success_url' => route('adyen.ipn.return', [
        'order_id' => 56,
        'payment_type' => 'order'
    ]),
    'email' => 'buyer@example.com',
    'name' => 'John Doe',
    'payment_type' => 'order',
    'currency' => 'USD',
    'flow_type' => $flow_type
]);

return $response;
```

---

## 📥 Step 5: Webhook Handler (IPN)
Create this in your controller:
```php
public function adyen_webhook_for_all(Request $request)
{
    Log::info('Adyen Webhook Received:', $request->all());
    
    try {
        $adyen = XgPaymentGateway::adyen();
        $adyen->setApiKey(get_static_option('adyen_api_key') ?? '');
        $adyen->setMerchantAccount(get_static_option('adyen_merchant_account') ?? '');
        $adyen->setWebhookSecret(get_static_option('adyen_webhook_secret') ?? '');
        $adyen->setEnv(get_static_option('adyen_test_mode') == 'on');

        $payment_data = $adyen->ipn_response($request->all());
        
        if ($payment_data['status'] === 'complete') {
            $this->handle_success($payment_data);
        }

    } catch (\Exception $e) {
        Log::error('Adyen Webhook Error: '.$e->getMessage());
    }

    return response()->json(['success' => true], 200);
}

private function handle_success(array $payment_data)
{
    // Handle different payment types (wallet/order/subscription/etc.)
    $type = $payment_data['payment_type'] ?? 'web';
    
    switch ($type) {
        case 'freelancer-wallet':
            // Update wallet logic
            break;
        case 'order':
            // Process order
            break;
        // ... other cases
    }
}
```

---

## 🔄 Step 6: Return URL Handler
```php
public function handle_adyen_return(Request $request)
{
    $order_id = $request->get('order_id');
    $payment_type = $request->get('payment_type');

    return view('payment.processing', [
        'order_id'     => $order_id,
        'payment_type' => $payment_type
    ]);
}
```

---

## 🧪 Testing & Credentials
1. **Test Mode**: Set `ADYEN_TEST_MODE=on` in `.env`
2. **Test Cards**:
   - Success: `5555 5555 5555 4444`
   - [More test cards](https://docs.adyen.com/development-resources/testing/test-card-numbers/)
3. **Webhook Testing**: Use Adyen's [Webhook Simulator](https://docs.adyen.com/development-resources/webhooks/simulate-webhooks)

---

## ✅ Integration Checklist
- [ ] `.env` configured with Adyen credentials
- [ ] Routes defined for webhook and return URL
- [ ] Webhook excluded from CSRF protection
- [ ] Payment initialization implemented
- [ ] Webhook handler validates and processes payments
- [ ] Return handler displays processing view
- [ ] Tested with Adyen sandbox

> 📘 Official Docs: [Adyen Laravel Integration Guide](https://docs.adyen.com/online-payments)

---

### Key Implementation Notes:
1. **Webhook Security**: Always validate HMAC signatures using `setWebhookSecret()`
2. **Error Handling**: Implement robust logging in all payment flows
3. **Currency Handling**: Amounts should be in minor units (e.g., $10.00 = 1000)

For production:
- Remove test mode flag (`ADYEN_TEST_MODE=`)
- Use live API keys
- Configure webhook in Adyen Customer Area


🙏 Thank You!
Thanks for using this integration guide.

Happy coding! 💻🚀