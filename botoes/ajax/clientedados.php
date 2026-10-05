<?php

/**
 * -------------------------------------------------------------------------
 * Botoes Plugin for GLPI - Ajax do cadastro de Dados do Cliente
 * -------------------------------------------------------------------------
 * listar (GET) | adicionar, atualizar, remover (POST)
 * O bootstrap do GLPI (sessão, autoload, CSRF) é feito pelo kernel antes deste arquivo.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

Session::checkLoginUser();

/**
 * Todos os tipos cadastrados na entidade, no formato usado pela tela.
 */
function plugin_botoes_cd_listar(int $entidade): array
{
    $permitida = PluginBotoesClientedado::entidadePermitida($entidade);
    $dados     = [];
    foreach (PluginBotoesClientedado::TIPOS as $tipo) {
        $dados[$tipo] = $permitida ? PluginBotoesClientedado::listar($tipo, $entidade) : [];
    }
    return $dados;
}

$acao = (string) ($_POST['acao'] ?? $_GET['acao'] ?? '');

if ($acao === 'listar') {
    $entidade = (int) ($_GET['entidade'] ?? -1);

    if (!Session::haveRight('config', READ)) {
        $resultado = ['success' => false, 'message' => 'Sem permissão para ver os dados do cliente.'];
    } elseif (!PluginBotoesClientedado::entidadePermitida($entidade)) {
        $resultado = ['success' => false, 'message' => 'Entidade inválida ou fora do seu perfil.'];
    } else {
        $resultado = ['success' => true, 'entidade' => $entidade, 'dados' => plugin_botoes_cd_listar($entidade)];
    }
} elseif (in_array($acao, ['adicionar', 'atualizar', 'remover'], true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Session::haveRight('config', UPDATE)) {
        $resultado = ['success' => false, 'message' => 'Sem permissão para alterar os dados do cliente.'];
    } else {
        $id    = (int) ($_POST['id'] ?? 0);
        $valor = (string) ($_POST['valor'] ?? '');

        $erro = match ($acao) {
            'adicionar' => PluginBotoesClientedado::adicionar(
                (string) ($_POST['tipo'] ?? ''),
                (int) ($_POST['entidade'] ?? -1),
                $valor
            ),
            'atualizar' => PluginBotoesClientedado::atualizar($id, $valor),
            'remover'   => PluginBotoesClientedado::remover($id),
        };

        // Entidade de referência para devolver a lista atualizada
        $entidade = (int) ($_POST['entidade'] ?? -1);

        $mensagem = 'Dados do cliente salvos com sucesso.';
        if ($acao === 'atualizar' && PluginBotoesClientedado::$ultimos_vinculados > 0) {
            $mensagem .= sprintf(
                ' %d chamado(s)/problema(s)/mudança(s) já registrados foram atualizados.',
                PluginBotoesClientedado::$ultimos_vinculados
            );
        }

        $resultado = $erro === ''
            ? ['success' => true, 'message' => $mensagem, 'entidade' => $entidade, 'dados' => plugin_botoes_cd_listar($entidade)]
            : ['success' => false, 'message' => $erro];
    }
} else {
    $resultado = ['success' => false, 'message' => 'Ação desconhecida.'];
}

echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
