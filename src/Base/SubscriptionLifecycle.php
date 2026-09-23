<?php
namespace Xgenious\Paymentgateway\Base;

interface SubscriptionLifecycle {
    public function cancel_subscription($subscription_id, $at_period_end = true);
    public function pause_subscription($subscription_id);
    public function resume_subscription($subscription_id);
    public function fetch_subscription($subscription_id);
}
