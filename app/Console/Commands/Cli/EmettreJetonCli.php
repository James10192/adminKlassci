<?php

namespace App\Console\Commands\Cli;

use App\Domain\Cli\CapacitesCli;
use App\Domain\Cli\JournalCli;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Émet le jeton CLI d'un membre de l'équipe (klassci admin:*).
 *
 * Un jeton par personne : les actions du CLI sont journalisées au nom de
 * celui qui les lance. Un jeton partagé rendait impossible de savoir qui avait
 * déployé quoi.
 */
class EmettreJetonCli extends Command
{
    protected $signature = 'cli:jeton
                            {email : Adresse du membre de l\'équipe}
                            {--nom=poste : Nom du poste, pour retrouver le jeton (ex. portable-aicha)}
                            {--jours=180 : Durée de validité du jeton, en jours}
                            {--revoquer : Révoque tous les jetons CLI du membre au lieu d\'en émettre un}';

    protected $description = 'Émettre (ou révoquer) le jeton CLI d\'un membre de l\'équipe KLASSCI';

    public function handle(): int
    {
        $membre = User::where('email', $this->argument('email'))->first();

        if (! $membre) {
            $this->error('Aucun membre de l\'équipe avec cette adresse.');
            return self::FAILURE;
        }

        if ($this->option('revoquer')) {
            $nombre = $membre->tokens()->where('name', 'like', 'cli:%')->delete();
            JournalCli::consigner('cli_token_revoked', "{$nombre} jeton(s) CLI révoqué(s)", membre: $membre);
            $this->info("{$nombre} jeton(s) CLI révoqué(s) pour {$membre->name}.");
            return self::SUCCESS;
        }

        $capacites = CapacitesCli::duRole($membre->role);

        if (! $membre->is_active || $capacites === []) {
            $this->error("{$membre->name} est inactif ou son rôle ({$membre->role}) n'ouvre aucun droit au CLI.");
            return self::FAILURE;
        }

        // Un poste perdu ou un départ ne doit pas laisser un accès ouvert pour toujours.
        $expireLe = now()->addDays(max(1, (int) $this->option('jours')));
        $nom = 'cli:' . $this->option('nom');
        $jeton = $membre->createToken($nom, $capacites, $expireLe);

        JournalCli::consigner('cli_token_issued', "Jeton CLI « {$nom} » émis", [
            'capacites' => $capacites,
            'expire_le' => $expireLe->toDateString(),
        ], membre: $membre);

        $this->info("Jeton CLI pour {$membre->name} ({$membre->role}) : " . implode(', ', $capacites));
        $this->line('');
        $this->line($jeton->plainTextToken);
        $this->line('');
        $this->comment('Affiché une seule fois. Sur le poste du membre :');
        $this->comment('  klassci admin:config ' . rtrim((string) config('app.url'), '/') . ' <ce jeton>');

        return self::SUCCESS;
    }
}
