<?php

namespace App\Domain\Cli;

use RuntimeException;

final class LectureSqlFermee extends RuntimeException
{
    public function __construct(?string $raison = null)
    {
        parent::__construct(
            'La lecture SQL est fermée : ' . ($raison ?? "aucun utilisateur MySQL en lecture seule n'est configuré.") . ' '
            . 'Créez dans cPanel un utilisateur qui n\'a que SELECT sur la base maître, sans les colonnes d\'identifiants, '
            . 'puis renseignez DB_LECTURE_USERNAME / DB_LECTURE_PASSWORD et CLI_SQL_CONNEXION=lecture.'
        );
    }
}
