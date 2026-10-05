/**
 * Botoes Plugin for GLPI 11 / 12
 * Status e atores (requerente, observador, atribuído) somente leitura em Chamados, Problemas e Mudanças já abertos.
 * O servidor também descarta essas mudanças; aqui os campos só ficam visivelmente travados.
 */
(function () {
    'use strict';

    if (window.botoesSomenteLeituraLoaded) {
        return;
    }
    window.botoesSomenteLeituraLoaded = true;

    const DICA = 'Somente leitura: use os botões Aceitar, Pendente e Grupo Observador';

    /** Só no formulário de um item já existente */
    function itemAberto() {
        if (!/\/front\/(ticket|problem|change)\.form\.php$/.test(window.location.pathname)) {
            return false;
        }
        const id = parseInt(new URLSearchParams(window.location.search).get('id') || '0', 10);
        return id > 0;
    }

    function travar(select) {
        if (select.dataset.botoesSomenteLeitura === '1' || select.closest('.modal')) {
            return;
        }
        select.dataset.botoesSomenteLeitura = '1';
        select.disabled = true;
        select.setAttribute('title', DICA);
        if (window.jQuery) {
            window.jQuery(select).trigger('change.select2');
        }
        const container = select.nextElementSibling;
        if (container && container.classList.contains('select2-container')) {
            container.classList.add('botoes-somente-leitura');
            container.setAttribute('title', DICA);
        }
        const bloco = select.closest('.form-field, .actor-field, .field-container');
        if (bloco) {
            bloco.classList.add('botoes-somente-leitura-bloco');
        }
    }

    function aplicar() {
        if (!itemAberto()) {
            return;
        }
        // Status só do formulário principal (a timeline tem outros campos "status", como o de aprovação)
        document.querySelectorAll('#itil-form select[name="status"], select[name="status"][form="itil-form"], select[data-actor-type="requester"], select[data-actor-type="observer"], select[data-actor-type="assign"]').forEach(travar);
        // Botões "me atribuir" / "me adicionar como ator"
        document.querySelectorAll('button[form^="addme_as_"]').forEach(function (b) {
            b.classList.add('botoes-oculto');
            b.setAttribute('aria-hidden', 'true');
            b.tabIndex = -1;
        });
    }

    let espera = null;
    function agendar() {
        clearTimeout(espera);
        espera = setTimeout(aplicar, 150);
    }

    function iniciar() {
        if (!itemAberto()) {
            return;
        }
        aplicar();
        // O GLPI recarrega partes do formulário via AJAX: reaplica quando o DOM muda
        new MutationObserver(agendar).observe(document.body, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
