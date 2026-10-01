<?php

namespace App\Domain\Care\Retours\Models;

use App\Domain\Care\Retours\Enums\AvisAssistant;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un 👍 / 👎 donné dans une école sur une réponse de Nanan, l'assistant IA.
 *
 * L'école garde son propre registre (elle s'en sert pour router la
 * conversation) ; ce modèle en est la copie que l'équipe lit, toutes écoles
 * confondues. La question et la réponse peuvent nommer un élève : elles ne
 * sortent jamais du panneau (ni Slack, ni API).
 */
class RetourAssistant extends Model
{
    protected $table = 'assistant_retours';

    protected $fillable = [
        'tenant_id',
        'cle',
        'avis',
        'raison',
        'commentaire',
        'question',
        'reponse',
        'modele',
        'page',
        'utilisateur_id_externe',
        'utilisateur_nom',
        'utilisateur_role',
        'conversation_ref',
        'message_ref',
        'donne_le',
    ];

    protected $hidden = ['cle'];

    protected function casts(): array
    {
        return [
            'avis' => AvisAssistant::class,
            'donne_le' => 'datetime',
            'traite_le' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traite_par');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function scopeNonTraites(Builder $q): Builder
    {
        return $q->whereNull('traite_le');
    }

    public function estTraite(): bool
    {
        return $this->traite_le !== null;
    }

    /** Libellé d'une raison envoyée par l'école ; une raison inconnue reste lisible telle quelle. */
    public function raisonLibelle(): ?string
    {
        if ($this->raison === null || $this->raison === '') {
            return null;
        }

        return config('care.retours_assistant.raisons.'.$this->raison, $this->raison);
    }
}
