<?php

/**
 * -------------------------------------------------------------------------
 * Botoes Plugin for GLPI - Barras de SLA (vindo do plugin barrasdesla)
 * -------------------------------------------------------------------------
 * Devolve data limite, percentual e cor da barra de SLA de atendimento (tto)
 * ou de solução (ttr) de um chamado.
 * O bootstrap do GLPI (sessão, autoload, CSRF) é feito pelo kernel antes deste arquivo.
 */

header('Content-Type: application/json; charset=UTF-8');

Session::checkLoginUser();

/**
 * @return array{success:bool, date?:string, percent?:int, color?:string}
 */
function plugin_botoes_sla(int $ticket_id, bool $is_tto): array
{
    $ticket = new Ticket();
    if ($ticket_id <= 0 || !$ticket->getFromDB($ticket_id) || !$ticket->canViewItem()) {
        return ['success' => false];
    }

    $data_limite = $ticket->fields[$is_tto ? 'time_to_own' : 'time_to_resolve'] ?? null;
    $status      = (int) ($ticket->fields['status'] ?? 0);
    $abertura    = $ticket->fields['date'] ?? null;

    if (empty($data_limite) || $data_limite === '0000-00-00 00:00:00' || empty($abertura)) {
        return ['success' => false];
    }

    // Data real de aceite do chamado
    $data_aceite = null;
    if (
        !empty($ticket->fields['takeintoaccountdate'])
        && $ticket->fields['takeintoaccountdate'] !== '0000-00-00 00:00:00'
    ) {
        $data_aceite = $ticket->fields['takeintoaccountdate'];
    } elseif ((int) ($ticket->fields['takeintoaccount_delay_stat'] ?? 0) > 0) {
        // Chamados antigos: segundos entre a abertura e o aceite
        $data_aceite = date('Y-m-d H:i:s', strtotime($abertura) + (int) $ticket->fields['takeintoaccount_delay_stat']);
    }

    $abertura_ts = strtotime($abertura);
    $limite_ts   = strtotime($data_limite);
    $agora_ts    = time();

    // SLA de atendimento congela no aceite; o de solução conta sempre até agora
    $fim_ts = ($is_tto && $data_aceite) ? strtotime($data_aceite) : $agora_ts;

    $total     = $limite_ts - $abertura_ts;
    $decorrido = $fim_ts - $abertura_ts;

    if ($total > 0) {
        $percentual = (int) min(100, max(0, round(($decorrido / $total) * 100)));
    } else {
        $percentual = ($fim_ts >= $limite_ts) ? 100 : 0;
    }

    // Prazo vencido (no SLA de atendimento, só enquanto não houve aceite)
    if ((!$is_tto || !$data_aceite) && $agora_ts >= $limite_ts) {
        $percentual = 100;
    }

    if ($percentual >= 100) {
        // Vermelho: mesma cor "crítica" das barras nativas do GLPI (preferência do usuário)
        $cor = $_SESSION['glpiduedatecritical_color'] ?? '#ff0000';
    } elseif ($percentual >= 75) {
        $cor = '#f1c40f'; // amarelo
    } else {
        $cor = '#3bc459'; // verde
    }

    // Pendente + SLA de atendimento ainda no prazo: laranja
    if ($is_tto && $status === CommonITILObject::WAITING && $percentual < 100) {
        $cor = '#f0ad4e';
    }

    return [
        'success' => true,
        'date'    => (string) Html::convDateTime($data_limite),
        'percent' => $percentual,
        'color'   => $cor,
    ];
}

echo json_encode(
    plugin_botoes_sla((int) ($_GET['ticket_id'] ?? 0), ($_GET['type'] ?? 'ttr') === 'tto'),
    JSON_UNESCAPED_UNICODE
);
