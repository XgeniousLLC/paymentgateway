# CinetPay Integration Guide


This guide provides a complete working integration of CinetPay into your Laravel application.

---

## 📦 Prerequisites

- Laravel project
- Composer installed
- CinetPay credentials
- `XgPaymentGateway` package

---

## Step 1: Get Your Credentials

Log in to your [CinetPay dashboard](https://cinetpay.com) and retrieve:

- **API Key** (App Key)
- **Site ID**

Pass them directly via setter methods — see Step 4 below.

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

## ✅ Integration Checklist

* [x] CinetPay API Key and Site ID retrieved from dashboard
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