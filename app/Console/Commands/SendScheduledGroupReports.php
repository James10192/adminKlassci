<?php

namespace App\Console\Commands;

use App\Mail\Group\ScheduledReportMail;
use App\Models\GroupReportSchedule;
use App\Services\Export\ReportRenderer;
use App\Services\Group\ReportRegistry;
use App\Services\Group\ScheduleDueResolver;
use App\Support\Period\PeriodFactory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendScheduledGroupReports extends Command
{
    protected $signature = 'group:send-scheduled-reports
        {--dry-run : Montre ce qui partirait, sans envoyer ni horodater}
        {--schedule= : Ne traiter qu\'une programmation, par son identifiant}';

    protected $description = 'Envoie les etats du portail groupe dont la programmation est echue';

    public function handle(
        ScheduleDueResolver $resolver,
        ReportRegistry $registry,
        ReportRenderer $renderer,
    ): int {
        $maintenant = CarbonImmutable::now();
        $simulation = (bool) $this->option('dry-run');

        $requete = GroupReportSchedule::query()->actives()->with('group');

        if ($id = $this->option('schedule')) {
            $requete->whereKey($id);
        }

        $programmations = $requete->get();

        $envoyes = 0;
        $echecs = 0;
        $ignores = 0;

        foreach ($programmations as $programmation) {
            if (! $programmation->estDue($resolver, $maintenant)) {
                $ignores++;
                continue;
            }

            // Un groupe supprimé laisse une programmation orpheline : on la
            // saute sans faire tomber les autres.
            if ($programmation->group === null) {
                $ignores++;
                continue;
            }

            try {
                $this->envoyer($programmation, $registry, $renderer, $simulation, $maintenant);
                $envoyes++;
            } catch (\Throwable $e) {
                $echecs++;

                Log::error("[group-reports] programmation #{$programmation->id} en echec : {$e->getMessage()}");

                if (! $simulation) {
                    // On garde la trace SANS horodater l'envoi : la
                    // programmation reste due et sera retentee au passage
                    // suivant, au lieu d'etre perdue jusqu'a la periode
                    // d'apres.
                    $programmation->forceFill([
                        'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    ])->save();
                }

                $this->error("  #{$programmation->id} : {$e->getMessage()}");
            }
        }

        $this->info("Programmations : {$envoyes} envoyee(s), {$echecs} en echec, {$ignores} non echue(s).");

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function envoyer(
        GroupReportSchedule $programmation,
        ReportRegistry $registry,
        ReportRenderer $renderer,
        bool $simulation,
        CarbonImmutable $maintenant,
    ): void {
        $destinataires = $programmation->destinataires();

        if ($destinataires->isEmpty()) {
            // Personne a qui envoyer : on le dit plutot que de compter un
            // envoi reussi. Le cas arrive quand les membres vises ont ete
            // desactives depuis la creation de la programmation.
            throw new \RuntimeException('Aucun destinataire actif avec adresse e-mail.');
        }

        $report = $registry->construire($programmation->report_key, $programmation->group);

        // Rendu UNE fois pour tous les destinataires : la consolidation
        // traverse toutes les bases etablissement.
        $pdf = $renderer->pdfBytes($report);
        $nomFichier = $renderer->filename($report, 'pdf');

        $cadence = $programmation->frequency === ScheduleDueResolver::MENSUEL ? 'mensuel' : 'hebdomadaire';

        // La periode annoncee dans le corps du message se lit DANS le document
        // joint, elle ne se recalcule pas a cote. Les deux etaient
        // independants tant que tous les etats couvraient l'annee ; depuis que
        // les etats de detail se cadrent sur le mois, un `PeriodFactory::default()`
        // pose ici annoncerait « Année 2026 » au-dessus d'une piece jointe qui
        // ne porte que septembre. Un message qui ment sur sa propre piece
        // jointe est pire qu'un message sans periode.
        $periode = $report->filters()['Période'] ?? PeriodFactory::default()->label();

        $this->line("  #{$programmation->id} {$registry->libelle($programmation->report_key)} → {$destinataires->count()} destinataire(s)");

        if ($simulation) {
            return;
        }

        foreach ($destinataires as $membre) {
            Mail::to($membre->email)->queue(new ScheduledReportMail(
                $programmation->group,
                $membre,
                $report->title(),
                $nomFichier,
                $pdf,
                $periode,
                $cadence,
            ));
        }

        $programmation->forceFill([
            'last_sent_at' => $maintenant,
            'last_error' => null,
        ])->save();
    }
}
