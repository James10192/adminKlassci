<?php

namespace App\Filament\Group\Resources\ReportScheduleResource\Pages;

use App\Filament\Group\Concerns\HasCustomHero;
use App\Filament\Group\Resources\ReportScheduleResource;
use App\Models\GroupReportSchedule;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListReportSchedules extends ListRecords
{
    use HasCustomHero;

    protected static string $resource = ReportScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Programmer un envoi'),
        ];
    }

    /**
     * Le bandeau du groupe, comme sur les sept autres écrans.
     *
     * Cette page était la seule à ouvrir sur le titre Filament brut au-dessus
     * d'un tableau, sur fond blanc — un écran manifestement inachevé collé à
     * un produit fini.
     */
    public function getHeader(): ?View
    {
        return view('filament.group.partials.rapports-hero', [
            'context' => $this->buildHeroContext(),
        ]);
    }

    /**
     * Ce que comptent ces trois cartouches.
     *
     * Rien ici ne mesure un établissement : tout est lu dans `klassci_master`,
     * qui répond toujours. Ces chiffres ne peuvent donc jamais être « non
     * mesurés » — mais ils peuvent être à zéro, et le hero le dit alors en
     * gris plutôt qu'en affichant un `0` qu'on lirait comme un échec.
     *
     * Les destinataires sont dédupliqués et comptés sur les seules
     * programmations ACTIVES : un membre nommé dans trois envois est une
     * personne, et un envoi suspendu n'écrit à personne.
     *
     * @return array{total: int, actifs: int, destinataires: int, dernier_envoi: ?string}
     */
    private function buildHeroContext(): array
    {
        $groupId = auth('group')->user()?->group_id;

        if ($groupId === null) {
            return ['total' => 0, 'actifs' => 0, 'destinataires' => 0, 'dernier_envoi' => null];
        }

        $programmations = GroupReportSchedule::query()
            ->where('group_id', $groupId)
            ->get(['is_active', 'recipient_member_ids', 'last_sent_at']);

        $actives = $programmations->where('is_active', true);

        $destinataires = $actives
            ->flatMap(fn (GroupReportSchedule $p): array => is_array($p->recipient_member_ids)
                ? $p->recipient_member_ids
                : [])
            ->unique()
            ->count();

        $dernier = $programmations
            ->pluck('last_sent_at')
            ->filter()
            ->max();

        return [
            'total' => $programmations->count(),
            'actifs' => $actives->count(),
            'destinataires' => $destinataires,
            'dernier_envoi' => $dernier?->locale('fr')->diffForHumans(),
        ];
    }
}
