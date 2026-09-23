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
| India | **Razorpay** | INR | ✅ native Subscriptions | Dynamic plan create/reuse; `pause/resume/cancel/fetch`; webhook secret verify |
| India | Paytm | INR | ➖ one-time only | Keep email-reminder fallback |
| India | Cashfree | INR | ➖ one-time only | Subscriptions API not wrapped yet |
| India | Instamojo | INR | ➖ one-time only | No subscription API in package |
| Africa | Paystack | NGN,GHS,ZAR... | 🔶 provider supports, not wrapped | Next candidate: wrap `/subscription` + webhook `charge.success` |
| Africa | Flutterwave | NGN + 30 | 🔶 provider supports, not wrapped | Next candidate: wrap payment-plans API |
| Africa | Payfast | ZAR | 🔶 recurring billing exists upstream | Needs ITN-based recurring wrapper |
| Africa | CinetPay | XOF,XAF | ➖ one-time only |  |
| EU/UK | Mollie | EUR+ | 🔶 Sequences API exists upstream | Needs mandates + `ipn_response_recurring` wrapper |
| EU/UK | Adyen | 150+ | 🔶 tokenized recurring upstream | Needs wrapper |
| LATAM | MercadoPago | BRL,ARS... | 🔶 preapproval API upstream | Needs wrapper |
| SEA | Midtrans | IDR | ➖ one-time only |  |
| SEA | Xendit | IDR,MYR,PHP,THB,VND | 🔶 recurring plans upstream | Needs wrapper |
| SEA | BillPlz | MYR | ➖ one-time only |  |
| SEA | Toyyibpay | MYR | ➖ one-time only |  |
| SEA | Senangpay | MYR | ✅ native recurring URL flow | Implements `RecurringSupport` |
| MENA | PayTabs | AED,SAR,... | 🔶 tokenize + recurring upstream | Needs wrapper |
| MENA | Paymob | EGP + | ➖ one-time only |  |
| US | PayPal | 25+ | 🔶 Billing Plans upstream | Needs wrapper |
| US | Authorize.Net | USD | 🔶 ARB upstream | Needs wrapper |
| US | Square | USD+ | 🔶 subscriptions upstream | Needs wrapper |
| TR | Iyzipay | TRY | 🔶 subscription upstream | Needs wrapper |
| BD | SSLCommerz | BDT | ➖ one-time only |  |
| RU/CIS | YooMoney | RUB | ➖ one-time only |  |

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
