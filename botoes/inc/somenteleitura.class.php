<?php

/**
 * -------------------------------------------------------------------------
 * Botoes Plugin for GLPI - Status e atores somente leitura
 * -------------------------------------------------------------------------
 * Para os perfis que usam os botões, o status e os atores (requerente, observador, atribuído) de Chamados,
 * Problemas e Mudanças já abertos não mudam pelo formulário, pelo kanban nem pela ação em massa.
 * O gancho pre_item_update roda antes das regras do GLPI e só descarta o que o usuário enviou nessas telas:
 * os botões do plugin, soluções, acompanhamentos, aprovações, regras e ações automáticas continuam valendo.
 */
class PluginBotoesSomenteleitura
{
    /** Telas em que a pessoa edita os campos diretamente */
    private const ROTAS = '#/(front/(ticket|problem|change)\.form\.php|ajax/kanban\.php|(ajax|front)/massiveaction\.php)$#';

    /** Chaves de atores aceitas pelo GLPI 11/12 na atualização de um item ITIL */
    private const CHAVES_ATORES = '/^_(itil_(requester|observer|assign)|(users|groups|suppliers)_id_(requester|observer|assign)(_notif|_deleted)?)$/';

    public const MENSAGEM = 'Status e atores são somente leitura: use os botões Aceitar, Pendente e Grupo Observador.';

    /** A regra vale para a pessoa logada? */
    public static function aplica(): bool
    {
        // Ações automáticas e linha de comando não têm rota de tela: rotaInterativa() já as deixa passar
        return Session::getLoginUserID()
            && plugin_botoes_opcao('somenteleitura')
            && plugin_botoes_current_profile_allowed();
    }

    private static function rotaInterativa(): bool
    {
        $caminho = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return (bool) preg_match(self::ROTAS, $caminho);
    }

    /**
     * Conjunto "tipo:Itemtype_id" dos atores atuais ou enviados.
     *
     * @param array<string, array> $porTipo
     * @return string[]
     */
    private static function normalizar(array $porTipo): array
    {
        $r = [];
        foreach (['requester', 'observer', 'assign'] as $tipo) {
            foreach ((array) ($porTipo[$tipo] ?? []) as $a) {
                if (is_array($a) && isset($a['itemtype'], $a['items_id'])) {
                    $r[] = $tipo . ':' . $a['itemtype'] . '_' . (int) $a['items_id'];
                }
            }
        }
        sort($r);
        return array_values(array_unique($r));
    }

    private static function atoresAtuais(CommonITILObject $item): array
    {
        $atual = [];
        foreach (['requester' => CommonITILActor::REQUESTER, 'observer' => CommonITILActor::OBSERVER, 'assign' => CommonITILActor::ASSIGN] as $tipo => $valor) {
            $atual[$tipo] = $item->getActorsForType($valor);
        }
        return $atual;
    }

    /** pre_item_update de Ticket, Problem e Change */
    public static function antesAtualizar(CommonDBTM $item): void
    {
        if (!$item instanceof CommonITILObject || !is_array($item->input) || !self::aplica() || !self::rotaInterativa()) {
            return;
        }
        $bloqueou = false;

        if (array_key_exists('status', $item->input) && (int) $item->input['status'] !== (int) ($item->fields['status'] ?? 0)) {
            unset($item->input['status']);
            $bloqueou = true;
        }

        if (array_key_exists('_actors', $item->input)) {
            $enviados = is_array($item->input['_actors']) ? $item->input['_actors'] : [];
            if (self::normalizar($enviados) !== self::normalizar(self::atoresAtuais($item))) {
                $bloqueou = true;
            }
            unset($item->input['_actors']);
        }

        foreach (array_keys($item->input) as $chave) {
            if (is_string($chave) && preg_match(self::CHAVES_ATORES, $chave)) {
                unset($item->input[$chave]);
                $bloqueou = true;
            }
        }

        if ($bloqueou) {
            Session::addMessageAfterRedirect(htmlescape(self::MENSAGEM), false, WARNING);
        }
    }
}
