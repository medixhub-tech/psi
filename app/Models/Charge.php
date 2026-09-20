<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Charge extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_on' => 'immutable_date', 'lock_version' => 'integer'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function netCents(): int
    {
        $paid = DB::table('payments')->where('charge_id', $this->id)->sum('amount');
        $reversed = DB::table('payment_reversals')->join('payments', 'payments.id', '=', 'payment_reversals.payment_id')->where('payments.charge_id', $this->id)->sum('payment_reversals.amount');

        return Money::cents((string) $paid) - Money::cents((string) $reversed);
    }

    public function balanceCents(): ?int
    {
        return $this->amount === null ? null : ($this->status === 'open' ? Money::cents($this->amount) - $this->netCents() : 0);
    }

    public function label(): string
    {
        if ($this->status === 'waived') {
            return 'Dispensada';
        } if ($this->status === 'void') {
            return 'Anulada';
        }
        if ($this->amount === null) {
            return 'Valor a definir';
        }
        $net = $this->netCents();

        return $net === Money::cents($this->amount) ? 'Quitada' : ($net > 0 ? 'Parcial' : 'Pendente');
    }
}
