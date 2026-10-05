<?php

/**
 * -------------------------------------------------------------------------
 * Botoes Plugin for GLPI - Hooks
 * -------------------------------------------------------------------------
 */

function plugin_botoes_install()
{
    global $DB;

    // Primeira instalação (ou atualização a partir de versão sem configuração):
    // libera para todos os perfis da interface padrão, mantendo o comportamento anterior.
    $current = Config::getConfigurationValues(PLUGIN_BOTOES_CONFIG_CONTEXT, ['profiles']);
    if (!array_key_exists('profiles', $current)) {
        $ids = [];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles', 'WHERE' => ['interface' => 'central']]) as $row) {
            $ids[] = (int) $row['id'];
        }
        plugin_botoes_set_allowed_profiles($ids);
    }

    plugin_botoes_criar_tabelas();

    // Campos Adicionais começam desativados (ativação na tela de configuração)
    $padroes = [];
    foreach (array_keys(PluginBotoesCampos::CAMPOS) as $campo) {
        $padroes["campo_{$campo}"]       = '0';
        $padroes["campo_{$campo}_obrig"] = '1';
    }
    // Aviso público/privado e aba Visualizadores começam ligados
    $padroes += plugin_botoes_opcoes_padrao();
    $existentes = Config::getConfigurationValues(PLUGIN_BOTOES_CONFIG_CONTEXT, array_keys($padroes));
    $faltantes  = array_diff_key($padroes, $existentes);
    if (count($faltantes) > 0) {
        Config::setConfigurationValues(PLUGIN_BOTOES_CONFIG_CONTEXT, $faltantes);
    }

    return true;
}

/**
 * Tabelas dos Campos Adicionais (idempotente: seguro em instalação e atualização).
 */
function plugin_botoes_criar_tabelas(): void
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // Dados do cliente: unidades, setores e telefones cadastrados por entidade
    if (!$DB->tableExists(PluginBotoesClientedado::TABELA)) {
        $DB->doQuery("CREATE TABLE `" . PluginBotoesClientedado::TABELA . "` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `tipo` varchar(20) NOT NULL DEFAULT 'unidade',
            `entities_id` int {$sign} NOT NULL DEFAULT 0,
            `nome` varchar(255) NOT NULL DEFAULT '',
            `users_id` int {$sign} NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tipo_entidade_nome` (`tipo`, `entities_id`, `nome`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // Valores gravados em cada Chamado / Problema / Mudança
    if (!$DB->tableExists(PluginBotoesCampos::TABELA)) {
        $DB->doQuery("CREATE TABLE `" . PluginBotoesCampos::TABELA . "` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int {$sign} NOT NULL DEFAULT 0,
            `unidade_id` int {$sign} NOT NULL DEFAULT 0,
            `unidade` varchar(255) NOT NULL DEFAULT '',
            `setor` varchar(255) NOT NULL DEFAULT '',
            `telefone` varchar(20) NOT NULL DEFAULT '',
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `item` (`itemtype`, `items_id`),
            KEY `unidade` (`unidade`),
            KEY `setor` (`setor`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // Visualizadores do chamado: uma linha por abertura
    if (!$DB->tableExists(PluginBotoesVisualizadores::TABELA)) {
        $DB->doQuery("CREATE TABLE `" . PluginBotoesVisualizadores::TABELA . "` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `tickets_id` int {$sign} NOT NULL DEFAULT 0,
            `users_id` int {$sign} NOT NULL DEFAULT 0,
            `view_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `users_id` (`users_id`),
            KEY `ticket_user_date` (`tickets_id`, `users_id`, `view_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");

        // Cópia única do histórico que o plugin Botões Adicionais tinha (a tabela dele não é alterada)
        $origem = 'glpi_plugin_botoesadicionais_visualizadores';
        if ($DB->tableExists($origem)) {
            foreach ($DB->request(['SELECT' => ['tickets_id', 'users_id', 'view_date'], 'FROM' => $origem, 'ORDER' => 'id ASC']) as $r) {
                $DB->insert(PluginBotoesVisualizadores::TABELA, [
                    'tickets_id' => (int) $r['tickets_id'],
                    'users_id'   => (int) $r['users_id'],
                    'view_date'  => $r['view_date'],
                ]);
            }
        }
    }
}

/**
 * Desinstalar mantém tabelas e configuração: dados do cliente, campos dos chamados, visualizadores e perfis
 * voltam como estavam ao reinstalar.
 */
function plugin_botoes_uninstall()
{
    return true;
}

/**
 * Colunas e filtros Unidade / Setor / Telefone nas listas de Chamado, Problema e Mudança.
 */
function plugin_botoes_getAddSearchOptionsNew($itemtype)
{
    if (!in_array($itemtype, plugin_botoes_supported_itemtypes(), true)) {
        return [];
    }

    // IDs repetidos em public/js/campos.js (ajuste do título das colunas)
    $ids    = ['unidade' => 15101, 'setor' => 15102, 'telefone' => 15103];
    $config = PluginBotoesCampos::getConfig();
    $opcoes = [];

    foreach (PluginBotoesCampos::CAMPOS as $campo => $rotulo) {
        if (!$config[$campo]['ativo']) {
            continue;
        }
        $opcoes[] = [
            'id'            => $ids[$campo],
            'table'         => PluginBotoesCampos::TABELA,
            'field'         => $campo,
            'name'          => $rotulo,
            'datatype'      => 'string',
            'massiveaction' => false,
            'joinparams'    => ['jointype' => 'itemtype_item'],
        ];
    }

    return $opcoes;
}

/**
 * Renderiza os três botões de ação rápida na barra inferior da timeline
 * de Chamados, Problemas e Mudanças.
 *
 * @param array $params
 */
function plugin_botoes_timeline_actions($params)
{
    $item = $params['item'] ?? null;
    if (
        !($item instanceof CommonITILObject)
        || !in_array($item::class, plugin_botoes_supported_itemtypes(), true)
        || $item->isNewItem()
    ) {
        return;
    }

    if (!plugin_botoes_current_profile_allowed()) {
        return;
    }

    if (!$item->canUpdateItem() && !$item->canAssignToMe()) {
        return;
    }

    $id       = (int) $item->getID();
    $itemtype = htmlescape($item::class);

    echo <<<HTML
<li class="botoes-actions">
    <button type="button" class="btn btn-success botoes-btn" data-botoes-action="accept" data-itemtype="{$itemtype}" data-items-id="{$id}" title="Atribuir a mim e iniciar atendimento">
        <i class="ti ti-user-check" aria-hidden="true"></i><span>Aceitar</span>
    </button>
    <button type="button" class="btn btn-warning botoes-btn" data-botoes-action="pending" data-itemtype="{$itemtype}" data-items-id="{$id}" title="Colocar em status Pendente">
        <i class="ti ti-clock-pause" aria-hidden="true"></i><span>Pendente</span>
    </button>
    <button type="button" class="btn btn-primary botoes-btn" data-botoes-action="observer" data-itemtype="{$itemtype}" data-items-id="{$id}" title="Adicionar ou substituir grupo observador">
        <i class="ti ti-users-group" aria-hidden="true"></i><span>Grupo Observador</span>
    </button>
</li>
HTML;
}
