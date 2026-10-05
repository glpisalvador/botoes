<?php

/**
 * -------------------------------------------------------------------------
 * Botoes Plugin for GLPI - Dados do cliente
 * -------------------------------------------------------------------------
 * Cadastro manual, por entidade, das unidades, setores e telefones que alimentam
 * os Campos Adicionais dos formulários de Chamado, Problema e Mudança.
 */

class PluginBotoesClientedado extends CommonDBTM
{
    public const TABELA = 'glpi_plugin_botoes_clientedados';

    public const TIPOS = ['unidade', 'setor', 'telefone'];

    public static function getTypeName($nb = 0): string
    {
        return 'Dados do cliente';
    }

    // $rightname não é redeclarada (tipada no GLPI 12, sem tipo no 11): permissões explícitas
    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canDelete(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function tipoValido(string $tipo): bool
    {
        return in_array($tipo, self::TIPOS, true);
    }

    public static function getRotulo(string $tipo): string
    {
        return ['unidade' => 'Unidade', 'setor' => 'Setor', 'telefone' => 'Telefone'][$tipo] ?? 'Registro';
    }

    /**
     * Telefone é gravado só com dígitos; unidade e setor como digitados (maiúsculas e minúsculas).
     */
    public static function normalizar(string $tipo, string $valor): string
    {
        $valor = trim((string) preg_replace('/\s+/', ' ', strip_tags($valor)));

        return match ($tipo) {
            'telefone' => substr((string) preg_replace('/\D/', '', $valor), 0, 11),
            default    => mb_substr($valor, 0, 255),
        };
    }

    /**
     * Telefone com DDD: 10 dígitos (fixo) ou 11 dígitos (celular).
     */
    public static function validarTelefone(string $digitos): string
    {
        $tamanho = strlen($digitos);
        if ($tamanho !== 10 && $tamanho !== 11) {
            return 'O telefone deve ter DDD + número (10 ou 11 dígitos).';
        }
        if (preg_match('/^(\d)\1+$/', $digitos)) {
            return 'O telefone informado não é válido.';
        }
        return '';
    }

    public static function formatarTelefone(string $telefone): string
    {
        $n = (string) preg_replace('/\D/', '', $telefone);

        if (strlen($n) === 11) {
            return '(' . substr($n, 0, 2) . ') ' . substr($n, 2, 5) . '-' . substr($n, 7, 4);
        }
        if (strlen($n) === 10) {
            return '(' . substr($n, 0, 2) . ') ' . substr($n, 2, 4) . '-' . substr($n, 6, 4);
        }
        return $n;
    }

    /**
     * Entidade existente e visível para o usuário na sessão atual.
     */
    public static function entidadePermitida(int $entities_id): bool
    {
        if ($entities_id < 0) {
            return false;
        }

        $ativas = array_map('intval', $_SESSION['glpiactiveentities'] ?? []);
        if (count($ativas) > 0 && !in_array($entities_id, $ativas, true)) {
            return false;
        }

        return countElementsInTable('glpi_entities', ['id' => $entities_id]) > 0;
    }

    /**
     * Registros de um tipo cadastrados na entidade (sem herança da entidade pai).
     *
     * @return array<int, array{id:int, valor:string, texto:string}>
     */
    public static function listar(string $tipo, int $entities_id): array
    {
        global $DB;

        $lista = [];
        if (!self::tipoValido($tipo) || $entities_id < 0 || !$DB->tableExists(self::TABELA)) {
            return $lista;
        }

        foreach ($DB->request([
            'SELECT' => ['id', 'nome'],
            'FROM'   => self::TABELA,
            'WHERE'  => ['tipo' => $tipo, 'entities_id' => $entities_id],
            'ORDER'  => 'nome ASC',
        ]) as $linha) {
            $valor   = (string) $linha['nome'];
            $lista[] = [
                'id'    => (int) $linha['id'],
                'valor' => $valor,
                'texto' => $tipo === 'telefone' ? self::formatarTelefone($valor) : $valor,
            ];
        }

        return $lista;
    }

    public static function porId(int $id): ?array
    {
        global $DB;

        if ($id <= 0 || !$DB->tableExists(self::TABELA)) {
            return null;
        }

        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $linha) {
            return $linha;
        }

        return null;
    }

    private static function validar(string $tipo, string $valor): string
    {
        if ($valor === '') {
            return [
                'unidade'  => 'Informe o nome da unidade.',
                'setor'    => 'Informe o nome do setor.',
                'telefone' => 'Informe o telefone.',
            ][$tipo];
        }

        return $tipo === 'telefone' ? self::validarTelefone($valor) : '';
    }

    private static function repetido(string $tipo, int $entities_id, string $valor, int $ignorar_id = 0): bool
    {
        $onde = ['tipo' => $tipo, 'entities_id' => $entities_id, 'nome' => $valor];
        if ($ignorar_id > 0) {
            $onde['NOT'] = ['id' => $ignorar_id];
        }

        return countElementsInTable(self::TABELA, $onde) > 0;
    }

    /**
     * @return string mensagem de erro, ou vazio em caso de sucesso
     */
    public static function adicionar(string $tipo, int $entities_id, string $valor): string
    {
        global $DB;

        if (!self::tipoValido($tipo)) {
            return 'Tipo de cadastro inválido.';
        }
        if (!self::entidadePermitida($entities_id)) {
            return 'Entidade inválida ou fora do seu perfil.';
        }

        $valor = self::normalizar($tipo, $valor);
        $erro  = self::validar($tipo, $valor);
        if ($erro !== '') {
            return $erro;
        }
        if (self::repetido($tipo, $entities_id, $valor)) {
            return sprintf('Este %s já está cadastrado nesta entidade.', mb_strtolower(self::getRotulo($tipo)));
        }

        $DB->insert(self::TABELA, [
            'tipo'        => $tipo,
            'entities_id' => $entities_id,
            'nome'        => $valor,
            'users_id'    => (int) Session::getLoginUserID(),
        ]);

        return '';
    }

    public static function atualizar(int $id, string $valor): string
    {
        global $DB;

        $registro = self::porId($id);
        if ($registro === null) {
            return 'Registro não encontrado.';
        }

        $tipo     = (string) $registro['tipo'];
        $entidade = (int) $registro['entities_id'];
        if (!self::entidadePermitida($entidade)) {
            return 'Entidade inválida ou fora do seu perfil.';
        }

        $valor = self::normalizar($tipo, $valor);
        $erro  = self::validar($tipo, $valor);
        if ($erro !== '') {
            return $erro;
        }
        if (self::repetido($tipo, $entidade, $valor, $id)) {
            return sprintf('Já existe outro %s igual nesta entidade.', mb_strtolower(self::getRotulo($tipo)));
        }

        $DB->update(self::TABELA, ['nome' => $valor], ['id' => $id]);

        self::$ultimos_vinculados = self::propagarRenomeacao($tipo, $id, $entidade, (string) $registro['nome'], $valor);

        return '';
    }

    /** Quantidade de Chamados/Problemas/Mudanças atualizados na última renomeação */
    public static int $ultimos_vinculados = 0;

    /**
     * Leva o novo nome de uma unidade ou setor para os itens já registrados com ele.
     * - Unidade: pelo id do cadastro, gravado em cada item.
     * - Setor: pelo nome antigo, só nos itens da mesma entidade (o item guarda apenas o texto).
     *
     * @return int quantidade de itens atualizados
     */
    private static function propagarRenomeacao(string $tipo, int $id, int $entidade, string $antigo, string $novo): int
    {
        global $DB;

        $tabela = PluginBotoesCampos::TABELA;
        if (!in_array($tipo, ['unidade', 'setor'], true) || !$DB->tableExists($tabela) || $antigo === $novo) {
            return 0;
        }

        if ($tipo === 'unidade') {
            $ids = [];
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => $tabela, 'WHERE' => ['unidade_id' => $id]]) as $linha) {
                $ids[] = (int) $linha['id'];
            }
            if (count($ids) > 0) {
                $DB->update($tabela, ['unidade' => $novo], ['id' => $ids]);
            }
            return count($ids);
        }

        $ids = [];
        foreach (plugin_botoes_supported_itemtypes() as $itemtype) {
            $tabela_item = $itemtype::getTable();
            foreach ($DB->request([
                'SELECT'     => ["$tabela.id"],
                'FROM'       => $tabela,
                'INNER JOIN' => [
                    $tabela_item => ['ON' => [$tabela => 'items_id', $tabela_item => 'id']],
                ],
                'WHERE'      => [
                    "$tabela.itemtype"         => $itemtype,
                    "$tabela.setor"            => $antigo,
                    "$tabela_item.entities_id" => $entidade,
                ],
            ]) as $linha) {
                $ids[] = (int) $linha['id'];
            }
        }
        if (count($ids) > 0) {
            $DB->update($tabela, ['setor' => $novo], ['id' => $ids]);
        }

        return count($ids);
    }

    public static function remover(int $id): string
    {
        global $DB;

        $registro = self::porId($id);
        if ($registro === null) {
            return 'Registro não encontrado.';
        }
        if (!self::entidadePermitida((int) $registro['entities_id'])) {
            return 'Entidade inválida ou fora do seu perfil.';
        }

        // Os itens já abertos guardam o texto: remover do cadastro não apaga o valor deles
        $DB->delete(self::TABELA, ['id' => $id]);

        return '';
    }
}
