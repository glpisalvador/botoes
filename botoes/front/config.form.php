<?php

/**
 * -------------------------------------------------------------------------
 * Botoes Plugin for GLPI - Tela de configuração
 * -------------------------------------------------------------------------
 */

global $CFG_GLPI;

Session::checkRight('config', UPDATE);

$form_url = $CFG_GLPI['root_doc'] . '/plugins/botoes/front/config.form.php';

if (isset($_POST['update'])) {
    // O campo múltiplo envia um hidden vazio + os valores selecionados
    $selected = $_POST['profiles'] ?? [];
    plugin_botoes_set_allowed_profiles(is_array($selected) ? $selected : []);

    PluginBotoesCampos::salvarConfig(is_array($_POST['campos'] ?? null) ? $_POST['campos'] : []);

    $opcoes = is_array($_POST['opcoes'] ?? null) ? $_POST['opcoes'] : [];
    $valores = [];
    foreach (array_keys(plugin_botoes_opcoes_padrao()) as $nome) {
        $valores[$nome] = !empty($opcoes[$nome]) ? '1' : '0';
    }
    Config::setConfigurationValues(PLUGIN_BOTOES_CONFIG_CONTEXT, $valores);

    Session::addMessageAfterRedirect(htmlescape('Configuração do plugin Botões salva.'), false, INFO);
    Html::redirect($form_url);
}

Html::header('Botões', $form_url, 'config', 'plugins');

$dropdown = Profile::dropdown([
    'name'     => 'profiles[]',
    'multiple' => true,
    'values'   => plugin_botoes_get_allowed_profiles(),
    'width'    => '100%',
    'display'  => false,
]);

// Linhas da tabela de Campos Adicionais
$descricoes = [
    'unidade'  => 'Lista das unidades cadastradas nos Dados do Cliente da entidade. Sem unidades cadastradas o campo fica sem opções e não é exigido.',
    'setor'    => 'Lista dos setores cadastrados nos Dados do Cliente da entidade. Sem cadastro, vira texto livre em maiúsculas.',
    'telefone' => 'Lista dos telefones cadastrados nos Dados do Cliente da entidade. Sem cadastro, vira texto livre com DDD (10 ou 11 dígitos).',
];
$linhas_campos = '';
foreach (PluginBotoesCampos::getConfig() as $campo => $cfg) {
    $rotulo    = htmlescape(PluginBotoesCampos::CAMPOS[$campo]);
    $descricao = htmlescape($descricoes[$campo]);
    $ativo     = $cfg['ativo'] ? ' checked' : '';
    $obrig     = $cfg['obrigatorio'] ? ' checked' : '';
    $linhas_campos .= <<<HTML
        <tr>
            <td>
                <div class="fw-semibold">{$rotulo}</div>
                <div class="text-muted small">{$descricao}</div>
            </td>
            <td class="text-center align-middle">
                <div class="form-check form-switch d-inline-block m-0">
                    <input class="form-check-input" type="checkbox" name="campos[{$campo}][ativo]" value="1"{$ativo} aria-label="{$rotulo} ativo">
                </div>
            </td>
            <td class="text-center align-middle">
                <input class="form-check-input" type="checkbox" name="campos[{$campo}][obrigatorio]" value="1"{$obrig} aria-label="{$rotulo} obrigatório">
            </td>
        </tr>
HTML;
}

$entidade_dropdown = Entity::dropdown([
    'name'    => 'botoes_entidade',
    'value'   => (int) ($_SESSION['glpiactive_entity'] ?? 0),
    'entity'  => $_SESSION['glpiactiveentities'] ?? [],
    'width'   => '100%',
    'display' => false,
]);

// Opções do chamado: aviso público/privado e aba Visualizadores
$descricoes_opcoes = [
    'privacidade'    => ['Perguntar se o acompanhamento é público ou privado', 'Ao enviar um acompanhamento novo, abre um aviso para escolher: Público (o requerente vê) ou Privado (só a equipe técnica). Aparece só para quem pode marcar acompanhamentos como privados.'],
    'visualizadores' => ['Aba "Visualizadores" no chamado', 'Registra quem abriu cada chamado, quando foi a primeira e a última vez e quantas vezes. O criador conta desde a abertura.'],
];
$linhas_opcoes = '';
foreach ($descricoes_opcoes as $nome => [$rotulo_op, $descricao_op]) {
    $marcado = plugin_botoes_opcao($nome) ? ' checked' : '';
    $rotulo_op = htmlescape($rotulo_op);
    $descricao_op = htmlescape($descricao_op);
    $linhas_opcoes .= <<<HTML
        <div class="form-check form-switch botoes-opcao">
            <input class="form-check-input" type="checkbox" name="opcoes[{$nome}]" value="1" id="botoes-opcao-{$nome}"{$marcado}>
            <label class="form-check-label" for="botoes-opcao-{$nome}">{$rotulo_op}</label>
            <div class="text-muted small">{$descricao_op}</div>
        </div>
HTML;
}

$action   = htmlescape($form_url);
$ajax_url = htmlescape($CFG_GLPI['root_doc'] . '/plugins/botoes/ajax/clientedados.php');
$csrf     = plugin_botoes_campo_csrf();

echo <<<HTML
<div class="container-lg my-3 botoes-config">
    <form method="post" action="{$action}">
        {$csrf}
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="ti ti-bolt me-2" aria-hidden="true"></i>Botões &mdash; Perfis
                </h3>
            </div>
            <div class="card-body">
                <label class="form-label" for="botoes-profiles">Perfis que podem ver e usar os botões</label>
                <div id="botoes-profiles">{$dropdown}</div>
                <div class="form-hint mt-2">
                    Os botões <strong>Aceitar</strong>, <strong>Pendente</strong> e <strong>Grupo Observador</strong>
                    aparecem em Chamados, Problemas e Mudanças somente para os perfis selecionados.
                    Digite no campo para pesquisar. Se nenhum perfil for selecionado, os botões ficam ocultos para todos.
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="ti ti-message-circle me-2" aria-hidden="true"></i>Acompanhamentos e visualizadores
                </h3>
            </div>
            <div class="card-body">
                {$linhas_opcoes}
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="ti ti-forms me-2" aria-hidden="true"></i>Campos Adicionais do Formulário
                </h3>
            </div>
            <div class="card-body">
                <div class="form-hint mb-3">
                    Campos exibidos logo abaixo da <strong>Entidade</strong> nos formulários de Chamado, Problema e Mudança,
                    alimentados pelos <strong>Dados do Cliente</strong> cadastrados abaixo. Depois da abertura, os valores
                    ficam somente leitura. Cada campo ativo também fica disponível como coluna e filtro nas listas.
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr><th>Campo</th><th class="text-center w-1">Ativo</th><th class="text-center w-1">Obrigatório</th></tr>
                        </thead>
                        <tbody>{$linhas_campos}</tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" name="update" value="1" class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Salvar
                </button>
            </div>
        </div>
    </form>

    <div class="card" id="botoes-clientedados" data-url="{$ajax_url}">
        <div class="card-header">
            <h3 class="card-title">
                <i class="ti ti-building me-2" aria-hidden="true"></i>Dados do Cliente
            </h3>
        </div>
        <div class="card-body">
            <div class="form-hint mb-3">
                Unidades, setores e telefones de cada entidade. Cada alteração é salva na hora, sem precisar do botão Salvar.
                Ao renomear uma unidade ou um setor, os chamados, problemas e mudanças já registrados com ele também são atualizados.
                Remover um item do cadastro não apaga o valor dos registros existentes.
            </div>
            <div class="mb-3" style="max-width:32rem;">
                <label class="form-label">Entidade</label>
                {$entidade_dropdown}
            </div>
            <div class="botoes-cd-recado" role="status" aria-live="polite"></div>
            <div class="row g-3 botoes-cd-paineis">
                <div class="col-lg-4"><div class="botoes-cd-painel" data-tipo="unidade" data-titulo="Unidades" data-icone="ti ti-map-pin" data-placeholder="Nome da unidade"></div></div>
                <div class="col-lg-4"><div class="botoes-cd-painel" data-tipo="setor" data-titulo="Setores" data-icone="ti ti-briefcase" data-placeholder="Nome do setor"></div></div>
                <div class="col-lg-4"><div class="botoes-cd-painel" data-tipo="telefone" data-titulo="Telefones" data-icone="ti ti-phone" data-placeholder="(DDD) número"></div></div>
            </div>
        </div>
    </div>
</div>
HTML;

Html::footer();
