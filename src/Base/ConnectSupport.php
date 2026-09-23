<?php
namespace Xgenious\Paymentgateway\Base;

interface ConnectSupport {
    public function setDestinationAccountId($account_id);
    public function setApplicationFeeAmount($amount);
    public function create_connect_account(array $args);
    public function create_account_onboarding_link($account_id, array $args = []);
}
