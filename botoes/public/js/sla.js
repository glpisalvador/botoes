/**
 * Botoes Plugin for GLPI 11 / 12 - Barras de SLA (vindo do plugin barrasdesla)
 *
 * Lista de chamados: renomeia colunas (SLA de atendimento/solução, requerente, técnico),
 * simplifica o status "Em atendimento (atribuído)" e desenha barras de progresso de SLA.
 * Formulário do chamado: troca o bloco nativo de níveis de serviço por duas barras.
 */
(function () {

    if (window.botoesSlaLoaded) {
        return;
    }
    window.botoesSlaLoaded = true;

    const SLA_URL = CFG_GLPI.root_doc + '/plugins/botoes/ajax/sla.php';

    const path = window.location.pathname;

    const isTicketList = path.includes('/front/ticket.php') ||
                         path.includes('/ajax/search.php');

    const isTicketForm = path.includes('/front/ticket.form.php');

    if (!isTicketList && !isTicketForm) {
        return;
    }

    // =========================================================
    // LISTA DE TICKETS
    // =========================================================
    function forceSLAProgressBars() {

        $('table.search-results thead th, table thead th').each(function () {
            const $th = $(this);
            let text = $th.text().toLowerCase().replace(/\s+/g, ' ').trim();

            if (text.includes('tempo para aceitar') || text.includes('time to own') ||
                text.includes('sla de atendimento') || text.includes('sla atend')) {
                $th.contents().filter(function () { return this.nodeType === 3; }).first()
                   .replaceWith('SLA DE ATENDIMENTO');
            }

            if (text.includes('tempo para solução') || text.includes('tempo para solucao') ||
                text.includes('time to resolve') || text.includes('sla de solução') ||
                text.includes('sla de solucao')) {
                $th.contents().filter(function () { return this.nodeType === 3; }).first()
                   .replaceWith('SLA DE SOLUÇÃO');
            }

            if (text.includes('requerente') && text.includes('-')) {
                $th.contents().filter(function () { return this.nodeType === 3; }).first()
                   .replaceWith('REQUERENTE');
            }

            if ((text.includes('atribuído') || text.includes('atribuido') || text.includes('assigned')) &&
                (text.includes('técnico') || text.includes('tecnico') || text.includes('technician'))) {
                $th.contents().filter(function () { return this.nodeType === 3; }).first()
                   .replaceWith('TÉCNICO');
            }
        });

        let ttoIndex = -1, ttrIndex = -1, statusIndex = -1, idIndex = -1;

        $('table.search-results thead th, table thead th').each(function (i) {
            const h = $(this).text().toLowerCase().replace(/\s+/g, ' ').trim();

            if (h.includes('sla de atendimento') || h.includes('tempo para aceitar') || h.includes('time to own')) {
                ttoIndex = i;
            }
            if (h.includes('sla de solução') || h.includes('sla de solucao') ||
                h.includes('tempo para solução') || h.includes('time to resolve')) {
                ttrIndex = i;
            }
            if (h.includes('status')) {
                statusIndex = i;
            }
            if (h === 'id' || h.trim() === 'id') {
                idIndex = i;
            }
        });

        if (ttoIndex === -1 && ttrIndex === -1) return;

        $('table.search-results tbody tr, table tbody tr').each(function () {
            const $row = $(this);
            const $cells = $row.find('td');

            let ticketId = null;

            if (idIndex !== -1) {
                ticketId = parseInt($cells.eq(idIndex).text().trim(), 10);
            }

            if (!ticketId) {
                const href = $row.find('a[href*="id="]').first().attr('href') || '';
                const m = href.match(/id=(\d+)/);
                if (m) ticketId = parseInt(m[1], 10);
            }

            if (!ticketId) return;

            if (statusIndex !== -1) {
                const $statusCell = $cells.eq(statusIndex);
                $statusCell.find('*').addBack().contents().filter(function () {
                    return this.nodeType === 3;
                }).each(function () {
                    this.nodeValue = this.nodeValue.replace(
                        /Em atendimento\s*\(atribu[íi]do\)/gi,
                        'Em atendimento'
                    );
                });
            }

            function processColumn(index, type) {
                if (index === -1 || $cells.length <= index) return;

                const $td = $cells.eq(index);

                if ($td.find('.force-sla').length > 0) return;

                if ($td.find('.progress').length > 0) {
                    $td.find('.progress').css({
                        height: '16px',
                        width: '110px',
                        minWidth: '110px',
                        marginTop: '3px',
                        display: 'block'
                    });
                    return;
                }

                if ($td.data('sla-loaded-' + type)) return;
                $td.data('sla-loaded-' + type, true);

                $td.html('<span style="color:#999;font-size:11px;">...</span>');

                $.get(SLA_URL, {
                    ticket_id: ticketId,
                    type: type
                }).done(function (resp) {
                    try {
                        const data = (typeof resp === 'string') ? JSON.parse(resp) : resp;

                        if (data.success && data.date) {
                            $td.html(`
                                <div class="force-sla" style="line-height:1.2;">
                                    <span style="font-size:12px;">${data.date}</span>
                                    <div class="progress" style="height:16px;width:110px;min-width:110px;margin-top:3px;display:block;">
                                        <div class="progress-bar" style="width:${data.percent}%;background-color:${data.color};font-size:11px;line-height:16px;">
                                            ${data.percent}%
                                        </div>
                                    </div>
                                </div>
                            `);
                        } else {
                            $td.html(`
                                <div class="force-sla" style="line-height:1.2;">
                                    <span style="font-size:12px;color:#666;">—</span>
                                    <div class="progress" style="height:16px;width:110px;min-width:110px;margin-top:3px;">
                                        <div class="progress-bar" style="width:100%;background:#AAAAAA;font-size:11px;line-height:16px;">
                                            —
                                        </div>
                                    </div>
                                </div>
                            `);
                        }
                    } catch (e) {
                        $td.html('—');
                    }
                }).fail(function () {
                    $td.html('—');
                });
            }

            processColumn(ttoIndex, 'tto');
            processColumn(ttrIndex, 'ttr');
        });
    }

    // =========================================================
    // FORMULÁRIO – esconde nativo + mostra só as duas barras
    // =========================================================
    let formDone = false;

    function forceSLAProgressBarsOnForm() {

        if (!isTicketForm || formDone) return;

        const urlParams = new URLSearchParams(window.location.search);
        let ticketId = parseInt(urlParams.get('id'), 10);

        if (!ticketId || ticketId <= 0) {
            const $idField = $('input[name="id"], input[name="tickets_id"]').first();
            if ($idField.length) {
                ticketId = parseInt($idField.val(), 10);
            }
        }

        if (!ticketId || ticketId <= 0) return;

        const $accordionBody = $('#service-levels .accordion-body');
        if (!$accordionBody.length) return;

        // Já processou?
        if ($accordionBody.find('.force-sla-custom').length > 0) {
            // Garante que o nativo continue escondido
            $accordionBody.children().not('.force-sla-custom').hide();
            formDone = true;
            return;
        }

        // ========== ESCONDE TUDO QUE É NATIVO ==========
        $accordionBody.children().hide();

        // Cria o novo conteúdo limpo
        const $container = $(`
            <div class="force-sla-custom p-2">
                <div class="mb-3" id="force-sla-tto">
                    <div style="font-size:13px;font-weight:600;margin-bottom:4px;color:#333;">
                        Tempo para atendimento
                    </div>
                    <div class="force-sla-content">
                        <span style="font-size:12px;color:#999;">carregando...</span>
                    </div>
                </div>
                <div class="mb-1" id="force-sla-ttr">
                    <div style="font-size:13px;font-weight:600;margin-bottom:4px;color:#333;">
                        Tempo para solução
                    </div>
                    <div class="force-sla-content">
                        <span style="font-size:12px;color:#999;">carregando...</span>
                    </div>
                </div>
            </div>
        `);

        $accordionBody.append($container);

        // Força esconder qualquer coisa que ainda esteja visível
        $accordionBody.children().not('.force-sla-custom').css({
            'display': 'none !important',
            'visibility': 'hidden',
            'height': '0',
            'overflow': 'hidden',
            'margin': '0',
            'padding': '0'
        }).hide();

        formDone = true;

        // Função para preencher as barras
        function fillBar(type, $target) {
            $.get(SLA_URL, {
                ticket_id: ticketId,
                type: type
            }).done(function (resp) {
                try {
                    const data = (typeof resp === 'string') ? JSON.parse(resp) : resp;

                    if (data.success && data.date) {
                        $target.html(`
                            <span style="font-size:12px;">${data.date}</span>
                            <div class="progress" style="height:18px;width:140px;min-width:140px;margin-top:4px;display:block;">
                                <div class="progress-bar" style="width:${data.percent}%;background-color:${data.color};font-size:11px;line-height:18px;">
                                    ${data.percent}%
                                </div>
                            </div>
                        `);
                    } else {
                        $target.html(`
                            <span style="font-size:12px;color:#666;">—</span>
                            <div class="progress" style="height:18px;width:140px;min-width:140px;margin-top:4px;">
                                <div class="progress-bar" style="width:100%;background:#AAAAAA;font-size:11px;line-height:18px;">
                                    —
                                </div>
                            </div>
                        `);
                    }
                } catch (e) {
                    $target.html('<span style="color:#999;">—</span>');
                }
            }).fail(function () {
                $target.html('<span style="color:#999;">—</span>');
            });
        }

        fillBar('tto', $('#force-sla-tto .force-sla-content'));
        fillBar('ttr', $('#force-sla-ttr .force-sla-content'));
    }

    // =========================================================
    // EXECUÇÃO
    // =========================================================
    function runList() {
        if (isTicketList) forceSLAProgressBars();
    }

    function runForm() {
        if (isTicketForm) {
            forceSLAProgressBarsOnForm();

            // Garante que o nativo continue escondido mesmo se o GLPI re-renderizar
            const $body = $('#service-levels .accordion-body');
            if ($body.length && $body.find('.force-sla-custom').length) {
                $body.children().not('.force-sla-custom').hide();
            }
        }
    }

    $(document).ready(function () {
        runList();
        setTimeout(runForm, 300);
        setTimeout(runForm, 800);
        setTimeout(runForm, 1600);
    });

    // Observer só na lista
    if (isTicketList) {
        const observer = new MutationObserver(function () {
            setTimeout(runList, 300);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    // No formulário: observa o acordeão para manter o nativo escondido
    if (isTicketForm) {
        const formObserver = new MutationObserver(function () {
            const $body = $('#service-levels .accordion-body');
            if ($body.length && $body.find('.force-sla-custom').length) {
                $body.children().not('.force-sla-custom').hide();
            } else if ($body.length && !formDone) {
                runForm();
            }
        });
        formObserver.observe(document.body, { childList: true, subtree: true });
    }

    $(document).on('shown.bs.collapse', '#service-levels', function () {
        setTimeout(runForm, 100);
    });

    $(document).on('click', 'table thead th', function () {
        setTimeout(runList, 500);
        setTimeout(runList, 1100);
    });

})();