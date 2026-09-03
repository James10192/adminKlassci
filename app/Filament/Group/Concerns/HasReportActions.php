<?php

namespace App\Filament\Group\Concerns;

use App\Domain\Exports\ExportableReport;
use App\Services\Export\ReportRenderer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pose les boutons d'export d'un écran du portail.
 *
 * Le rapport est construit par une fabrique appelée au clic, pas au rendu de
 * la page : consolider tous les établissements pour un bouton qu'on ne
 * cliquera peut-être pas coûterait une traversée de toutes les bases à chaque
 * affichage.
 */
trait HasReportActions
{
    /**
     * @param  callable(): ExportableReport  $fabrique
     */
    protected function actionsRapport(string $cle, string $libelle, callable $fabrique, string $icone = 'heroicon-o-document-arrow-down'): ActionGroup
    {
        return ActionGroup::make([
            Action::make($cle . '_pdf')
                ->label('PDF')
                ->icon('heroicon-o-document-text')
                ->action(fn () => $this->telecharger($fabrique, 'pdf')),

            Action::make($cle . '_excel')
                ->label('Excel')
                ->icon('heroicon-o-table-cells')
                ->action(fn () => $this->telecharger($fabrique, 'xlsx')),
        ])
            ->label($libelle)
            ->icon($icone)
            ->button()
            ->color('gray');
    }

    /**
     * @param  callable(): ExportableReport  $fabrique
     */
    private function telecharger(callable $fabrique, string $format)
    {
        $renderer = app(ReportRenderer::class);

        try {
            $report = $fabrique();

            if ($format === 'xlsx') {
                return $renderer->excelDownload($report);
            }

            $octets = $renderer->pdfBytes($report);
            $nom = $renderer->filename($report, 'pdf');

            return response()->streamDownload(fn () => print($octets), $nom);
        } catch (HttpException $e) {
            // Le refus de volume porte déjà un message qui dit quoi faire ;
            // on le montre tel quel plutôt que de laisser passer une page
            // d'erreur au directeur.
            Notification::make()
                ->title('Export impossible')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return null;
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Export impossible')
                ->body('Le document n\'a pas pu être produit. L\'incident a été enregistré.')
                ->danger()
                ->send();

            return null;
        }
    }
}
