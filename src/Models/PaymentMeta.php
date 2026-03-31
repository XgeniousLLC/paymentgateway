<?php

namespace Xgenious\Paymentgateway\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMeta extends Model
{
    use HasFactory;
    protected $table = "xg_payment_meta";
    protected $fillable = [
        "gateway",
        "order_id",
        "amount",
        "meta_data",
        "session_id",
        "type",
        "track",
    ];
}
