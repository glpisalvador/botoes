/**
 * Botoes Plugin for GLPI 11 / 12
 * Os botões são renderizados pelo hook PHP (timeline_actions) em Chamados, Problemas e Mudanças.
 * Este script apenas trata os cliques e cria as janelas modais sob demanda.
 */
(function () {
    'use strict';

    if (window.botoesPluginLoaded) {
        return;
    }
    window.botoesPluginLoaded = true;

    const AJAX_URL = ((typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : '')
        + '/plugins/botoes/ajax/actions.php';

    function esc(str) {
        return String(str ?? '').replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function notifyError(message) {
        if (typeof glpi_toast_error === 'function') {
            glpi_toast_error(esc(message));
        } else {
            window.alert(message);
        }
    }

    function spinner(label) {
        return '<span class="spinner-border spinner-border-sm me-1" role="status"></span> ' + label;
    }

    /**
     * @param {{itemtype: string, items_id: number}} target
     * @param {Object} data
     */
    function request(target, data) {
        return $.ajax({
            url: AJAX_URL,
            method: data.action === 'observer_data' ? 'GET' : 'POST',
            data: Object.assign({itemtype: target.itemtype, items_id: target.items_id}, data),
            dataType: 'json'
        });
    }

    /**
     * Cria (uma única vez) e exibe uma modal Bootstrap 5.
     */
    function showModal(id, title, body, footer) {
        let el = document.getElementById(id);
        if (!el) {
            $('body').append(`
                <div class="modal fade botoes-modal" id="${id}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">${title}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                            </div>
                            <div class="modal-body">${body}</div>
                            <div class="modal-footer">${footer}</div>
                        </div>
                    </div>
                </div>`);
            el = document.getElementById(id);
        }
        bootstrap.Modal.getOrCreateInstance(el).show();
        return $(el);
    }

    function submitAction($btn, target, data, busyLabel) {
        const original = $btn.html();
        $btn.prop('disabled', true).html(busyLabel ? spinner(busyLabel) : '<span class="spinner-border spinner-border-sm" role="status"></span>');
        request(target, data).done(function (res) {
            if (res && res.success) {
                window.location.reload();
                return;
            }
            notifyError(res && res.message ? res.message : 'Não foi possível concluir a ação.');
            $btn.prop('disabled', false).html(original);
        }).fail(function () {
            notifyError('Falha na comunicação com o servidor.');
            $btn.prop('disabled', false).html(original);
        });
    }

    // -------------------------------------------------------------------------
    // Aceitar: executa direto, sem confirmação
    // -------------------------------------------------------------------------
    function runAccept($btn, target) {
        if ($btn.prop('disabled')) {
            return;
        }
        submitAction($btn, target, {action: 'accept'}, '');
    }

    // -------------------------------------------------------------------------
    // Pendente
    // -------------------------------------------------------------------------
    function openPending(target) {
        const $modal = showModal(
            'botoes-modal-pending',
            '<i class="ti ti-clock-pause text-warning me-2"></i>Colocar em pendente',
            '<p class="mb-2">Alterar o status para <strong>Pendente</strong>?</p>'
            + '<label for="botoes-pending-reason" class="form-label small text-muted">Motivo (opcional, será incluído no acompanhamento)</label>'
            + '<textarea class="form-control" id="botoes-pending-reason" rows="3" placeholder="Ex.: aguardando retorno do solicitante"></textarea>',
            '<button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">Cancelar</button>'
            + '<button type="button" class="btn btn-warning botoes-confirm"><i class="ti ti-clock-pause me-1"></i>Confirmar</button>'
        );
        $modal.data('target', target);
        $('#botoes-pending-reason').val('');
    }

    $(document).on('click', '#botoes-modal-pending .botoes-confirm', function () {
        submitAction($(this), $('#botoes-modal-pending').data('target'), {
            action: 'pending',
            reason: $('#botoes-pending-reason').val()
        }, 'Processando...');
    });

    // -------------------------------------------------------------------------
    // Grupo observador
    // -------------------------------------------------------------------------
    function renderCurrentObservers(list) {
        const $box = $('#botoes-observer-current');
        if (!list.length) {
            $box.html('<span class="text-muted small">Nenhum grupo observador.</span>');
            return;
        }
        $box.html(list.map(function (g) {
            return `<span class="botoes-badge" data-relation-id="${g.relation_id}">
                        <i class="ti ti-users-group"></i>${esc(g.name)}
                        <button type="button" class="botoes-badge-remove" title="Remover" aria-label="Remover">
                            <i class="ti ti-x"></i>
                        </button>
                    </span>`;
        }).join(''));
    }

    function openObserver(target) {
        const $modal = showModal(
            'botoes-modal-observer',
            '<i class="ti ti-users-group text-primary me-2"></i>Grupo observador',
            '<label class="form-label small text-muted">Grupos observadores atuais</label>'
            + '<div id="botoes-observer-current" class="botoes-observer-current mb-3"></div>'
            + '<label for="botoes-observer-group" class="form-label">Grupo</label>'
            + '<select class="form-select mb-3" id="botoes-observer-group"></select>'
            + '<div class="form-check">'
            + '  <input class="form-check-input" type="radio" name="botoes_observer_mode" id="botoes-mode-add" value="add" checked>'
            + '  <label class="form-check-label" for="botoes-mode-add">Adicionar aos observadores atuais</label>'
            + '</div>'
            + '<div class="form-check">'
            + '  <input class="form-check-input" type="radio" name="botoes_observer_mode" id="botoes-mode-replace" value="replace">'
            + '  <label class="form-check-label" for="botoes-mode-replace">Substituir os observadores atuais</label>'
            + '</div>',
            '<button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">Fechar</button>'
            + '<button type="button" class="btn btn-primary botoes-confirm"><i class="ti ti-device-floppy me-1"></i>Salvar</button>'
        );
        $modal.data('target', target).data('changed', false);
        $('#botoes-mode-add').prop('checked', true);

        const $confirm = $modal.find('.botoes-confirm').prop('disabled', true);
        const $select = $('#botoes-observer-group').prop('disabled', true)
            .html('<option value="">Carregando...</option>');
        $('#botoes-observer-current').html(spinner('Carregando...'));

        request(target, {action: 'observer_data'}).done(function (res) {
            if (!res || !res.success) {
                $('#botoes-observer-current').html('<span class="text-danger small">'
                    + esc(res && res.message ? res.message : 'Erro ao carregar grupos.') + '</span>');
                return;
            }
            renderCurrentObservers(res.current || []);

            let options = '<option value="">-- Selecione um grupo --</option>';
            (res.available || []).forEach(function (g) {
                options += `<option value="${g.id}">${esc(g.name)}</option>`;
            });
            $select.html(options).prop('disabled', !res.can_update);
            $confirm.prop('disabled', !res.can_update);
        }).fail(function () {
            $('#botoes-observer-current').html('<span class="text-danger small">Falha na comunicação com o servidor.</span>');
        });
    }

    $(document).on('click', '#botoes-modal-observer .botoes-confirm', function () {
        const groupId = parseInt($('#botoes-observer-group').val(), 10);
        if (!groupId) {
            notifyError('Selecione um grupo.');
            return;
        }
        submitAction($(this), $('#botoes-modal-observer').data('target'), {
            action: 'observer_save',
            group_id: groupId,
            mode: $('input[name="botoes_observer_mode"]:checked').val() || 'add'
        }, 'Salvando...');
    });

    $(document).on('click', '#botoes-modal-observer .botoes-badge-remove', function () {
        const $badge = $(this).closest('.botoes-badge');
        $badge.css('opacity', 0.4);
        request($('#botoes-modal-observer').data('target'), {
            action: 'observer_remove',
            relation_id: $badge.data('relation-id')
        }).done(function (res) {
            if (res && res.success) {
                $badge.remove();
                if (!$('#botoes-observer-current .botoes-badge').length) {
                    renderCurrentObservers([]);
                }
                $('#botoes-modal-observer').data('changed', true);
            } else {
                notifyError(res && res.message ? res.message : 'Erro ao remover grupo.');
                $badge.css('opacity', 1);
            }
        }).fail(function () {
            notifyError('Falha na comunicação com o servidor.');
            $badge.css('opacity', 1);
        });
    });

    // Recarrega a página ao fechar a modal se algum grupo foi removido
    $(document).on('hidden.bs.modal', '#botoes-modal-observer', function () {
        if ($(this).data('changed')) {
            window.location.reload();
        }
    });

    // Em larguras menores os rótulos nativos ficam ocultos (só ícone): garante a dica no hover
    $(document).on('mouseenter', '#itil-footer .main-actions > .btn', function () {
        if (!this.title) {
            const label = $(this).children('span:not(.visually-hidden)').first().text().trim()
                || this.getAttribute('aria-label') || '';
            if (label) {
                this.title = label;
            }
        }
    });

    // -------------------------------------------------------------------------
    // Clique nos botões da barra (Chamado, Problema ou Mudança)
    // -------------------------------------------------------------------------
    $(document).on('click', '.botoes-btn[data-botoes-action]', function (e) {
        e.preventDefault();
        const target = {
            itemtype: String($(this).data('itemtype') || ''),
            items_id: parseInt($(this).data('items-id'), 10)
        };
        if (!target.itemtype || !target.items_id) {
            return;
        }
        switch ($(this).data('botoes-action')) {
            case 'accept':
                runAccept($(this), target);
                break;
            case 'pending':
                openPending(target);
                break;
            case 'observer':
                openObserver(target);
                break;
        }
    });
})();
