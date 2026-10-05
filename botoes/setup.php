<?php

/**
 * -------------------------------------------------------------------------
 * Botoes Plugin for GLPI
 * -------------------------------------------------------------------------
 * @package   Botoes
 * @license   GPLv3+
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_BOTOES_VERSION', '1.8.0');
define('PLUGIN_BOTOES_MIN_GLPI', '11.0.0');
define('PLUGIN_BOTOES_MAX_GLPI', '13.0.0');

/** Contexto usado na tabela glpi_configs */
define('PLUGIN_BOTOES_CONFIG_CONTEXT', 'plugin:botoes');

/**
 * Initialize plugin hooks
 */
function plugin_init_botoes()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['botoes'] = true;

    // Arquivos servidos a partir do diretório public/ do plugin
    // sla.js: barras de SLA na lista e no formulário do chamado (antigo plugin barrasdesla)
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['botoes'] = ['js/botoes.js', 'js/campos.js', 'js/sla.js'];
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['botoes']        = 'css/botoes.css';

    $plugin = new Plugin();
    if ($plugin->isActivated('botoes')) {
        // Aviso "Público ou Privado?" ao enviar um acompanhamento
        if (plugin_botoes_opcao('privacidade')) {
            $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['botoes'][] = 'js/acompanhamento.js';
        }
        // Aba "Visualizadores" no chamado
        Plugin::registerClass('PluginBotoesVisualizadores', ['addtabon' => ['Ticket']]);

        // Status e atores somente leitura para os perfis que usam os botões (servidor + tela)
        if (plugin_botoes_opcao('somenteleitura')) {
            foreach (plugin_botoes_supported_itemtypes() as $itemtype) {
                $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['botoes'][$itemtype] = ['PluginBotoesSomenteleitura', 'antesAtualizar'];
            }
            if (Session::getLoginUserID() && plugin_botoes_current_profile_allowed()) {
                $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['botoes'][] = 'js/somenteleitura.js';
            }
        }
    }

    // Único ponto de renderização dos botões: barra inferior da timeline (Chamado, Problema, Mudança)
    $PLUGIN_HOOKS[Hooks::TIMELINE_ACTIONS]['botoes'] = 'plugin_botoes_timeline_actions';

    // Campos Adicionais do Formulário (Unidade, Setor, Telefone) ligados aos Dados do Cliente
    $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['botoes'] = ['PluginBotoesCampos', 'injetar'];
    foreach (plugin_botoes_supported_itemtypes() as $itemtype) {
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['botoes'][$itemtype] = ['PluginBotoesCampos', 'validar'];
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['botoes'][$itemtype]     = ['PluginBotoesCampos', 'salvar'];
        $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['botoes'][$itemtype]   = ['PluginBotoesCampos', 'remover'];
    }

    // Tela de configuração (Configurar > Plugins)
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['botoes'] = 'front/config.form.php';
    }
}

/**
 * Get name and version of the plugin
 */
function plugin_version_botoes()
{
    return [
        'name'         => 'Botões',
        'version'      => PLUGIN_BOTOES_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_BOTOES_MIN_GLPI,
                'max' => PLUGIN_BOTOES_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_botoes_check_prerequisites()
{
    return true;
}

function plugin_botoes_check_config($verbose = false)
{
    return true;
}

/**
 * Tipos de objeto ITIL em que os botões são exibidos.
 *
 * @return string[]
 */
function plugin_botoes_supported_itemtypes(): array
{
    return [Ticket::class, Problem::class, Change::class];
}

/**
 * IDs dos perfis autorizados a ver e usar os botões.
 *
 * @return int[]
 */
function plugin_botoes_get_allowed_profiles(): array
{
    $values = Config::getConfigurationValues(PLUGIN_BOTOES_CONFIG_CONTEXT, ['profiles']);
    $ids    = json_decode((string) ($values['profiles'] ?? '[]'), true);

    return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
}

/**
 * @param int[] $ids
 */
function plugin_botoes_set_allowed_profiles(array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
    Config::setConfigurationValues(PLUGIN_BOTOES_CONFIG_CONTEXT, ['profiles' => json_encode($ids)]);
}

/**
 * O perfil ativo do usuário logado pode ver os botões?
 */
function plugin_botoes_current_profile_allowed(): bool
{
    $profile_id = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
    return $profile_id > 0 && in_array($profile_id, plugin_botoes_get_allowed_profiles(), true);
}

/**
 * Opções liga/desliga do plugin (ligadas por padrão).
 * privacidade: aviso "Público ou Privado?" ao enviar acompanhamento; visualizadores: aba no chamado;
 * somenteleitura: status e atores somente leitura para os perfis que usam os botões.
 */
function plugin_botoes_opcoes_padrao(): array
{
    return ['privacidade' => '1', 'visualizadores' => '1', 'somenteleitura' => '1'];
}

function plugin_botoes_opcao(string $nome): bool
{
    static $valores = null;
    if ($valores === null) {
        $padrao  = plugin_botoes_opcoes_padrao();
        $valores = array_merge($padrao, Config::getConfigurationValues(PLUGIN_BOTOES_CONFIG_CONTEXT, array_keys($padrao)));
    }
    return ($valores[$nome] ?? '0') === '1';
}

/**
 * Campo CSRF para formulários HTML: o GLPI 11 exige token; no GLPI 12 a proteção
 * é feita pelos cabeçalhos Sec-Fetch-Site/Origin e o token foi descontinuado.
 * (As chamadas ajax via jQuery já recebem o token automaticamente no GLPI 11.)
 */
function plugin_botoes_campo_csrf(): string
{
    return version_compare(GLPI_VERSION, '12.0.0-dev', '<')
        ? Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()])
        : '';
}
