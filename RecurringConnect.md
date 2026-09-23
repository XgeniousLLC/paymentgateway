# Region-Wise Recurring Support Matrix

Reusable reference for all projects consuming `xgenious/paymentgateway`.
Native auto-charge lives in the package; apps only store the subscription id + status.

## Shared contracts (package-level)

| Interface | Methods | Implemented by |
|---|---|---|
| `RecurringSupport` | `charge_customer_recurring($args)`, `ipn_response_recurring($args)` | Stripe, Razorpay, Senangpay |
| `SubscriptionLifecycle` | `cancel_subscription($id, $at_period_end)`, `pause_subscription($id)`, `resume_subscription($id)`, `fetch_subscription($id)` | Stripe, Razorpay |
| `ConnectSupport` | `setDestinationAccountId()`, `setApplicationFeeAmount()`, `create_connect_account()`, `create_account_onboarding_link()` | Stripe |

`charge_customer_recurring($args)` accepts the same keys as `charge_customer()` plus:
`is_subscription`, `recurring_interval` (day/week/month/year + aliases),
`recurring_interval_count`, `destination_account_id`, `application_fee_amount`.
Razorpay additionally accepts `plan_config` (`id,title,price,type,package_description,razorpay_plan_id?`).

## Region-wise matrix

| Region | Gateway | Currency | Recurring | Notes |
|---|---|---|---|---|
| Global | **Stripe** | 135+ (USD,EUR,GBP,...) | ✅ native Billing subscriptions | Checkout `mode=subscription`; webhooks via `construct_webhook_event()`; Connect express + `subscription_data.transfer_data` / `application_fee_percent` in subscription mode (`payment_intent_data` in one-time mode) |
| Global | **PayPal** | AUD,USD,EUR | ✅ Catalog product + billing plan + subscription | Approve via `approve` link; `activate/suspend` map to resume/pause; cancel at gateway |
| India | **Razorpay** | INR | ✅ native Subscriptions | Dynamic plan create/reuse; `pause/resume/cancel/fetch`; webhook secret verify |
| India | **Paytm** | INR | ✅ Subscriptions (PPR params on init) | Frequency unit mapping; cancel/status via checksum-signed calls |
| India | **Cashfree** | INR | ✅ Subscriptions API (UPI Autopay/eNACH/cards) | `pause/resume/cancel/fetch`; webhook HMAC verify |
| India | Instamojo | INR | ➖ one-time only | Provider exposes payment links only, no subscription API |
| Africa | **Paystack** | NGN,GHS,ZAR | ✅ Plans + auto-charge on init with plan code | Webhook HMAC-SHA512 verify; disable/enable map to cancel/resume |
| Africa | **Flutterwave** | NGN + 30 | ✅ Payment Plans API | Pass `payment_plan` id on checkout; secret-hash webhook verify |
| Africa | **Payfast** | ZAR | ✅ Recurring billing fields on checkout | `subscription_type` + billing date; lifecycle managed in PayFast dashboard (no API) |
| EU/UK | **Mollie** | EUR+ | ✅ Customers + mandates + Subscriptions API | First payment creates mandate, subscription chained on IPN; cancel needs customer id |
| US | **Authorize.Net** | USD+ | ✅ ARB subscriptions | Card via Accept.js opaque data or raw fields; cancel/fetch via ARB API |
| US | **Square** | USD+ | ✅ Catalog plan + card-on-file subscription | Needs `square_customer_id` + `square_card_id` from Web Payments SDK; pause/resume/cancel/fetch |
| LATAM | **MercadoPago** | BRL,ARS... | ✅ Preapproval plans | Plan + preapproval checkout; pause/resume/cancel via status update |
| SEA | **Midtrans** | IDR | ✅ Subscription API (Core API `createSubscription`) | Needs `card_token` from Snap/GoPay tokenization; enable/disable map to resume/cancel |
| SEA | **Xendit** | IDR,MYR,PHP,THB,VND | ✅ Recurring payments API | `pause/resume/stop/fetch`; callback-token webhook verify |
| SEA | BillPlz | MYR | ➖ one-time only | No provider recurring |
| SEA | Toyyibpay | MYR | ➖ one-time only | No provider recurring |
| SEA | **Senangpay** | MYR | ✅ native recurring URL flow | Implements `RecurringSupport` |
| MENA | **PayTabs** | AED,SAR,... | ✅ Tokenized recurring (`tran_class=recurring`) | First checkout tokenizes; renewals via `charge_saved_token()`; app-scheduled |
| MENA | Paymob | EGP + | ➖ one-time only | No wrapper yet (Moto/token API exists upstream) |
| TR | Iyzipay | TRY | ➖ one-time only | No subscription API surfaced |
| BD | SSLCommerz | BDT | ➖ one-time only | No provider recurring |
| RU/CIS | YooMoney | RUB | ➖ one-time only | No wrapper yet |

Legend: ✅ wrapped + tested interface · 🔶 provider has API, package wrapper pending · ➖ no provider recurring.

## Stripe usage (recurring + Connect)

```php
$stripe = XgPaymentGateway::stripe();
$stripe->setSecretKey(config('paymentgateway.stripe.secret_key'))
    ->setPublicKey(config('paymentgateway.stripe.public_key'))
    ->setWebhookSecret(config('paymentgateway.stripe.webhook_secret'))
    ->setCurrency('USD')
    ->setEnv(true);

// Monthly auto-charge checkout
return $stripe->charge_customer_recurring([
    'amount' => 25, 'title' => 'Monthly donation',
    'description' => 'Monthly donation', 'order_id' => $logId,
    'track' => $track, 'ipn_url' => route('donation.stripe.ipn'),
    'cancel_url' => route('donation.cancel'), 'email' => $email, 'name' => $name,
    'payment_type' => 'monthly', 'recurring_interval' => 'month',
]);

// With Connect split (platform fee in minor units)
$stripe->setDestinationAccountId('acct_xxx')->setApplicationFeeAmount(100);

// Webhook verification (recurring charges land here)
$result = $stripe->construct_webhook_event($payload, $sigHeader);
// handle: checkout.session.completed, invoice.payment_succeeded,
// invoice.payment_failed, customer.subscription.deleted

// Lifecycle
$stripe->cancel_subscription($subId);            // at period end
$stripe->cancel_subscription($subId, false);     // immediately
$stripe->pause_subscription($subId);
$stripe->resume_subscription($subId);

// Connect onboarding
$acct = $stripe->create_connect_account(['country' => 'US', 'email' => $email]);
$link = $stripe->create_account_onboarding_link($acct['account_id'], [
    'refresh_url' => $refresh, 'return_url' => $return,
]);
```

Config keys (`config/paymentgateway.php` → env):
`STRIPE_SECRET_KEY`, `STRIPE_PUBLIC_KEY`, `STRIPE_WEBHOOK_SECRET`,
`STRIPE_RECURRING_INTERVAL` (default `month`), `STRIPE_RECURRING_INTERVAL_COUNT`,
`STRIPE_CONNECT_ACCOUNT_ID`.

## Razorpay usage (India recurring)

```php
$razorpay = XgPaymentGateway::razorpay();
$razorpay->setApiKey($key)->setApiSecret($secret)
    ->setWebhookSecret($webhookSecret)->setCurrency('INR')->setEnv(true);

return $razorpay->charge_customer_recurring([
    'amount' => 500, 'title' => 'Monthly donation',
    'description' => 'Monthly donation', 'order_id' => $logId,
    'track' => $track, 'ipn_url' => route('donation.razorpay.ipn'),
    'email' => $email, 'name' => $name, 'payment_type' => 'monthly',
    // optional: 'plan_config' => ['id'=>..,'title'=>..,'price'=>..,'type'=>0],
]);
```

## App-side pattern (any project)

1. Add columns to the payment log + subscription table:
   `stripe_subscription_id`, `stripe_customer_id`, `razorpay_subscription_id`,
   `razorpay_plan_id`, `is_recurring`, `subscription_status`, `next_billing_date`.
2. On checkout: call `charge_customer_recurring()`, store pending subscription row.
3. On IPN success: mark `active`, store gateway subscription id.
4. On webhook (`invoice.payment_succeeded` / Razorpay `subscription.charged`): create the
   renewal log entry + increment raised totals.
5. On `invoice.payment_failed` / `subscription.cancelled`: mark `payment_failed`,
   notify donor, keep grace-period logic in the app.
6. Expose donor cancel: `cancel_subscription($id)` then mark app row `cancelled`.
