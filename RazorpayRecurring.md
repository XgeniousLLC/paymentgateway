# Razorpay Recurring Subscription Integration - Migration Guide v2.0

## 📌 Overview
This update adds **Razorpay Recurring Subscription** support to the payment system, enabling automatic subscription renewals for tenants with comprehensive webhook handling, payment failure management, and subscription lifecycle control.

---

## ⚠️ Prerequisites

Before implementing this feature, ensure you have:

- ✅ Laravel 8.x or higher
- ✅ Razorpay account with **Subscriptions feature enabled** (Contact Razorpay support if not enabled)
- ✅ Existing multi-tenant payment system with `PaymentLogs`, `Tenant`, and `PricePlan` models
- ✅ Razorpay payment gateway already integrated
- ✅ Access to server environment variables and webhook configuration
- ✅ Database backup completed before migration

---

## 🗄️ Database Migration

### Step 1: Create Migration File

Create a new migration file:

```bash
php artisan make:migration add_razorpay_subscription_fields_to_tables
```

### Step 2: Add Migration Code

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_razorpay_subscription_fields_to_tables.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add Razorpay subscription fields to payment_logs table
        Schema::table('payment_logs', function (Blueprint $table) {
            $table->string('razorpay_subscription_id')->nullable()->after('transaction_id');
            $table->string('razorpay_plan_id')->nullable()->after('razorpay_subscription_id');
            $table->boolean('is_recurring')->default(0)->after('is_renew');
            $table->enum('subscription_status', [
                'pending', 'active', 'paused', 'cancelled', 
                'payment_failed', 'expired'
            ])->nullable()->after('is_recurring');
            $table->timestamp('next_billing_date')->nullable()->after('expire_date');
            
            // Add indexes for performance
            $table->index('razorpay_subscription_id');
            $table->index('subscription_status');
        });

        // Add Razorpay subscription fields to tenants table
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('razorpay_subscription_id')->nullable()->after('id');
            $table->boolean('recurring_enabled')->default(0)->after('razorpay_subscription_id');
            $table->timestamp('grace_period_end')->nullable()->after('expire_date');
            $table->integer('payment_grace_days')->default(3)->after('grace_period_end');
            $table->unsignedBigInteger('renewal_payment_log_id')->nullable()->after('payment_grace_days');
            
            // Add indexes
            $table->index('razorpay_subscription_id');
            $table->index('grace_period_end');
        });

        // Add Razorpay plan ID to price_plans table
        Schema::table('price_plans', function (Blueprint $table) {
            $table->string('razorpay_plan_id')->nullable()->after('id');
            $table->timestamp('razorpay_synced_at')->nullable()->after('razorpay_plan_id');
            
            $table->index('razorpay_plan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_logs', function (Blueprint $table) {
            $table->dropIndex(['razorpay_subscription_id']);
            $table->dropIndex(['subscription_status']);
            
            $table->dropColumn([
                'razorpay_subscription_id',
                'razorpay_plan_id',
                'is_recurring',
                'subscription_status',
                'next_billing_date'
            ]);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['razorpay_subscription_id']);
            $table->dropIndex(['grace_period_end']);
            
            $table->dropColumn([
                'razorpay_subscription_id',
                'recurring_enabled',
                'grace_period_end',
                'payment_grace_days',
                'renewal_payment_log_id'
            ]);
        });

        Schema::table('price_plans', function (Blueprint $table) {
            $table->dropIndex(['razorpay_plan_id']);
            
            $table->dropColumn([
                'razorpay_plan_id',
                'razorpay_synced_at'
            ]);
        });
    }
};
```

### Step 3: Add System Settings Migration

Create another migration for system settings:

```bash
php artisan make:migration add_razorpay_recurring_system_settings
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add global Razorpay recurring setting
        DB::table('static_options')->insert([
            [
                'option_name' => 'razorpay_recurring_enabled',
                'option_value' => '0', // Disabled by default
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }

    public function down(): void
    {
        DB::table('static_options')
            ->where('option_name', 'razorpay_recurring_enabled')
            ->delete();
    }
};
```

### Step 4: Run Migrations

```bash
php artisan migrate
```

**⚠️ Important:** Test the migration on a staging/development environment first!

---

## 📁 File Structure Setup

Create the following directory structure:

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Landlord/
│   │       └── Frontend/
│   │           ├── PaymentLogController.php (UPDATE EXISTING)
│   │           └── RazorpayWebhookController.php (NEW)
│   └── Middleware/
│       └── VerifyCsrfToken.php (UPDATE EXISTING)
├── Services/
│   └── Payment/
│       └── RazorpaySubscriptionService.php (NEW)
└── Helpers/
    └── Payment/
        └── PaymentGatewayCredential.php (UPDATE EXISTING)

routes/
└── web.php (UPDATE EXISTING)
```

---

## 🔧 Implementation Steps

### Step 1: Update Route Configuration

**File:** `routes/web.php`

Add the webhook route **outside** of any middleware groups that require authentication:

```php
<?php

// Add this BEFORE Route::group() or at the top of your routes file
Route::match(['get', 'post'], '/razorpay-subscription-webhook', function(\Illuminate\Http\Request $request) {
    return app(\App\Http\Controllers\Landlord\Frontend\RazorpayWebhookController::class)->handle($request);
})
->name('landlord.frontend.razorpay.subscription.webhook')
->withoutMiddleware(['csrf', 'auth', 'maintenance_mode']);

// Your existing routes continue below...
```

**📝 Note:** This route must be accessible publicly for Razorpay webhooks to work.

---

### Step 2: Update CSRF Token Middleware

**File:** `app/Http/Middleware/VerifyCsrfToken.php`

```php
<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Add your existing excluded routes here
        '/razorpay-subscription-webhook',
        
        // Example of other common webhook exclusions:
        // '/paypal-ipn',
        // '/stripe-webhook',
    ];
}
```

---

### Step 3: Create RazorpaySubscriptionService

**File:** `app/Services/Payment/RazorpaySubscriptionService.php` </br>
*Follow the main script of Nazmart*

**⚠️ COPY THE ENTIRE FILE** from document index 6 (`RazorpaySubscriptionService.php`)

**Key Features:**
- ✅ Global and per-tenant recurring control
- ✅ Plan synchronization with Razorpay
- ✅ Subscription creation and management
- ✅ Automatic renewal handling
- ✅ Payment failure grace period
- ✅ Email notifications

---

### Step 4: Create RazorpayWebhookController

**File:** `app/Http/Controllers/Landlord/Frontend/RazorpayWebhookController.php` </br>
*Follow the main script of Nazmart*

**⚠️ COPY THE ENTIRE FILE** from document index 5 (`RazorpayWebhookController.php`)

**Handles These Webhook Events:**
- ✅ `payment.authorized` - Initial payment authorization
- ✅ `payment.captured` - Payment capture confirmation
- ✅ `payment.failed` - Payment failure handling
- ✅ `subscription.authenticated` - Subscription setup
- ✅ `subscription.activated` - Subscription activation
- ✅ `subscription.charged` - Automatic renewal
- ✅ `subscription.pending` - Payment pending
- ✅ `subscription.halted` - Payment halted
- ✅ `subscription.cancelled` - Subscription cancellation
- ✅ `qr_code.created` - QR code generation
- ✅ `qr_code.paid` - QR code payment
- ✅ `qr_code.closed` - QR code closure

---

### Step 5: Update PaymentLogController

**File:** `app/Http/Controllers/Landlord/Frontend/PaymentLogController.php`

**⚠️ IMPORTANT:** Make the following changes to your existing file:

#### 5.1: Add Import Statement (Top of File)

```php
<?php

namespace App\Http\Controllers\Landlord\Frontend;

// Add this import
use App\Services\Payment\RazorpaySubscriptionService;

// Your existing imports...
```

#### 5.2: Update `payment_with_gateway()` Method

Find the `payment_with_gateway()` method and replace the Razorpay section:

```php
public function payment_with_gateway($payment_gateway_name, $request = [])
{
    try {
        $gateway_function = 'get_' . $payment_gateway_name . '_credential';

        if (!method_exists((new PaymentGatewayCredential()), $gateway_function)) {
            // ... existing custom gateway code ...
        } else {
            $gateway = PaymentGatewayCredential::$gateway_function();
            
            // ⭐ ADD THIS RAZORPAY SUBSCRIPTION CHECK
            if ($payment_gateway_name === 'razorpay') {
                $plan = PricePlan::find($this->payment_details['package_id']);
                $should_use_recurring = RazorpaySubscriptionService::shouldUseRecurring($this->payment_details['tenant_id'])
                    && $plan
                    && $plan->type != 2; // 2 = lifetime

                if ($should_use_recurring) {
                    Log::info('Using Razorpay subscription', [
                        'tenant_id' => $this->payment_details['tenant_id'],
                        'plan_type' => $plan->type
                    ]);

                    // Update payment log with subscription flags
                    $this->payment_details->update([
                        'is_recurring' => 1,
                        'subscription_status' => 'pending'
                    ]);

                    // Prepare plan config for dynamic subscription
                    $plan_config = [
                        'id' => $plan->id,
                        'title' => $plan->title,
                        'package_description' => $plan->package_description,
                        'price' => $this->total,
                        'type' => $plan->type,
                        'razorpay_plan_id' => $plan->razorpay_plan_id ?? null
                    ];

                    // Prepare charge customer data with subscription info
                    $charge_data = $this->common_charge_customer_data($payment_gateway_name);

                    // Add subscription-specific data
                    $charge_data['is_subscription'] = true;
                    $charge_data['plan_config'] = $plan_config;

                    Log::info('Calling charge_customer with subscription data', [
                        'order_id' => $charge_data['order_id'],
                        'is_subscription' => true
                    ]);

                    $redirect_url = $gateway->charge_customer($charge_data);
                    return $redirect_url;
                } else {
                    Log::info('Using standard Razorpay payment (recurring disabled or lifetime plan)', [
                        'tenant_id' => $this->payment_details['tenant_id'],
                        'plan_type' => $plan ? $plan->type : 'unknown'
                    ]);
                }
            }
            // ⭐ END OF RAZORPAY SUBSCRIPTION CHECK

            // Regular payment process...
            $redirect_url = $gateway->charge_customer(
                $this->common_charge_customer_data($payment_gateway_name)
            );

            return $redirect_url;
        }
    } catch (\Exception $e) {
        return back()->with(['msg' => $e->getMessage(), 'type' => 'danger']);
    }
}
```

---

### Step 6: Update PaymentGatewayCredential Helper

**File:** `app/Helpers/Payment/PaymentGatewayCredential.php`

Add these methods to your existing `PaymentGatewayCredential` class:

```php
<?php

namespace App\Helpers\Payment;

class PaymentGatewayCredential
{
    // ... your existing methods ...

    /**
     * Get Razorpay credentials with subscription support
     */
    public static function get_razorpay_credential()
    {
        $gateway = PaymentGateway::where('name', 'razorpay')->first();
        $credentials = !empty($gateway) ? json_decode($gateway->credentials) : '';

        $key = !empty($credentials) ? $credentials->api_key : '';
        $secret = !empty($credentials) ? $credentials->api_secret : '';
        $webhook_secret = !empty($credentials) && isset($credentials->webhook_secret) 
            ? $credentials->webhook_secret 
            : '';

        return XgPaymentGateway::razorpay()
            ->setKeyId($key)
            ->setKeySecret($secret)
            ->setWebhookSecret($webhook_secret) // Add webhook secret support
            ->setEnv(true);
    }

    // ... rest of your methods ...
}
```

**📝 Note:** Ensure your Razorpay payment gateway package supports `setWebhookSecret()`. If not, you may need to update the package.

---

## 🔐 Razorpay Dashboard Configuration

### Step 1: Enable Subscriptions Feature

1. Login to [Razorpay Dashboard](https://dashboard.razorpay.com)
2. Go to **Settings** → **Product Configuration**
3. Enable **Subscriptions** feature
4. If not available, contact Razorpay support to enable it for your account

### Step 2: Configure Webhook

1. Go to **Settings** → **Webhooks**
2. Click **Create New Webhook**
3. Enter Webhook URL: `https://yourdomain.com/razorpay-subscription-webhook`
4. Select these events:
    - ✅ `payment.authorized`
    - ✅ `payment.captured`
    - ✅ `payment.failed`
    - ✅ `subscription.authenticated`
    - ✅ `subscription.activated`
    - ✅ `subscription.charged`
    - ✅ `subscription.pending`
    - ✅ `subscription.halted`
    - ✅ `subscription.cancelled`
    - ✅ `subscription.completed`
    - ✅ `qr_code.created`
    - ✅ `qr_code.paid`
    - ✅ `qr_code.closed`
5. Set **Alert Email** for webhook failures
6. Save and copy the **Webhook Secret**

### Step 3: Update Webhook Secret in Database

```sql
-- Update your payment_gateways table
UPDATE payment_gateways 
SET credentials = JSON_SET(
    credentials, 
    '$.webhook_secret', 
    'YOUR_WEBHOOK_SECRET_HERE'
)
WHERE name = 'razorpay';
```

Or update via your admin panel interface.

---

## 🧪 Testing Guide

### Test Environment Setup

```bash
# 1. Use Razorpay Test Mode
# Update your .env file
RAZORPAY_MODE=test
RAZORPAY_KEY_ID=rzp_test_xxxxxxxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxxxxxxxxxxxxxxxx
```

### Test Scenarios

#### 1. **Test Subscription Creation**

```php
// Test URL: /order-payment-form
// Expected: Creates payment log with is_recurring = 1
// Check logs: Should see "Using Razorpay subscription"
```

#### 2. **Test Webhook Reception**

Use Razorpay's webhook testing tool or cURL:

```bash
curl -X POST https://yourdomain.com/razorpay-subscription-webhook \
  -H "Content-Type: application/json" \
  -H "X-Razorpay-Signature: YOUR_SIGNATURE" \
  -d '{
    "event": "subscription.charged",
    "payload": {
      "subscription": {
        "entity": {
          "id": "sub_test123",
          "notes": {
            "tenant_id": "test-tenant",
            "payment_log_id": "1"
          }
        }
      },
      "payment": {
        "entity": {
          "id": "pay_test123",
          "amount": 10000
        }
      }
    }
  }'
```

#### 3. **Test Payment Failure Handling**

- Trigger failed payment webhook
- Check grace period is set on tenant
- Verify failure emails are sent

#### 4. **Test Subscription Cancellation**

```php
use App\Services\Payment\RazorpaySubscriptionService;

// In your controller or artisan command
$result = RazorpaySubscriptionService::cancelSubscription('sub_xxxxx', true);
dd($result);
```

---

## 📊 Model Updates

### Add Accessors to PaymentLogs Model

**File:** `app/Models/PaymentLogs.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentLogs extends Model
{
    protected $fillable = [
        // ... your existing fillable fields ...
        'razorpay_subscription_id',
        'razorpay_plan_id',
        'is_recurring',
        'subscription_status',
        'next_billing_date',
    ];

    protected $casts = [
        'is_recurring' => 'boolean',
        'next_billing_date' => 'datetime',
    ];

    /**
     * Check if this is a recurring subscription
     */
    public function isRecurring(): bool
    {
        return $this->is_recurring && !empty($this->razorpay_subscription_id);
    }

    /**
     * Check if subscription is active
     */
    public function isSubscriptionActive(): bool
    {
        return $this->subscription_status === 'active';
    }

    /**
     * Get the subscription status badge color
     */
    public function getSubscriptionStatusColorAttribute(): string
    {
        return match($this->subscription_status) {
            'active' => 'success',
            'pending' => 'warning',
            'paused' => 'info',
            'cancelled' => 'danger',
            'payment_failed' => 'danger',
            'expired' => 'secondary',
            default => 'secondary'
        };
    }
}
```

### Add Accessors to Tenant Model

**File:** `app/Models/Tenant.php`

```php
<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant
{
    protected $fillable = [
        // ... your existing fillable fields ...
        'razorpay_subscription_id',
        'recurring_enabled',
        'grace_period_end',
        'payment_grace_days',
        'renewal_payment_log_id',
    ];

    protected $casts = [
        'recurring_enabled' => 'boolean',
        'grace_period_end' => 'datetime',
    ];

    /**
     * Check if tenant is in grace period
     */
    public function isInGracePeriod(): bool
    {
        return $this->grace_period_end && $this->grace_period_end->isFuture();
    }

    /**
     * Get latest payment log
     */
    public function latestPaymentLog()
    {
        return $this->hasOne(PaymentLogs::class, 'tenant_id', 'id')
            ->where('payment_status', 'complete')
            ->latest();
    }

    /**
     * Get active subscription
     */
    public function activeSubscription()
    {
        return $this->hasOne(PaymentLogs::class, 'razorpay_subscription_id', 'razorpay_subscription_id')
            ->where('subscription_status', 'active')
            ->latest();
    }
}
```

---

## 🎨 Admin Panel Integration

### Enable/Disable Recurring Feature

Create an admin settings form:

```php
// In your admin settings controller
public function updateRazorpaySettings(Request $request)
{
    $request->validate([
        'razorpay_recurring_enabled' => 'required|boolean',
    ]);

    update_static_option('razorpay_recurring_enabled', $request->razorpay_recurring_enabled);

    return back()->with('success', 'Razorpay settings updated successfully');
}
```

**Blade Template:**

```blade
<div class="form-group">
    <label class="d-flex align-items-center">
        <input type="checkbox" 
               name="razorpay_recurring_enabled" 
               value="1"
               {{ get_static_option('razorpay_recurring_enabled') ? 'checked' : '' }}>
        <span class="ml-2">Enable Razorpay Recurring Subscriptions</span>
    </label>
    <small class="form-text text-muted">
        When enabled, customers will be automatically charged for renewals.
        Ensure Razorpay Subscriptions feature is enabled in your Razorpay Dashboard.
    </small>
</div>
```


---



## ⚙️ Configuration Options

### System Settings

Add these to your admin panel or database:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `razorpay_recurring_enabled` | boolean | `0` | Global recurring subscriptions toggle |
| `razorpay_webhook_secret` | string | `null` | Webhook signature verification secret |
| `payment_grace_days` | integer | `3` | Days of grace period after failed payment |
| `subscription_renewal_email` | boolean | `1` | Send email on successful renewal |
| `subscription_failure_email` | boolean | `1` | Send email on payment failure |

---

## 🚀 Deployment Checklist

Before deploying to production:

- [ ] Database migrations tested and backed up
- [ ] All new files added to version control
- [ ] Webhook URL is HTTPS (SSL certificate required)
- [ ] Webhook secret configured in Razorpay Dashboard
- [ ] Webhook secret added to database
- [ ] Route is excluded from CSRF protection
- [ ] Route is publicly accessible (no auth middleware)
- [ ] Razorpay Subscriptions feature enabled in account
- [ ] Test webhooks successfully received
- [ ] Payment failure handling tested
- [ ] Grace period logic tested
- [ ] Email notifications configured and tested
- [ ] Logs monitored for errors
- [ ] Rollback plan prepared

---

## 🐛 Troubleshooting

### Issue 1: Webhooks Not Received

**Symptoms:**
- No webhook logs in application
- Razorpay dashboard shows webhook failures

**Solutions:**
```bash
# Check route is accessible
curl -X POST https://yourdomain.com/razorpay-subscription-webhook

# Check CSRF exclusion
grep "razorpay-subscription-webhook" app/Http/Middleware/VerifyCsrfToken.php

# Check webhook URL in Razorpay Dashboard
# Ensure it matches your production URL exactly
```

### Issue 2: Signature Verification Failed

**Symptoms:**
- Log shows "Invalid Razorpay webhook signature"

**Solutions:**
```sql
-- Verify webhook secret in database
SELECT credentials FROM payment_gateways WHERE name = 'razorpay';

-- Should contain: {"api_key":"...", "api_secret":"...", "webhook_secret":"..."}
```

### Issue 3: Subscription Not Created

**Symptoms:**
- Payment log has `is_recurring = 0`
- No subscription ID in database

**Solutions:**
```php
// Check if recurring is enabled
dd(get_static_option('razorpay_recurring_enabled'));

// Check plan type (lifetime plans don't support recurring)
$plan = PricePlan::find($packageId);
dd($plan->type); // Should be 0 (monthly) or 1 (yearly), not 2 (lifetime)

// Check Razorpay Subscriptions feature
// Login to Razorpay Dashboard > Settings > Product Configuration
```

### Issue 4: Renewal Not Processing

**Symptoms:**
- Webhook received but tenant not updated
- No new payment log created

**Solutions:**
```bash
# Check webhook event type
grep "subscription.charged" storage/logs/laravel.log

# Check if payment log ID is in webhook data
# Should be in payload.subscription.entity.notes.payment_log_id

# Check database for subscription ID
SELECT * FROM payment_logs WHERE razorpay_subscription_id = 'sub_xxxxx';
```

### Issue 5: Email Notifications Not Sent

**Symptoms:**
- Renewals process but no emails sent

**Solutions:**
```php
// Test email configuration
php artisan tinker
Mail::raw('Test email', function($message) {
    $message->to('test@example.com')->subject('Test');
});

// Check mail logs
storage/logs/laravel.log
```

---



##  Support & Resources

### Official Documentation
- [Razorpay Subscriptions API](https://razorpay.com/docs/api/subscriptions/)
- [Razorpay Webhooks](https://razorpay.com/docs/webhooks/)
- [Laravel Multi-Tenancy](https://tenancyforlaravel.com/)


---

** Pro Tip:** Start with recurring disabled globally, test thoroughly with a few test tenants, then gradually enable for all new subscriptions.
Good luck with your implementation! 