/**
 * Botoes Plugin for GLPI 11 / 12
 * Ao enviar um acompanhamento novo, abre o aviso "Público ou Privado?" e grava conforme a escolha.
 * Só aparece para quem pode marcar o acompanhamento como privado (o formulário tem a opção).
 */
(function () {
    'use strict';

    if (window.botoesAcompanhamentoLoaded) {
        return;
    }
    window.botoesAcompanhamentoLoaded = true;

    const MODAL_ID = 'botoes-modal-privacidade';

    /** O botoesadicionais também pode fazer a mesma pergunta: nesse caso ele cuida disso */
    function outroPluginPergunta() {
        const ba = window.botoesadicionais;
        if (!ba || typeof ba.dados !== 'function') {
            return false;
        }
        const d = ba.dados();
        return !!(d && d.followup);
    }

    function montarModal() {
        let el = document.getElementById(MODAL_ID);
        if (el) {
            return el;
        }
        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal fade botoes-modal" id="${MODAL_ID}" tabindex="-1" aria-hidden="true" aria-labelledby="${MODAL_ID}-titulo">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="${MODAL_ID}-titulo"><i class="ti ti-eye me-2" aria-hidden="true"></i>Visibilidade do acompanhamento</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="botoes-priv-pergunta"><i class="ti ti-info-circle" aria-hidden="true"></i> Quem deve ver este acompanhamento?</p>
                            <div class="botoes-priv-escolhas">
                                <button type="button" class="botoes-priv-escolha botoes-priv-publico" data-privado="0">
                                    <i class="ti ti-world" aria-hidden="true"></i>
                                    <strong>Público</strong>
                                    <span>Para ciência do cliente: aparece para o requerente.</span>
                                </button>
                                <button type="button" class="botoes-priv-escolha botoes-priv-privado" data-privado="1">
                                    <i class="ti ti-lock" aria-hidden="true"></i>
                                    <strong>Privado</strong>
                                    <span>Somente a equipe técnica vê.</span>
                                </button>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        </div>
                    </div>
                </div>
            </div>`);
        return document.getElementById(MODAL_ID);
    }

    function enviar(form, submitter) {
        form.dataset.botoesPrivacidade = '1';
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
        } else if (submitter) {
            submitter.click();
        } else {
            form.submit();
        }
        // Se o envio não recarregar a página, o próximo acompanhamento volta a perguntar
        setTimeout(function () { delete form.dataset.botoesPrivacidade; }, 1500);
    }

    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || form.dataset.botoesPrivacidade === '1' || outroPluginPergunta()) {
            return;
        }
        if ((form.getAttribute('action') || '').indexOf('itilfollowup.form.php') < 0) {
            return;
        }
        // Só para acompanhamentos novos (editar mantém a escolha já feita)
        const submitter = e.submitter || form.querySelector('button[name="add"], input[name="add"]');
        if (!submitter || submitter.name !== 'add') {
            return;
        }
        const check = form.querySelector('input[type="checkbox"][name="is_private"]');
        if (!check) {
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();

        const el = montarModal();
        const modal = bootstrap.Modal.getOrCreateInstance(el);
        el.querySelectorAll('[data-privado]').forEach(function (b) {
            b.classList.toggle('botoes-priv-atual', (b.dataset.privado === '1') === check.checked);
            b.onclick = function () {
                check.checked = b.dataset.privado === '1';
                check.dispatchEvent(new Event('change', {bubbles: true}));
                modal.hide();
                enviar(form, submitter);
            };
        });
        modal.show();
    }, true);
})();
