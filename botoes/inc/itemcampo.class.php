<?php

/**
 * -------------------------------------------------------------------------
 * Botoes Plugin for GLPI - Valores dos Campos Adicionais por item
 * -------------------------------------------------------------------------
 * O motor de pesquisa associa a tabela glpi_plugin_botoes_itemcampos a esta classe
 * para montar as colunas Unidade / Setor / Telefone nas listas.
 */

class PluginBotoesItemcampo extends CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return 'Campos adicionais';
    }

    // $rightname não é redeclarada (tipada no GLPI 12, sem tipo no 11): permissões explícitas
    public static function canView(): bool
    {
        return Session::haveRight('ticket', READ)
            || Session::haveRight('problem', READ)
            || Session::haveRight('change', READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }
}
