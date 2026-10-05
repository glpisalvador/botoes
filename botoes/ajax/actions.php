<?php

/**
 * -------------------------------------------------------------------------
 * Botoes Plugin for GLPI - Ajax Actions (Chamado, Problema, Mudança)
 * -------------------------------------------------------------------------
 * O bootstrap do GLPI (sessão, autoload, CSRF) é feito pelo kernel antes deste arquivo.
 */

header('Content-Type: application/json; charset=UTF-8');

Session::checkLoginUser();

/**
 * Coleta e limpa as mensagens de erro/aviso deixadas na sessão pelo core.
 */
function plugin_botoes_session_errors(): string
{
    $chunks = [];
    if (!empty($_SESSION['MESSAGE_AFTER_REDIRECT']) && is_array($_SESSION['MESSAGE_AFTER_REDIRECT'])) {
        foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] as $type => $messages) {
            if ((int) $type === INFO) {
                continue;
            }
            foreach ((array) $messages as $msg) {
                $chunks[] = trim(strip_tags((string) $msg));
            }
        }
        unset($_SESSION['MESSAGE_AFTER_REDIRECT']);
    }
    return implode(' ', array_filter($chunks));
}

function plugin_botoes_fail(string $default): array
{
    $err = plugin_botoes_session_errors();
    return ['success' => false, 'message' => $err !== '' ? $err : $default];
}

function plugin_botoes_ok(string $message): array
{
    // Exibida como toast após o recarregamento da página
    Session::addMessageAfterRedirect(htmlescape($message), false, INFO);
    return ['success' => true, 'message' => $message];
}

function plugin_botoes_ok_logged(string $message, bool $logged): array
{
    if (!$logged) {
        $message .= ' (atenção: não foi possível registrar o acompanhamento automático)';
    }
    return plugin_botoes_ok($message);
}

function plugin_botoes_current_user_name(): string
{
    $name = getUserName((int) Session::getLoginUserID());
    return is_string($name) && $name !== '' ? $name : 'Usuário #' . (int) Session::getLoginUserID();
}

/**
 * Particularidades de cada tipo de objeto ITIL.
 *
 * - noun/posto: textos ("Chamado posto", "Mudança posta")
 * - accept_to: status de "em atendimento" do tipo
 * - accept_from: status a partir dos quais Aceitar muda o status
 *   (nos demais o usuário só é atribuído, sem retroceder o fluxo)
 */
function plugin_botoes_type_info(CommonITILObject $item): array
{
    return match ($item::class) {
        Problem::class => [
            'noun'        => 'Problema',
            'posto'       => 'posto',
            'accept_to'   => CommonITILObject::ASSIGNED,
            'accept_from' => [CommonITILObject::INCOMING, CommonITILObject::ACCEPTED, CommonITILObject::WAITING],
        ],
        Change::class => [
            'noun'        => 'Mudança',
            'posto'       => 'posta',
            // Mudança não possui "Em atendimento": o equivalente é "Aceita"
            'accept_to'   => CommonITILObject::ACCEPTED,
            'accept_from' => [CommonITILObject::INCOMING, Change::EVALUATION, CommonITILObject::WAITING],
        ],
        default => [
            'noun'        => 'Chamado',
            'posto'       => 'posto',
            'accept_to'   => CommonITILObject::ASSIGNED,
            'accept_from' => [CommonITILObject::INCOMING, CommonITILObject::WAITING],
        ],
    };
}

/**
 * Registra um acompanhamento automático descrevendo a ação executada.
 *
 * @param array $lines Linhas em texto puro (escapadas aqui)
 * @param array $extra Campos adicionais do ITILFollowup
 */
function plugin_botoes_log_followup(CommonITILObject $item, array $lines, array $extra = []): bool
{
    $content = '';
    foreach ($lines as $line) {
        $content .= '<p>' . nl2br(htmlescape($line)) . '</p>';
    }

    $followup = new ITILFollowup();
    return (bool) $followup->add($extra + [
        'itemtype'               => $item::class,
        'items_id'               => (int) $item->getID(),
        'content'                => $content,
        'is_private'             => 0,
        // O registro automático não deve reabrir nem recalcular o status
        '_no_reopen'             => 1,
        '_do_not_compute_status' => 1,
    ]);
}

function plugin_botoes_is_assigned(CommonITILObject $item, int $user_id): bool
{
    $link = new ($item->userlinkclass)();
    return count($link->find([
        $item::getForeignKeyField() => (int) $item->getID(),
        'users_id'                  => $user_id,
        'type'                      => CommonITILActor::ASSIGN,
    ])) > 0;
}

/**
 * @return array<int, string> groups_id => nome dos grupos observadores atuais
 */
function plugin_botoes_observer_names(CommonITILObject $item): array
{
    $names = [];
    $link  = new ($item->grouplinkclass)();
    foreach ($link->find([$item::getForeignKeyField() => (int) $item->getID(), 'type' => CommonITILActor::OBSERVER]) as $rel) {
        $group = new Group();
        if ($group->getFromDB($rel['groups_id'])) {
            $names[(int) $rel['groups_id']] = $group->fields['completename'] ?: $group->fields['name'];
        }
    }
    return $names;
}

function plugin_botoes_text_list(array $names): string
{
    return count($names) ? implode(', ', $names) : 'nenhum';
}

function plugin_botoes_is_finished(CommonITILObject $item): bool
{
    $status = (int) $item->fields['status'];
    return in_array($status, $item::getClosedStatusArray(), true)
        || in_array($status, $item::getSolvedStatusArray(), true);
}

// -----------------------------------------------------------------------------
// Ações
// -----------------------------------------------------------------------------

function plugin_botoes_accept(CommonITILObject $item): array
{
    $info    = plugin_botoes_type_info($item);
    $id      = (int) $item->getID();
    $user_id = (int) Session::getLoginUserID();

    if (plugin_botoes_is_finished($item)) {
        return ['success' => false, 'message' => sprintf('Não é possível aceitar: %s já está solucionado ou fechado.', mb_strtolower($info['noun']))];
    }

    $was_assigned  = plugin_botoes_is_assigned($item, $user_id);
    $change_status = in_array((int) $item->fields['status'], $info['accept_from'], true);

    if ($was_assigned && !$change_status) {
        return ['success' => false, 'message' => 'Você já está atribuído e o atendimento já foi iniciado.'];
    }

    if (!$was_assigned) {
        if (!$item->canAssignToMe() && !$item->canAssign()) {
            return ['success' => false, 'message' => 'Você não tem permissão para se atribuir a este item.'];
        }

        $item->update([
            'id'               => $id,
            '_users_id_assign' => $user_id,
        ]);

        if (!plugin_botoes_is_assigned($item, $user_id)) {
            // Fallback: vínculo direto do técnico
            $link  = new ($item->userlinkclass)();
            $added = $link->add([
                $item::getForeignKeyField() => $id,
                'users_id'                  => $user_id,
                'type'                      => CommonITILActor::ASSIGN,
                'use_notification'          => 1,
            ]);
            if (!$added) {
                return plugin_botoes_fail('Não foi possível atribuir o item ao seu usuário.');
            }
        }
    }

    // A atribuição pode já ter alterado o status automaticamente
    $item->getFromDB($id);
    if (
        in_array((int) $item->fields['status'], $info['accept_from'], true)
        && (int) $item->fields['status'] !== $info['accept_to']
    ) {
        $item->update([
            'id'     => $id,
            'status' => $info['accept_to'],
        ]);
        $item->getFromDB($id);
        if ((int) $item->fields['status'] !== $info['accept_to']) {
            return plugin_botoes_fail(sprintf(
                'Você foi atribuído, mas não foi possível alterar o status para "%s".',
                $item::getStatus($info['accept_to'])
            ));
        }
    }

    plugin_botoes_session_errors();
    $logged = plugin_botoes_log_followup($item, ['Atendimento iniciado por ' . plugin_botoes_current_user_name()]);
    plugin_botoes_session_errors();

    return plugin_botoes_ok_logged('Atendimento iniciado: você foi atribuído.', $logged);
}

function plugin_botoes_pending(CommonITILObject $item, string $reason): array
{
    $info = plugin_botoes_type_info($item);
    $id   = (int) $item->getID();

    if (!$item->canUpdateItem()) {
        return ['success' => false, 'message' => 'Você não tem permissão para alterar o status deste item.'];
    }
    if (plugin_botoes_is_finished($item)) {
        return ['success' => false, 'message' => 'Não é possível colocar em pendente um item solucionado ou fechado.'];
    }
    if ((int) $item->fields['status'] === CommonITILObject::WAITING) {
        return ['success' => false, 'message' => sprintf('%s já está Pendente.', $info['noun'])];
    }

    $lines = [sprintf('%s %s em pendente por %s', $info['noun'], $info['posto'], plugin_botoes_current_user_name())];
    if ($reason !== '') {
        $lines[] = "Motivo:\n" . $reason;
    }

    // "pending" é o mesmo mecanismo do formulário nativo: o core coloca o item em Pendente
    $logged = plugin_botoes_log_followup($item, $lines, ['pending' => 1]);

    $item->getFromDB($id);
    if ((int) $item->fields['status'] !== CommonITILObject::WAITING) {
        $item->update([
            'id'     => $id,
            'status' => CommonITILObject::WAITING,
        ]);
        $item->getFromDB($id);
        if ((int) $item->fields['status'] !== CommonITILObject::WAITING) {
            return plugin_botoes_fail('Falha ao alterar o status para Pendente.');
        }
    }

    plugin_botoes_session_errors();
    return plugin_botoes_ok_logged(sprintf('%s %s em Pendente.', $info['noun'], $info['posto']), $logged);
}

function plugin_botoes_observer_data(CommonITILObject $item): array
{
    global $DB;

    $link_table = getTableForItemType($item->grouplinkclass);

    $current  = [];
    $iterator = $DB->request([
        'SELECT'     => ["$link_table.id AS relation_id", 'glpi_groups.id', 'glpi_groups.name', 'glpi_groups.completename'],
        'FROM'       => $link_table,
        'INNER JOIN' => [
            'glpi_groups' => [
                'ON' => [$link_table => 'groups_id', 'glpi_groups' => 'id'],
            ],
        ],
        'WHERE'      => [
            "$link_table." . $item::getForeignKeyField() => (int) $item->getID(),
            "$link_table.type"                           => CommonITILActor::OBSERVER,
        ],
        'ORDER'      => 'glpi_groups.completename',
    ]);
    foreach ($iterator as $row) {
        $current[] = [
            'relation_id' => (int) $row['relation_id'],
            'id'          => (int) $row['id'],
            'name'        => $row['completename'] ?: $row['name'],
        ];
    }

    $where = getEntitiesRestrictCriteria('glpi_groups', 'entities_id', (int) $item->fields['entities_id'], true);
    $where['is_watcher'] = 1;

    $available = [];
    foreach ($DB->request(['FROM' => 'glpi_groups', 'WHERE' => $where, 'ORDER' => 'completename']) as $row) {
        $available[] = [
            'id'   => (int) $row['id'],
            'name' => $row['completename'] ?: $row['name'],
        ];
    }

    return [
        'success'    => true,
        'can_update' => $item->canUpdateItem(),
        'current'    => $current,
        'available'  => $available,
    ];
}

function plugin_botoes_observer_save(CommonITILObject $item, int $group_id, string $mode): array
{
    $id = (int) $item->getID();
    $fk = $item::getForeignKeyField();

    if (!$item->canUpdateItem()) {
        return ['success' => false, 'message' => 'Você não tem permissão para alterar grupos deste item.'];
    }

    $group = new Group();
    if ($group_id <= 0 || !$group->getFromDB($group_id)) {
        return ['success' => false, 'message' => 'Selecione um grupo válido.'];
    }

    $link     = new ($item->grouplinkclass)();
    $name     = $group->fields['completename'] ?: $group->fields['name'];
    $previous = plugin_botoes_observer_names($item);
    $exists   = isset($previous[$group_id]);

    if ($mode === 'add' && $exists) {
        return ['success' => false, 'message' => sprintf('O grupo "%s" já é observador.', $name)];
    }
    if ($mode === 'replace' && $exists && count($previous) === 1) {
        return ['success' => false, 'message' => sprintf('O grupo "%s" já é o único observador.', $name)];
    }

    if ($mode === 'replace') {
        foreach ($link->find([$fk => $id, 'type' => CommonITILActor::OBSERVER]) as $rel) {
            if ((int) $rel['groups_id'] !== $group_id) {
                $link->delete(['id' => $rel['id']]);
            }
        }
    }

    if (!$exists) {
        $added = $link->add([
            $fk                => $id,
            'groups_id'        => $group_id,
            'type'             => CommonITILActor::OBSERVER,
            'use_notification' => 1,
        ]);
        if (!$added) {
            return plugin_botoes_fail('Não foi possível associar o grupo observador.');
        }
    }

    plugin_botoes_session_errors();

    // Grupos que saíram (na substituição)
    $replaced = $previous;
    unset($replaced[$group_id]);

    if ($mode === 'replace' && count($replaced)) {
        $logged = plugin_botoes_log_followup($item, [
            'Grupo observador alterado de ' . plugin_botoes_text_list(array_values($replaced)) . ' para ' . $name,
        ]);
        $message = sprintf('Grupo observador substituído por "%s".', $name);
    } else {
        // Adição, ou substituição sem grupo anterior
        $logged  = plugin_botoes_log_followup($item, ['Adicionado o grupo observador ' . $name]);
        $message = sprintf('Grupo observador "%s" adicionado.', $name);
    }
    plugin_botoes_session_errors();

    return plugin_botoes_ok_logged($message, $logged);
}

function plugin_botoes_observer_remove(CommonITILObject $item, int $relation_id): array
{
    if (!$item->canUpdateItem()) {
        return ['success' => false, 'message' => 'Você não tem permissão para remover grupos deste item.'];
    }

    $link = new ($item->grouplinkclass)();
    if (
        $relation_id <= 0
        || !$link->getFromDB($relation_id)
        || (int) $link->fields[$item::getForeignKeyField()] !== (int) $item->getID()
        || (int) $link->fields['type'] !== CommonITILActor::OBSERVER
    ) {
        return ['success' => false, 'message' => 'Vínculo do grupo não encontrado.'];
    }

    $group = new Group();
    $name  = $group->getFromDB((int) $link->fields['groups_id'])
        ? ($group->fields['completename'] ?: $group->fields['name'])
        : '#' . (int) $link->fields['groups_id'];

    if (!$link->delete(['id' => $relation_id])) {
        return plugin_botoes_fail('Não foi possível remover o grupo observador.');
    }
    plugin_botoes_session_errors();

    $logged = plugin_botoes_log_followup($item, ['Removido o grupo observador ' . $name]);
    plugin_botoes_session_errors();

    return [
        'success' => true,
        'message' => 'Grupo observador removido.' . ($logged ? '' : ' (não foi possível registrar o acompanhamento)'),
    ];
}

// -----------------------------------------------------------------------------
// Roteamento
// -----------------------------------------------------------------------------

$action   = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$itemtype = (string) ($_POST['itemtype'] ?? $_GET['itemtype'] ?? Ticket::class);
$items_id = (int) ($_POST['items_id'] ?? $_GET['items_id'] ?? 0);

if (!in_array($itemtype, plugin_botoes_supported_itemtypes(), true)) {
    $result = ['success' => false, 'message' => 'Tipo de item não suportado.'];
} elseif (!plugin_botoes_current_profile_allowed()) {
    $result = ['success' => false, 'message' => 'Seu perfil não tem acesso a estas ações.'];
} else {
    /** @var CommonITILObject $item */
    $item = new $itemtype();
    if ($items_id <= 0 || !$item->getFromDB($items_id)) {
        $result = ['success' => false, 'message' => 'Item não encontrado.'];
    } elseif (!$item->canViewItem()) {
        $result = ['success' => false, 'message' => 'Você não tem acesso a este item.'];
    } else {
        $result = match ($action) {
            'accept'          => plugin_botoes_accept($item),
            'pending'         => plugin_botoes_pending($item, trim((string) ($_POST['reason'] ?? ''))),
            'observer_data'   => plugin_botoes_observer_data($item),
            'observer_save'   => plugin_botoes_observer_save(
                $item,
                (int) ($_POST['group_id'] ?? 0),
                ($_POST['mode'] ?? 'add') === 'replace' ? 'replace' : 'add'
            ),
            'observer_remove' => plugin_botoes_observer_remove($item, (int) ($_POST['relation_id'] ?? 0)),
            default           => ['success' => false, 'message' => 'Ação desconhecida.'],
        };
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
