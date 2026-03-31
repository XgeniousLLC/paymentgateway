# CinetPay Integration Guide


This guide provides a complete working integration of CinetPay into your Laravel application.

---

## 📦 Prerequisites

- Laravel project
- Composer installed
- CinetPay credentials
- `XgPaymentGateway` package

---

## 🧪 Step 1: Environment Configuration

Add the following to your `.env` file:

```env
CINETPAY_APP_KEY=12912847765bc0db748fdd44.40081707
CINETPAY_SITE_ID=445160
CINETPAY_TEST_MODE=on
````
## OR

Configure these in the admin panel:
**Admin Panel → General Settings → Payment Gateway Settings → CinetPay**

---

## 🔁 Step 2: Define Routes

Add the following to your `routes/web.php` file:

```php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentLogController;

Route::match(['get', 'post'], 'cinetpay-notify', [PaymentLogController::class, 'cinetpay_ipn'])
    ->name('payment.cinetpay.ipn');

Route::match(['get', 'post'], 'cinetpay-return', [PaymentLogController::class, 'cinetpay_return'])
    ->name('payment.cinetpay.return');
```

---

## 🔐 Step 3: Exclude Notify Route from CSRF

Open `app/Http/Middleware/VerifyCsrfToken.php` and add:

```php
protected $except = [
    'cinetpay-notify',
    'cinetpay-return',
];
```

---

## 💸 Step 4: Initialize Payment

You can use the following example in your order controller or payment service class:

```php
$cinetpay = XgPaymentGateway::cinetpay();
$cinetpay->setAppKey(get_static_option('cinetpay_app_key') ?? '');
$cinetpay->setSiteId(get_static_option('cinetpay_site_id'));
$cinetpay->setEnv(get_static_option('cinetpay_test_mode') === 'on');

$response = $cinetpay->charge_customer([
    'amount' => 10,
    'title' => 'Order Payment',
    'description' => 'Order via CinetPay',
    'ipn_url' => $notify_url,
    'order_id' => 56,
    'track' => Str::random(36),
    'cancel_url' => route('payment.failed'),
    'success_url' => $retrun_url,
    'email' => 'buyer@example.com',
    'name' => 'John Doe',
    'payment_type' => 'order',
]);

return $response;
```

---

## 📥 Step 5: IPN Handler (Notify URL)

In `PaymentController.php`:

```php
public function cinetpay_ipn(Request $request)
{
    $cinetpay = XgPaymentGateway::cinetpay();
    $cinetpay->setAppKey(get_static_option('cinetpay_app_key') ?? '');
    $cinetpay->setSiteId(get_static_option('cinetpay_site_id'));
    $cinetpay->setEnv(get_static_option('cinetpay_test_mode') === 'on');

    $ipn_response = $cinetpay->ipn_response();

    if ($ipn_response['status'] === 'complete') {
        $order_id = $ipn_response['order_id'];
        $track = $ipn_response['track'];

        // ✅ Mark order/payment as complete
    }

    return response()->json('IPN Received', 200);
}
```

---

## 🔄 Step 6: Return Handler (Success URL)

In the same `PaymentController.php` file:

```php
public function cinetpay_return(Request $request)
{
    $transaction_id = $request->cpm_trans_id;

    // 🔄 Optionally verify or redirect
    return redirect()->route('frontend.order.payment.success');
}
```

---

## 🧪 Test Credentials (from CinetPay)

```text
API Key: 12912847765bc0db748fdd44.40081707
Site ID: 445160
```

Enable test mode with:

```env
CINETPAY_TEST_MODE=on
```

---

## ✅ Integration Checklist

* [x] `.env` configured
* [x] CinetPay enabled in admin panel
* [x] Routes defined
* [x] CSRF protection bypassed for notify
* [x] Payment initiated via `charge_customer()`
* [x] IPN handler set
* [x] Return handler set

---

> 📘 Need help? Check [CinetPay's official documentation](https://docs.cinetpay.com/api/1.0-en/introduction/overview/)

🙏 Thank You!
Thanks for using this integration guide.

Happy coding! 💻✨