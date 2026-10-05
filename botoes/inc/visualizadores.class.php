<?php

/**
 * -------------------------------------------------------------------------
 * Botoes Plugin for GLPI - Aba "Visualizadores" no chamado
 * -------------------------------------------------------------------------
 * Registra cada abertura do chamado (quem, quando) e mostra quem já viu, a primeira e a última vez e quantas
 * vezes. O criador conta desde a abertura do chamado.
 */
class PluginBotoesVisualizadores extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_botoes_visualizadores';

    public static function getTypeName($nb = 0): string
    {
        return 'Visualizadores';
    }

    public static function getIcon(): string
    {
        return 'ti ti-eye';
    }

    public static function canView(): bool
    {
        return Session::haveRight('ticket', READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Uma visualização por carregamento do chamado */
    public static function registrar(Ticket $ticket): void
    {
        global $DB;
        static $feito = [];

        $tid = (int) $ticket->getID();
        $uid = (int) Session::getLoginUserID();
        if ($tid <= 0 || $uid <= 0 || isset($feito[$tid]) || !$DB->tableExists(self::TABELA)) {
            return;
        }
        $feito[$tid] = true;
        $DB->insert(self::TABELA, ['tickets_id' => $tid, 'users_id' => $uid, 'view_date' => date('Y-m-d H:i:s')]);

        $criador = (int) ($ticket->fields['users_id_recipient'] ?? 0);
        if (
            $criador > 0
            && count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['tickets_id' => $tid, 'users_id' => $criador], 'LIMIT' => 1])) === 0
        ) {
            $DB->insert(self::TABELA, [
                'tickets_id' => $tid,
                'users_id'   => $criador,
                'view_date'  => $ticket->fields['date_creation'] ?? $ticket->fields['date'] ?? date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @return array<int, array{primeira: string, ultima: string, total: int}>
     */
    public static function listar(int $tickets_id): array
    {
        global $DB;

        $q = static fn (string $sql) => new \Glpi\DBAL\QueryExpression($sql);
        $lista = [];
        foreach ($DB->request([
            'SELECT'  => [
                'users_id',
                $q('MIN(' . $DB->quoteName('view_date') . ') AS ' . $DB->quoteName('primeira')),
                $q('MAX(' . $DB->quoteName('view_date') . ') AS ' . $DB->quoteName('ultima')),
                $q('COUNT(*) AS ' . $DB->quoteName('total')),
            ],
            'FROM'    => self::TABELA,
            'WHERE'   => ['tickets_id' => $tickets_id],
            'GROUPBY' => ['users_id'],
            'ORDER'   => ['primeira ASC'],
        ]) as $r) {
            $lista[(int) $r['users_id']] = ['primeira' => (string) $r['primeira'], 'ultima' => (string) $r['ultima'], 'total' => (int) $r['total']];
        }
        return $lista;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof Ticket || $item->isNewItem() || !plugin_botoes_opcao('visualizadores')) {
            return '';
        }
        self::registrar($item);
        return self::createTabEntry(self::getTypeName(), count(self::listar((int) $item->getID())), null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Ticket || !plugin_botoes_opcao('visualizadores')) {
            return true;
        }

        $lista   = self::listar((int) $item->getID());
        $criador = (int) ($item->fields['users_id_recipient'] ?? 0);
        $eu      = (int) Session::getLoginUserID();
        $pessoas = count($lista);
        $vezes   = array_sum(array_column($lista, 'total'));

        echo '<div class="botoes-visualizadores">';
        echo '<p class="botoes-vis-resumo"><i class="ti ti-info-circle" aria-hidden="true"></i> '
            . $pessoas . ' pessoa(s), ' . $vezes . ' visualização(ões) no total.</p>';

        if ($pessoas === 0) {
            echo '<p class="botoes-vis-vazio"><i class="ti ti-mood-empty" aria-hidden="true"></i> Ninguém abriu este chamado ainda.</p></div>';
            return true;
        }

        echo '<div class="table-responsive"><table class="table table-sm table-hover botoes-vis-tabela"><thead><tr>'
            . '<th>Usuário</th><th>Primeira visualização</th><th>Última</th><th class="text-end">Vezes</th>'
            . '</tr></thead><tbody>';
        foreach ($lista as $uid => $v) {
            $classe = $uid === $eu ? ' class="botoes-vis-eu"' : ($uid === $criador ? ' class="botoes-vis-criador"' : '');
            $nome   = getUserName($uid);
            $nome   = is_string($nome) && $nome !== '' ? $nome : 'Usuário #' . $uid;
            echo '<tr' . $classe . '><td>' . htmlescape($nome)
                . ($uid === $criador ? ' <span class="botoes-vis-selo botoes-vis-selo-criador">Criador</span>' : '')
                . ($uid === $eu ? ' <span class="botoes-vis-selo botoes-vis-selo-eu">Você</span>' : '')
                . '</td><td>' . htmlescape((string) Html::convDateTime($v['primeira'])) . '</td>'
                . '<td>' . htmlescape((string) Html::convDateTime($v['ultima'])) . '</td>'
                . '<td class="text-end">' . (int) $v['total'] . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
        return true;
    }
}
