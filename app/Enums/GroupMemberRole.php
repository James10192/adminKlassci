<?php

namespace App\Enums;

/**
 * Single source of truth for group_members.role values.
 *
 * Every downstream concern (dropdown options, badge colors, label
 * formatting, role-based alert matching, model helpers) derives from this
 * enum so adding a role is a one-file change instead of the 4-site edit
 * that the original string-based approach required.
 */
enum GroupMemberRole: string
{
    case Fondateur = 'fondateur';
    case DirecteurGeneral = 'directeur_general';
    case DirecteurGeneralAdjoint = 'directeur_general_adjoint';
    case DirecteurFinancier = 'directeur_financier';

    public function label(): string
    {
        return match ($this) {
            self::Fondateur => 'Fondateur',
            self::DirecteurGeneral => 'Directeur Général',
            self::DirecteurGeneralAdjoint => 'Directeur Général Adjoint',
            self::DirecteurFinancier => 'Directeur Financier',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Fondateur => 'success',
            self::DirecteurGeneral => 'primary',
            self::DirecteurGeneralAdjoint => 'info',
            self::DirecteurFinancier => 'warning',
        };
    }

    /**
     * True for roles that share full operational oversight — they receive
     * every AlertType by default. CFOs (DirecteurFinancier) are excluded:
     * the AlertRoleMatcher routes them to financial alerts only.
     */
    public function hasOperationalOversight(): bool
    {
        return match ($this) {
            self::Fondateur,
            self::DirecteurGeneral,
            self::DirecteurGeneralAdjoint => true,
            self::DirecteurFinancier => false,
        };
    }

    /**
     * @return array<string,string> [value => label] — ready for Filament
     *  Select options, config seeds, or API responses.
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
