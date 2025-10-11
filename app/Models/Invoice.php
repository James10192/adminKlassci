<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'period_start',
        'period_end',
        'subtotal',
        'tax_amount',
        'total_amount',
        'amount_paid',
        'status',
        'payment_method',
        'payment_reference',
        'paid_at',
        'line_items',
        'notes',
        'terms',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'paid_at' => 'datetime',
        'line_items' => 'array',
    ];

    /**
     * Relations
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scopes
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue')
                     ->orWhere(function($q) {
                         $q->where('status', 'sent')
                           ->where('due_date', '<', now());
                     });
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    /**
     * Accessors
     */
    public function getBalanceDueAttribute(): float
    {
        return max(0, $this->total_amount - $this->amount_paid);
    }

    public function getIsFullyPaidAttribute(): bool
    {
        return $this->amount_paid >= $this->total_amount;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status !== 'paid' && $this->due_date->isPast();
    }

    /**
     * Helper methods
     */
    public function markAsPaid(float $amount, string $method, ?string $reference = null): bool
    {
        return $this->update([
            'amount_paid' => $amount,
            'payment_method' => $method,
            'payment_reference' => $reference,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    public function send(): bool
    {
        return $this->update(['status' => 'sent']);
    }

    public function cancel(): bool
    {
        return $this->update(['status' => 'cancelled']);
    }
}
