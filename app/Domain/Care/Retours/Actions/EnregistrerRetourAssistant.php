<?php

namespace App\Domain\Care\Retours\Actions;

use App\Domain\Care\Notifications\AnnonceSlack;
use App\Domain\Care\Retours\DTO\ResultatRetour;
use App\Domain\Care\Retours\Enums\AvisAssistant;
use App\Domain\Care\Retours\Models\RetourAssistant;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre un 👍 / 👎 transmis par une école.
 *
 * Deux idempotences, pour deux raisons :
 *  - par (instance, clé) : un réseau mobile qui renvoie le même envoi retrouve
 *    la ligne déjà écrite et n'écrit rien ;
 *  - par (instance, message, personne) : l'école garde UN avis par personne et
 *    par réponse (elle fait un updateOrCreate). Une personne qui passe de 👍 à
 *    👎 envoie une nouvelle clé (`care_uuid:N`) ; on met la ligne à jour au
 *    lieu d'en ajouter une seconde, sinon le taux de satisfaction compterait
 *    deux fois la même réponse. La version la plus récente selon `donne_le`
 *    l'emporte, quel que soit l'ordre d'arrivée.
 *
 * Un avis qui change rouvre le retour : ce qui avait été traité pour un 👍
 * ne l'a pas été pour le 👎 qui le remplace.
 */
class EnregistrerRetourAssistant
{
    public function __construct(private readonly AnnonceSlack $slack)
    {
    }

    /** @param array<string, mixed> $donnees validées par EnregistrerRetourAssistantRequest */
    public function executer(Tenant $tenant, array $donnees, string $cle): ResultatRetour
    {
        if ($existant = $this->parCle($tenant, $cle)) {
            return new ResultatRetour($existant, true);
        }

        try {
            [$retour, $aAnnoncer, $ecrit] = DB::transaction(fn () => $this->ecrire($tenant, $donnees, $cle), 3);
        } catch (UniqueConstraintViolationException) {
            // Un envoi concurrent a gagné la course : sur la même clé, c'est un rejeu ;
            // sur le même message, la ligne existe maintenant et l'on met à jour.
            if ($existant = $this->parCle($tenant, $cle)) {
                return new ResultatRetour($existant, true);
            }
            [$retour, $aAnnoncer, $ecrit] = DB::transaction(fn () => $this->ecrire($tenant, $donnees, $cle), 3);
        }

        if ($aAnnoncer) {
            $this->slack->programmerRetour($retour);
        }

        // Une version plus ancienne que celle déjà reçue n'écrit rien : pour
        // l'école, c'est un rejeu (elle n'a rien à renvoyer).
        return new ResultatRetour($retour, ! $ecrit);
    }

    private function parCle(Tenant $tenant, string $cle): ?RetourAssistant
    {
        return RetourAssistant::where('tenant_id', $tenant->id)->where('cle', $cle)->first();
    }

    /** @return array{0: RetourAssistant, 1: bool, 2: bool} le retour, s'il mérite une annonce, s'il a été écrit */
    private function ecrire(Tenant $tenant, array $d, string $cle): array
    {
        $attributs = [
            'cle' => $cle,
            'avis' => $d['avis'],
            'raison' => $d['raison'] ?? null,
            'commentaire' => isset($d['commentaire']) ? (trim((string) $d['commentaire']) ?: null) : null,
            'question' => (string) $d['question'],
            'reponse' => (string) $d['reponse'],
            'modele' => $d['modele'] ?? null,
            'page' => $d['page'] ?? null,
            'utilisateur_nom' => (string) $d['utilisateur']['nom'],
            'utilisateur_role' => $d['utilisateur']['role'] ?? null,
            'conversation_ref' => isset($d['conversation_ref']) ? (string) $d['conversation_ref'] : null,
            // Dans le fuseau de l'application : Eloquent écrit le Carbon tel quel,
            // sans conversion. Une école à UTC+1 (ucao-benin) enverrait sinon une
            // heure de travers, et une v2 passerait pour plus ancienne que la v1.
            'donne_le' => Carbon::parse($d['donne_le'])->setTimezone(config('app.timezone')),
        ];

        $retour = RetourAssistant::where('tenant_id', $tenant->id)
            ->where('message_ref', (string) $d['message_ref'])
            ->where('utilisateur_id_externe', (int) $d['utilisateur']['id'])
            ->lockForUpdate()
            ->first();

        if ($retour === null) {
            $retour = new RetourAssistant($attributs + [
                'tenant_id' => $tenant->id,
                'message_ref' => (string) $d['message_ref'],
                'utilisateur_id_externe' => (int) $d['utilisateur']['id'],
            ]);
            $retour->save();

            return [$retour, true, true];
        }

        // L'école versionne ses envois (`care_uuid`, puis `care_uuid:N`) ; le
        // réseau peut les livrer dans le désordre. La plus récente selon
        // donne_le l'emporte, une plus ancienne ne réécrit rien.
        if ($attributs['donne_le']->lt($retour->donne_le)) {
            return [$retour, false, false];
        }

        $avisChange = $retour->avis !== AvisAssistant::from($d['avis']);
        $commentaireNouveau = filled($attributs['commentaire']) && $attributs['commentaire'] !== $retour->commentaire;
        $retour->fill($attributs);
        if ($avisChange) {
            $retour->forceFill(['traite_le' => null, 'traite_par' => null]);
        }
        $retour->save();

        return [$retour, $avisChange || $commentaireNouveau, true];
    }
}
