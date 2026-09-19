<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'scheduled', 'lock_version' => 1, 'schedule_version' => 1];

    public const LABELS = ['scheduled' => 'Agendado', 'waiting' => 'Aguardando', 'in_progress' => 'Em atendimento', 'completed' => 'Concluído', 'no_show' => 'Não compareceu', 'cancelled' => 'Cancelado'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'arrived_at' => 'immutable_datetime', 'called_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'lock_version' => 'integer', 'schedule_version' => 'integer'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status];
    }
}
