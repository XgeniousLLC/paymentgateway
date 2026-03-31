# XGenious Payment Gateway

A Laravel package to manage multiple payment gateways with a unified API.

- **Package:** `xgenious/paymentgateway`
- **Author:** Sharifur Rahman
- **License:** MIT
- **PHP:** ^8.2
- **Laravel:** ^12.x

---

## Supported Payment Gateways

| # | Gateway | # | Gateway |
|---|---------|---|---------|
| 1 | PayPal | 2 | Stripe |
| 3 | Paytm | 4 | Midtrans |
| 5 | Razorpay | 6 | Mollie |
| 7 | Flutterwave | 8 | Paystack |
| 9 | Payfast | 10 | Cashfree |
| 11 | Instamojo | 12 | Mercado Pago |
| 13 | SquarePay | 14 | [CinetPay](CinetPay.md) |
| 15 | PayTabs | 16 | BillPlz |
| 17 | Zitopay | 18 | Toyyibpay |
| 19 | Pagali | 20 | Authorize.Net |
| 21 | SitesWay | 22 | TransactionCloud |
| 23 | WiPay | 24 | KineticPay |
| 25 | Senangpay | 26 | SaltPay |
| 27 | Iyzipay | 28 | Paymob |
| 29 | PowertranzPay | 30 | AwdPay |
| 31 | YooMoney | 32 | CoinPayments |
| 33 | SSLCommerz | 34 | Xendit |
| 35 | [Adyen](AdyenPay.md) | 36 | Airwallex |

---

## Installation

```shell
composer require xgenious/paymentgateway
```

**Step 3 — Publish the config file:**

```shell
php artisan vendor:publish --provider="Xgenious\Paymentgateway\Providers\PaymentgatewayServiceProvider"
```

This creates `config/paymentgateway.php`.

---

## Environment Variables

Add the credentials for the gateways you use to your `.env` file:

```env
# Global
SITE_GLOBAL_CURRENCY=USD

# Exchange Rates (when global currency differs from gateway currency)
NGN_EXCHANGE_RATE=
INR_EXCHANGE_RATE=
USD_EXCHANGE_RATE=
IDR_EXCHANGE_RATE=
ZAR_EXCHANGE_RATE=
BRL_EXCHANGE_RATE=

# Stripe
STRIPE_SECRET_KEY=
STRIPE_PUBLIC_KEY=

# PayPal
PAYPAL_MODE=sandbox
PAYPAL_SANDBOX_CLIENT_ID=
PAYPAL_SANDBOX_CLIENT_SECRET=
PAYPAL_SANDBOX_APP_ID=
PAYPAL_LIVE_CLIENT_ID=
PAYPAL_LIVE_CLIENT_SECRET=
PAYPAL_LIVE_APP_ID=

# Midtrans
MIDTRANS_MERCHANT_ID=
MIDTRANS_SERVER_KEY=
MIDTRANS_CLIENT_KEY=
MIDTRANS_ENVAIRONTMENT=false

# Paytm
PAYTM_ENVIRONMENT=local
PAYTM_MERCHANT_ID=
PAYTM_MERCHANT_KEY=
PAYTM_MERCHANT_WEBSITE=
PAYTM_CHANNEL=
PAYTM_INDUSTRY_TYPE=

# Razorpay
RAZORPAY_API_KEY=
RAZORPAY_API_SECRET=

# Mollie
MOLLIE_KEY=

# Flutterwave
FLW_PUBLIC_KEY=
FLW_SECRET_KEY=
FLW_SECRET_HASH=

# Paystack
PAYSTACK_PUBLIC_KEY=
PAYSTACK_SECRET_KEY=
PAYSTACK_PAYMENT_URL=https://api.paystack.co
MERCHANT_EMAIL=

# PayFast
PF_MERCHANT_ID=
PF_MERCHANT_KEY=
PAYFAST_PASSPHRASE=
PF_MERCHANT_ENV=true
PF_ITN_URL=

# Cashfree
CASHFREE_TEST_MODE=true
CASHFREE_APP_ID=
CASHFREE_SECRET_KEY=

# Instamojo
INSTAMOJO_CLIENT_ID=
INSTAMOJO_CLIENT_SECRET=
INSTAMOJO_TEST_MODE=true

# Mercado Pago
MERCADO_PAGO_CLIENT_ID=
MERCADO_PAGO_CLIENT_SECRET=
MERCADO_PAGO_TEST_MODE=true
```

---

## Basic Usage

Every gateway follows the same two-method pattern:

1. `charge_customer(array $data)` — initiates payment, returns redirect response
2. `ipn_response()` — handles the webhook/IPN callback

### Common `charge_customer` parameters

```php
[
    'amount'       => 10,
    'title'        => 'Order #56',
    'description'  => 'Payment for order #56',
    'ipn_url'      => route('payment.gateway.ipn'),
    'order_id'     => 56,
    'track'        => 'unique_tracking_id',
    'cancel_url'   => route('payment.failed'),
    'success_url'  => route('payment.success'),
    'email'        => 'customer@example.com',
    'name'         => 'Customer Name',
    'payment_type' => 'order',
]
```

---

## Gateway Setup Examples

### Stripe

```php
// charge_customer
$stripe = XgPaymentGateway::stripe();
$stripe->setSecretKey('sk_test_...');
$stripe->setPublicKey('pk_test_...');
$stripe->setCurrency('USD');
$stripe->setEnv(true); // true = sandbox, false = live

$response = $stripe->charge_customer([...]);
return $response;

// ipn_response
$stripe = XgPaymentGateway::stripe();
$stripe->setSecretKey('sk_test_...');
$stripe->setPublicKey('pk_test_...');
$stripe->setEnv(true);
dd($stripe->ipn_response());
```

**IPN Route:**
```php
Route::post('/stripe-ipn', [PaymentLogController::class, 'stripe_ipn'])->name('payment.stripe.ipn');
```

---

### PayPal

```php
// charge_customer
$paypal = XgPaymentGateway::paypal();
$paypal->setClientId('client_id');
$paypal->setClientSecret('client_secret');
$paypal->setAppId('app_id');
$paypal->setCurrency('USD');
$paypal->setEnv(true); // true = sandbox

$response = $paypal->charge_customer([...]);
return $response;

// ipn_response
$paypal = XgPaymentGateway::paypal();
$paypal->setClientId('client_id');
$paypal->setClientSecret('client_secret');
$paypal->setAppId('app_id');
$paypal->setEnv(true);
dd($paypal->ipn_response());
```

**IPN Route:**
```php
Route::get('/paypal-ipn', [PaymentLogController::class, 'paypal_ipn'])->name('payment.paypal.ipn');
```

---

### Paytm

```php
// charge_customer
$paytm = XgPaymentGateway::paytm();
$paytm->setMerchantId('your_merchant_id');
$paytm->setMerchantKey('your_merchant_key');
$paytm->setMerchantWebsite('WEBSTAGING');
$paytm->setChannel('WEB');
$paytm->setIndustryType('Retail');
$paytm->setCurrency('INR');
$paytm->setEnv(true); // true = sandbox
$paytm->setExchangeRate(74); // required if currency is not INR

$response = $paytm->charge_customer([...]);
return $response;

// ipn_response
$paytm = XgPaymentGateway::paytm();
$paytm->setMerchantId('your_merchant_id');
$paytm->setMerchantKey('your_merchant_key');
$paytm->setMerchantWebsite('WEBSTAGING');
$paytm->setChannel('WEB');
$paytm->setIndustryType('Retail');
$paytm->setEnv(true);
dd($paytm->ipn_response());
```

---

### Midtrans

```php
// charge_customer
$midtrans = XgPaymentGateway::midtrans();
$midtrans->setClientKey('your_client_key');
$midtrans->setServerKey('your_server_key');
$midtrans->setCurrency('IDR');
$midtrans->setEnv(true); // true = sandbox
$midtrans->setExchangeRate(14000); // required if currency is not IDR

$response = $midtrans->charge_customer([...]);
return $response;

// ipn_response
$midtrans = XgPaymentGateway::midtrans();
$midtrans->setClientKey('your_client_key');
$midtrans->setServerKey('your_server_key');
$midtrans->setEnv(true);
dd($midtrans->ipn_response());
```

**IPN Route:**
```php
Route::get('/midtrans-ipn', [PaymentLogController::class, 'midtrans_ipn'])->name('payment.midtrans.ipn');
```

**Midtrans Test Cards:**
```
VISA (3DS Enabled):      4811 1111 1111 1114
VISA (3DS Disabled):     4411 1111 1111 1118
Mastercard (3DS Enabled): 5211 1111 1111 1117
Mastercard (3DS Disabled): 5410 1111 1111 1116
```

---

### Razorpay

```php
// charge_customer
$razorpay = XgPaymentGateway::razorpay();
$razorpay->setApiKey('your_api_key');
$razorpay->setApiSecret('your_api_secret');
$razorpay->setCurrency('INR');
$razorpay->setEnv(true);
$razorpay->setExchangeRate(74); // required if currency is not INR

$response = $razorpay->charge_customer([...]);
return $response;

// ipn_response
$razorpay = XgPaymentGateway::razorpay();
$razorpay->setApiKey('your_api_key');
$razorpay->setApiSecret('your_api_secret');
$razorpay->setEnv(true);
dd($razorpay->ipn_response());
```

---

### Mollie

```php
// charge_customer
$mollie = XgPaymentGateway::mollie();
$mollie->setApiKey('your_api_key');
$mollie->setCurrency('EUR');
$mollie->setEnv(true);

$response = $mollie->charge_customer([...]);
return $response;

// ipn_response
$mollie = XgPaymentGateway::mollie();
$mollie->setApiKey('your_api_key');
$mollie->setCurrency('EUR');
$mollie->setEnv(true);
dd($mollie->ipn_response());
```

**IPN Route:**
```php
Route::get('/mollie-ipn', [PaymentLogController::class, 'mollie_ipn'])->name('payment.mollie.ipn');
```

---

### Flutterwave

```php
// charge_customer
$flutterwave = XgPaymentGateway::flutterwave();
$flutterwave->setPublicKey('your_public_key');
$flutterwave->setSecretKey('your_secret_key');
$flutterwave->setSecretHash('your_secret_hash');
$flutterwave->setCurrency('NGN');
$flutterwave->setEnv(true);

$response = $flutterwave->charge_customer([...]);
return $response;

// ipn_response
$flutterwave = XgPaymentGateway::flutterwave();
$flutterwave->setSecretKey('your_secret_key');
$flutterwave->setSecretHash('your_secret_hash');
$flutterwave->setEnv(true);
dd($flutterwave->ipn_response());
```

**IPN Route:**
```php
Route::get('/flutterwave-ipn', [PaymentLogController::class, 'flutterwave_ipn'])->name('payment.flutterwave.ipn');
```

**Flutterwave Test Cards:**
```
Mastercard (PIN): 5531 8866 5214 2950 | CVV: 564 | Expiry: 09/32 | PIN: 3310 | OTP: 12345
Visa:             4556 0527 0417 2643 | CVV: 899 | Expiry: 09/32 | PIN: 3310 | OTP: 12345
```

---

### Paystack

```php
// charge_customer
$paystack = XgPaymentGateway::paystack();
$paystack->setPublicKey('your_public_key');
$paystack->setSecretKey('your_secret_key');
$paystack->setMerchantEmail('merchant@example.com');
$paystack->setCurrency('NGN');
$paystack->setEnv(true);

$response = $paystack->charge_customer([...]);
return $response;

// ipn_response
$paystack = XgPaymentGateway::paystack();
$paystack->setSecretKey('your_secret_key');
$paystack->setEnv(true);
dd($paystack->ipn_response());
```

---

### Payfast

```php
// charge_customer
$payfast = XgPaymentGateway::payfast();
$payfast->setMerchantId('your_merchant_id');
$payfast->setMerchantKey('your_merchant_key');
$payfast->setPassphrase('your_passphrase');
$payfast->setCurrency('ZAR');
$payfast->setEnv(true);

$response = $payfast->charge_customer([...]);
return $response;

// ipn_response
$payfast = XgPaymentGateway::payfast();
$payfast->setMerchantId('your_merchant_id');
$payfast->setMerchantKey('your_merchant_key');
$payfast->setPassphrase('your_passphrase');
$payfast->setEnv(true);
dd($payfast->ipn_response());
```

---

### Cashfree

```php
// charge_customer
$cashfree = XgPaymentGateway::cashfree();
$cashfree->setAppId('your_app_id');
$cashfree->setSecretKey('your_secret_key');
$cashfree->setCurrency('INR');
$cashfree->setEnv(true); // true = test mode

$response = $cashfree->charge_customer([...]);
return $response;

// ipn_response
$cashfree = XgPaymentGateway::cashfree();
$cashfree->setAppId('your_app_id');
$cashfree->setSecretKey('your_secret_key');
$cashfree->setEnv(true);
dd($cashfree->ipn_response());
```

---

### Instamojo

```php
// charge_customer
$instamojo = XgPaymentGateway::instamojo();
$instamojo->setClientId('your_client_id');
$instamojo->setClientSecret('your_client_secret');
$instamojo->setCurrency('INR');
$instamojo->setEnv(true); // true = test mode

$response = $instamojo->charge_customer([...]);
return $response;

// ipn_response
$instamojo = XgPaymentGateway::instamojo();
$instamojo->setClientId('your_client_id');
$instamojo->setClientSecret('your_client_secret');
$instamojo->setEnv(true);
dd($instamojo->ipn_response());
```

---

### Mercado Pago

```php
// charge_customer
$mercadopago = XgPaymentGateway::mercadopago();
$mercadopago->setClientId('your_client_id');
$mercadopago->setClientSecret('your_client_secret');
$mercadopago->setCurrency('BRL');
$mercadopago->setEnv(true);

$response = $mercadopago->charge_customer([...]);
return $response;

// ipn_response
$mercadopago = XgPaymentGateway::mercadopago();
$mercadopago->setClientId('your_client_id');
$mercadopago->setClientSecret('your_client_secret');
$mercadopago->setEnv(true);
dd($mercadopago->ipn_response());
```

---

### CinetPay

```php
$cinetpay = XgPaymentGateway::cinetpay();
```

See detailed setup: [CinetPay.md](CinetPay.md)

---

### Authorize.Net

```php
// charge_customer
$authorizenet = XgPaymentGateway::authorizenet();
$authorizenet->setApiLoginId('your_login_id');
$authorizenet->setTransactionKey('your_transaction_key');
$authorizenet->setCurrency('USD');
$authorizenet->setEnv(true);

$response = $authorizenet->charge_customer([...]);
return $response;

// ipn_response
$authorizenet = XgPaymentGateway::authorizenet();
$authorizenet->setApiLoginId('your_login_id');
$authorizenet->setTransactionKey('your_transaction_key');
$authorizenet->setEnv(true);
dd($authorizenet->ipn_response());
```

---

### Adyen

See detailed setup: [AdyenPay.md](AdyenPay.md)

---

### Other Gateways

The following gateways follow the same `charge_customer` / `ipn_response` pattern. Refer to the individual gateway class in `src/Base/Gateways/` for available setter methods:

| Facade Method | Gateway |
|---|---|
| `XgPaymentGateway::squareup()` | SquarePay |
| `XgPaymentGateway::paytabs()` | PayTabs |
| `XgPaymentGateway::billplz()` | BillPlz |
| `XgPaymentGateway::zitopay()` | ZitoPay |
| `XgPaymentGateway::toyyibpay()` | Toyyibpay |
| `XgPaymentGateway::pagalipay()` | Pagali |
| `XgPaymentGateway::sitesway()` | SitesWay |
| `XgPaymentGateway::transactionclud()` | TransactionCloud |
| `XgPaymentGateway::wipay()` | WiPay |
| `XgPaymentGateway::kineticpay()` | KineticPay |
| `XgPaymentGateway::senangpay()` | Senangpay |
| `XgPaymentGateway::saltpay()` | SaltPay |
| `XgPaymentGateway::iyzipay()` | Iyzipay |
| `XgPaymentGateway::paymob()` | Paymob |
| `XgPaymentGateway::powertranz()` | PowertranzPay |
| `XgPaymentGateway::awdPay()` | AwdPay |
| `XgPaymentGateway::yoomoney()` | YooMoney |
| `XgPaymentGateway::coinpayments()` | CoinPayments |
| `XgPaymentGateway::sslcommerz()` | SSLCommerz |
| `XgPaymentGateway::xendit()` | Xendit |
| `XgPaymentGateway::airwallex()` | Airwallex |
| `XgPaymentGateway::adyen()` | Adyen |

---

## Developer Guide: Adding a New Gateway

This guide walks through adding a new payment gateway to the package.

### Architecture Overview

```
src/
├── Base/
│   ├── PaymentGatewayBase.php       ← abstract base class all gateways extend
│   ├── PaymentGatewayHelpers.php    ← registers all gateway factory methods
│   └── Gateways/
│       └── YourGatewayPay.php       ← your new gateway class
├── Traits/
│   ├── PaymentEnvironment.php       ← provides setEnv() / getEnv()
│   ├── CurrencySupport.php          ← provides setCurrency() / getCurrency() / setExchangeRate()
│   └── ConvertUsdSupport.php        ← provides get_amount_in_usd() for currency conversion
src/Facades/
│   └── XgPaymentGateway.php         ← the facade users call
config/
│   └── paymentgateway.php           ← gateway credentials config
```

Every gateway class must extend `PaymentGatewayBase` and implement six abstract methods.

---

### Step 1 — Create the Gateway Class

Create `src/Base/Gateways/YourGatewayPay.php`:

```php
<?php

namespace Xgenious\Paymentgateway\Base\Gateways;

use Xgenious\Paymentgateway\Base\PaymentGatewayBase;
use Xgenious\Paymentgateway\Traits\CurrencySupport;
use Xgenious\Paymentgateway\Traits\PaymentEnvironment;
use Xgenious\Paymentgateway\Traits\ConvertUsdSupport; // include if you need USD conversion

class YourGatewayPay extends PaymentGatewayBase
{
    use PaymentEnvironment, CurrencySupport, ConvertUsdSupport;

    // 1. Declare credential properties
    protected string $api_key;
    protected string $secret_key;

    // 2. Add public setters (fluent — return $this)
    public function setApiKey(string $key): static
    {
        $this->api_key = $key;
        return $this;
    }

    public function setSecretKey(string $key): static
    {
        $this->secret_key = $key;
        return $this;
    }

    // 3. Private getters
    private function getApiKey(): string { return $this->api_key; }
    private function getSecretKey(): string { return $this->secret_key; }

    // 4. Implement: convert amount to the unit the gateway expects
    public function charge_amount($amount): float|int
    {
        // If the site currency is natively supported, use it directly.
        // Otherwise, convert to USD (or the gateway's base currency).
        if (in_array($this->getCurrency(), $this->supported_currency_list(), true)) {
            return $amount;
        }
        return $this->get_amount_in_usd($amount);
    }

    // 5. Implement: initiate a payment and redirect the customer
    public function charge_customer(array $args): mixed
    {
        $amount = $this->charge_amount($args['amount']);

        // Call the gateway SDK / HTTP API to create a payment session.
        // $args keys: amount, title, description, ipn_url, order_id,
        //             track, cancel_url, success_url, email, name, payment_type

        $response = YourGatewaySdk::createPayment([
            'amount'      => $amount,
            'currency'    => $this->charge_currency(),
            'redirect_url'=> $args['success_url'],
            'webhook_url' => $args['ipn_url'],
            'reference'   => $args['order_id'],
        ]);

        // Store anything you need to verify the payment later
        session()->put('yourgateway_payment_id', $response->id);
        session()->put('yourgateway_order_id',   $args['order_id']);

        // Return a redirect to the hosted payment page
        return redirect($response->payment_url);
    }

    // 6. Implement: verify the payment on the IPN/callback route
    public function ipn_response(array $args = []): array
    {
        $payment_id = session()->get('yourgateway_payment_id');
        $order_id   = session()->get('yourgateway_order_id');
        session()->forget(['yourgateway_payment_id', 'yourgateway_order_id']);

        $payment = YourGatewaySdk::getPayment($payment_id);

        if ($payment->status === 'paid') {
            // verified_data() merges ['status' => 'complete'] with your array
            return $this->verified_data([
                'transaction_id' => $payment->id,
                'order_id'       => $order_id,
            ]);
        }

        return ['status' => 'failed', 'order_id' => $order_id];
    }

    // 7. Implement: currencies this gateway natively accepts
    public function supported_currency_list(): array
    {
        return ['USD', 'EUR', 'GBP']; // add all currencies the gateway accepts
    }

    // 8. Implement: return the currency that will actually be charged
    public function charge_currency(): string
    {
        return $this->getCurrency();
    }

    // 9. Implement: a lowercase slug for this gateway
    public function gateway_name(): string
    {
        return 'yourgateway';
    }
}
```

---

### Step 2 — Register the Gateway in `PaymentGatewayHelpers`

Open `src/Base/PaymentGatewayHelpers.php` and add:

```php
use Xgenious\Paymentgateway\Base\Gateways\YourGatewayPay;

// inside the class:
public function yourgateway(): YourGatewayPay
{
    return new YourGatewayPay();
}
```

The facade (`XgPaymentGateway`) proxies all calls to this class, so the method is immediately available:

```php
XgPaymentGateway::yourgateway()
```

---

### Step 3 — Add Credentials to the Config (optional)

If the gateway needs credentials from `.env`, add a block to `config/paymentgateway.php`:

```php
'yourgateway' => [
    'api_key'    => env('YOURGATEWAY_API_KEY'),
    'secret_key' => env('YOURGATEWAY_SECRET_KEY'),
    'test_mode'  => env('YOURGATEWAY_TEST_MODE', true),
],
```

And add the corresponding keys to `.env`:

```env
YOURGATEWAY_API_KEY=
YOURGATEWAY_SECRET_KEY=
YOURGATEWAY_TEST_MODE=true
```

---

### Step 4 — Add a Blade View (if redirect-based)

Some gateways (e.g. Stripe, Razorpay) render a Blade view instead of a direct redirect. If your gateway needs one, create:

```
resources/views/yourgateway.blade.php
```

Return it from `charge_customer`:

```php
public function charge_customer(array $args): mixed
{
    return view('paymentgateway::yourgateway', [
        'data' => [
            'api_key' => $this->getApiKey(),
            'amount'  => $this->charge_amount($args['amount']),
            // ...
        ]
    ]);
}
```

---

### Step 5 — Add an IPN Route

In your application's `routes/web.php` (or the consuming app), register the callback route:

```php
Route::get('/yourgateway-ipn', [PaymentLogController::class, 'yourgateway_ipn'])
    ->name('payment.yourgateway.ipn');
```

Then in the controller:

```php
public function yourgateway_ipn()
{
    $yourgateway = XgPaymentGateway::yourgateway();
    $yourgateway->setApiKey(config('paymentgateway.yourgateway.api_key'));
    $yourgateway->setSecretKey(config('paymentgateway.yourgateway.secret_key'));
    $yourgateway->setEnv(config('paymentgateway.yourgateway.test_mode'));

    $response = $yourgateway->ipn_response();

    if ($response['status'] === 'complete') {
        // update order, mark as paid
    }
}
```

---

### Available Traits

| Trait | Methods provided |
|---|---|
| `PaymentEnvironment` | `setEnv(bool)`, `getEnv()` |
| `CurrencySupport` | `setCurrency(string)`, `getCurrency()`, `setExchangeRate(float)`, `getExchangeRate()` |
| `ConvertUsdSupport` | `get_amount_in_usd(float)` — converts any amount to USD using the exchange rate |
| `IndianCurrencySupport` | Converts to INR |
| `IDRCurrencySupport` | Converts to IDR |
| `NigeriaCurrencySupport` | Converts to NGN |

### `verified_data()` Return Format

`ipn_response()` must return an array. On success, use `$this->verified_data([...])` which auto-merges `status => complete`:

```php
// success
return $this->verified_data([
    'transaction_id' => 'txn_abc123',
    'order_id'       => 56,
]);
// result: ['status' => 'complete', 'transaction_id' => 'txn_abc123', 'order_id' => 56]

// failure
return ['status' => 'failed', 'order_id' => $order_id];
```

### Recurring Payment Support (optional)

If the gateway supports subscriptions, implement the `RecurringSupport` interface:

```php
use Xgenious\Paymentgateway\Base\RecurringSupport;

class YourGatewayPay extends PaymentGatewayBase implements RecurringSupport
{
    public function charge_customer_recurring(array $args): mixed { ... }
    public function ipn_response_recurring(array $args = []): array { ... }
}
```

---

## Running Tests

```shell
composer test
```

## Static Analysis

```shell
composer analysis
```

---

## Contributing

See [Contribution.md](Contribution.md) for guidelines.

---

## License

MIT © [XgeniousLLC](https://github.com/XgeniousLLC/paymentgateway)
