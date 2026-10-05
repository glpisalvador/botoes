<?php

/**
 * -------------------------------------------------------------------------
 * Botoes Plugin for GLPI - Campos Adicionais do Formulário
 * -------------------------------------------------------------------------
 * Unidade, Setor e Telefone nos formulários de Chamado, Problema e Mudança,
 * alimentados pelos Dados do Cliente cadastrados por entidade.
 *
 * Regras:
 * - Unidade: somente as unidades cadastradas na entidade (sem cadastro, sem opções).
 * - Setor / Telefone: lista cadastrada na entidade; sem cadastro, texto livre.
 * - Após a abertura os valores ficam somente leitura.
 */

class PluginBotoesCampos
{
    public const TABELA = 'glpi_plugin_botoes_itemcampos';

    public const CAMPOS = ['unidade' => 'Unidade', 'setor' => 'Setor', 'telefone' => 'Telefone'];

    /** Presente apenas no formulário nativo: chamados de e-mail, API, formulários etc. não são afetados */
    public const MARCADOR = 'plugin_botoes_campos';

    private const TEMP = 'plugin_botoes_campos_temp';

    // -------------------------------------------------------------------------
    // Configuração (glpi_configs, contexto plugin:botoes)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{ativo:bool, obrigatorio:bool}>
     */
    public static function getConfig(): array
    {
        $chaves = [];
        foreach (array_keys(self::CAMPOS) as $campo) {
            $chaves[] = "campo_{$campo}";
            $chaves[] = "campo_{$campo}_obrig";
        }
        $valores = Config::getConfigurationValues(PLUGIN_BOTOES_CONFIG_CONTEXT, $chaves);

        $config = [];
        foreach (array_keys(self::CAMPOS) as $campo) {
            $config[$campo] = [
                'ativo'       => (string) ($valores["campo_{$campo}"] ?? '0') === '1',
                'obrigatorio' => (string) ($valores["campo_{$campo}_obrig"] ?? '1') === '1',
            ];
        }

        return $config;
    }

    public static function salvarConfig(array $dados): void
    {
        $valores = [];
        foreach (array_keys(self::CAMPOS) as $campo) {
            $valores["campo_{$campo}"]       = !empty($dados[$campo]['ativo']) ? '1' : '0';
            $valores["campo_{$campo}_obrig"] = !empty($dados[$campo]['obrigatorio']) ? '1' : '0';
        }
        Config::setConfigurationValues(PLUGIN_BOTOES_CONFIG_CONTEXT, $valores);
    }

    private static function algumAtivo(array $config): bool
    {
        foreach ($config as $campo) {
            if ($campo['ativo']) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Leitura dos valores
    // -------------------------------------------------------------------------

    /**
     * @return array{unidade_id:int, unidade:string, setor:string, telefone:string}|null
     */
    public static function getDados(string $itemtype, int $items_id): ?array
    {
        global $DB;

        if ($items_id <= 0 || !$DB->tableExists(self::TABELA)) {
            return null;
        }

        foreach ($DB->request([
            'FROM'  => self::TABELA,
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'LIMIT' => 1,
        ]) as $linha) {
            return [
                'unidade_id' => (int) $linha['unidade_id'],
                'unidade'    => (string) $linha['unidade'],
                'setor'      => (string) $linha['setor'],
                'telefone'   => (string) $linha['telefone'],
            ];
        }

        return null;
    }

    /**
     * Valores enviados pelo formulário (normalizados).
     */
    /**
     * @param array|null $fonte dados do formulário; padrão: $_POST
     */
    private static function valoresEnviados(?array $fonte = null): array
    {
        $fonte ??= $_POST;

        return [
            'unidade'  => (int) ($fonte['plugin_botoes_unidade'] ?? 0),
            'setor'    => PluginBotoesClientedado::normalizar('setor', (string) ($fonte['plugin_botoes_setor'] ?? '')),
            'telefone' => PluginBotoesClientedado::normalizar('telefone', (string) ($fonte['plugin_botoes_telefone'] ?? '')),
        ];
    }

    private static function buscarOpcao(array $opcoes, string $chave, $valor): ?array
    {
        foreach ($opcoes as $opcao) {
            if ((string) $opcao[$chave] === (string) $valor) {
                return $opcao;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Formulário (hook post_item_form)
    // -------------------------------------------------------------------------

    public static function injetar(array $params): void
    {
        $item = $params['item'] ?? null;
        if (
            !($item instanceof CommonITILObject)
            || !in_array($item::class, plugin_botoes_supported_itemtypes(), true)
        ) {
            return;
        }

        $config = self::getConfig();
        if (!self::algumAtivo($config)) {
            return;
        }

        if ($item->isNewItem()) {
            self::renderNovo($item, $config, is_array($params['options'] ?? null) ? $params['options'] : []);
        } else {
            self::renderLeitura($item, $config);
        }
    }

    private static function abrirCampo(string $campo, bool $obrigatorio): void
    {
        echo '<div class="form-field row align-items-center col-12 glpi-full-width mb-2 plugin-botoes-campo">';
        echo '<label class="col-form-label col-xxl-5 text-xxl-end" for="plugin_botoes_' . $campo . '">';
        echo htmlescape(self::CAMPOS[$campo]) . ($obrigatorio ? '&nbsp;<span class="required">*</span>' : '');
        echo '</label>';
        echo '<div class="col-xxl-7 field-container">';
    }

    private static function fecharCampo(): void
    {
        echo '</div></div>';
    }

    private static function renderSelect(string $campo, array $opcoes, string $selecionado): void
    {
        echo '<select name="plugin_botoes_' . $campo . '" id="plugin_botoes_' . $campo . '" class="form-select plugin-botoes-select" style="width:100%;">';
        echo '<option value="' . ($campo === 'unidade' ? '0' : '') . '">------</option>';
        foreach ($opcoes as $opcao) {
            $valor = $campo === 'unidade' ? (string) $opcao['id'] : $opcao['valor'];
            $sel   = $valor === $selecionado ? ' selected' : '';
            echo '<option value="' . htmlescape($valor) . '"' . $sel . '>' . htmlescape($opcao['texto']) . '</option>';
        }
        echo '</select>';
    }

    /**
     * @param array $opcoes opções do showForm: na recarga do item novo (troca de entidade,
     *                      categoria, atores...) o GLPI repassa aqui os dados já preenchidos,
     *                      pois o conteúdo da aba é carregado por ajax, sem o POST original.
     */
    private static function renderNovo(CommonITILObject $item, array $config, array $opcoes = []): void
    {
        $entidade = (int) ($item->fields['entities_id'] ?? 0);

        // Valores a repor: após erro de validação (sessão) ou recarga do formulário (opções / POST)
        $atual = ['unidade' => 0, 'setor' => '', 'telefone' => ''];
        $temp  = $_SESSION[self::TEMP] ?? null;
        unset($_SESSION[self::TEMP]);
        if (is_array($temp) && ($temp['itemtype'] ?? '') === $item::class) {
            $atual = array_merge($atual, array_intersect_key($temp, $atual));
        }
        if (isset($opcoes[self::MARCADOR])) {
            $atual = self::valoresEnviados($opcoes);
        } elseif (isset($_POST[self::MARCADOR])) {
            $atual = self::valoresEnviados();
        }

        echo '<input type="hidden" name="' . self::MARCADOR . '" value="1">';

        if ($config['unidade']['ativo']) {
            $opcoes = PluginBotoesClientedado::listar('unidade', $entidade);
            // Sem unidades cadastradas não há o que escolher: a obrigatoriedade não se aplica
            self::abrirCampo('unidade', $config['unidade']['obrigatorio'] && count($opcoes) > 0);
            self::renderSelect('unidade', $opcoes, (string) $atual['unidade']);
            if (count($opcoes) === 0) {
                echo '<div class="form-text">Nenhuma unidade cadastrada nos dados deste cliente.</div>';
            }
            self::fecharCampo();
        }

        if ($config['setor']['ativo']) {
            $opcoes = PluginBotoesClientedado::listar('setor', $entidade);
            self::abrirCampo('setor', $config['setor']['obrigatorio']);
            if (count($opcoes) > 0) {
                self::renderSelect('setor', $opcoes, (string) $atual['setor']);
            } else {
                echo '<input type="text" name="plugin_botoes_setor" id="plugin_botoes_setor" class="form-control plugin-botoes-maiusculo"'
                    . ' maxlength="255" placeholder="Digite o setor" value="' . htmlescape((string) $atual['setor']) . '">';
            }
            self::fecharCampo();
        }

        if ($config['telefone']['ativo']) {
            $opcoes = PluginBotoesClientedado::listar('telefone', $entidade);
            self::abrirCampo('telefone', $config['telefone']['obrigatorio']);
            if (count($opcoes) > 0) {
                self::renderSelect('telefone', $opcoes, (string) $atual['telefone']);
            } else {
                echo '<input type="tel" name="plugin_botoes_telefone" id="plugin_botoes_telefone" class="form-control plugin-botoes-telefone"'
                    . ' maxlength="15" inputmode="numeric" placeholder="(DDD) número"'
                    . ' value="' . htmlescape(PluginBotoesClientedado::formatarTelefone((string) $atual['telefone'])) . '">';
            }
            self::fecharCampo();
        }
    }

    private static function renderLeitura(CommonITILObject $item, array $config): void
    {
        $dados = self::getDados($item::class, (int) $item->getID());

        $textos = [
            'unidade'  => $dados['unidade'] ?? '',
            'setor'    => $dados['setor'] ?? '',
            'telefone' => PluginBotoesClientedado::formatarTelefone($dados['telefone'] ?? ''),
        ];

        foreach (array_keys(self::CAMPOS) as $campo) {
            if (!$config[$campo]['ativo']) {
                continue;
            }
            self::abrirCampo($campo, false);
            echo '<input type="text" class="form-control" readonly disabled value="'
                . htmlescape($textos[$campo] !== '' ? $textos[$campo] : '------') . '">';
            self::fecharCampo();
        }
    }

    // -------------------------------------------------------------------------
    // Validação (hook pre_item_add) e gravação (item_add / item_purge)
    // -------------------------------------------------------------------------

    public static function validar(CommonDBTM $item): void
    {
        if (!isset($_POST[self::MARCADOR]) || !in_array($item::class, plugin_botoes_supported_itemtypes(), true)) {
            return;
        }

        $config = self::getConfig();
        if (!self::algumAtivo($config)) {
            return;
        }

        $entidade = (int) ($item->input['entities_id'] ?? $_POST['entities_id'] ?? 0);
        $valores  = self::valoresEnviados();
        $erros    = [];

        if ($config['unidade']['ativo']) {
            $opcoes = PluginBotoesClientedado::listar('unidade', $entidade);
            if (count($opcoes) > 0) {
                if ($valores['unidade'] <= 0 && $config['unidade']['obrigatorio']) {
                    $erros[] = 'O campo <b>Unidade</b> é obrigatório.';
                } elseif ($valores['unidade'] > 0 && self::buscarOpcao($opcoes, 'id', $valores['unidade']) === null) {
                    $erros[] = 'A <b>Unidade</b> escolhida não pertence a esta entidade.';
                }
            }
        }

        foreach (['setor', 'telefone'] as $campo) {
            if (!$config[$campo]['ativo']) {
                continue;
            }
            $rotulo = self::CAMPOS[$campo];
            $opcoes = PluginBotoesClientedado::listar($campo, $entidade);
            $valor  = $valores[$campo];

            if ($valor === '') {
                if ($config[$campo]['obrigatorio']) {
                    $erros[] = "O campo <b>{$rotulo}</b> é obrigatório.";
                }
            } elseif (count($opcoes) > 0) {
                if (self::buscarOpcao($opcoes, 'valor', $valor) === null) {
                    $erros[] = "O <b>{$rotulo}</b> escolhido não pertence a esta entidade.";
                }
            } elseif ($campo === 'telefone') {
                $erro = PluginBotoesClientedado::validarTelefone($valor);
                if ($erro !== '') {
                    $erros[] = "<b>{$rotulo}</b>: " . htmlescape($erro);
                }
            }
        }

        if (count($erros) === 0) {
            return;
        }

        $_SESSION[self::TEMP] = ['itemtype' => $item::class] + $valores;
        foreach ($erros as $erro) {
            Session::addMessageAfterRedirect($erro, false, ERROR);
        }
        $item->input = false;
    }

    public static function salvar(CommonDBTM $item): void
    {
        global $DB;

        if (!isset($_POST[self::MARCADOR]) || !in_array($item::class, plugin_botoes_supported_itemtypes(), true)) {
            return;
        }
        unset($_SESSION[self::TEMP]);

        $items_id = (int) $item->getID();
        if ($items_id <= 0 || !$DB->tableExists(self::TABELA)) {
            return;
        }

        $config   = self::getConfig();
        $entidade = (int) ($item->fields['entities_id'] ?? 0);
        $valores  = self::valoresEnviados();

        // Guarda o texto da unidade: o chamado não muda se o cadastro for alterado depois
        $unidade_id   = 0;
        $unidade_nome = '';
        if ($config['unidade']['ativo'] && $valores['unidade'] > 0) {
            $opcao = self::buscarOpcao(PluginBotoesClientedado::listar('unidade', $entidade), 'id', $valores['unidade']);
            if ($opcao !== null) {
                $unidade_id   = (int) $opcao['id'];
                $unidade_nome = (string) $opcao['valor'];
            }
        }

        // Setor da lista: grava como cadastrado; texto livre (entidade sem setores): maiúsculas
        $setor = $config['setor']['ativo'] ? $valores['setor'] : '';
        if ($setor !== '' && count(PluginBotoesClientedado::listar('setor', $entidade)) === 0) {
            $setor = mb_strtoupper($setor, 'UTF-8');
        }

        $linha = [
            'unidade_id' => $unidade_id,
            'unidade'    => $unidade_nome,
            'setor'      => $setor,
            // Gravado já formatado para exibição na coluna das listas
            'telefone'   => $config['telefone']['ativo'] ? PluginBotoesClientedado::formatarTelefone($valores['telefone']) : '',
        ];

        if ($linha['unidade_id'] === 0 && $linha['setor'] === '' && $linha['telefone'] === '') {
            return;
        }

        $existente = self::getDados($item::class, $items_id);
        if ($existente !== null) {
            $DB->update(self::TABELA, $linha, ['itemtype' => $item::class, 'items_id' => $items_id]);
        } else {
            $DB->insert(self::TABELA, $linha + ['itemtype' => $item::class, 'items_id' => $items_id]);
        }
    }

    public static function remover(CommonDBTM $item): void
    {
        global $DB;

        if (!in_array($item::class, plugin_botoes_supported_itemtypes(), true) || !$DB->tableExists(self::TABELA)) {
            return;
        }

        $DB->delete(self::TABELA, ['itemtype' => $item::class, 'items_id' => (int) $item->getID()]);
    }
}
