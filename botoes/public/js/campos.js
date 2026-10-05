/**
 * Botoes Plugin for GLPI 11 / 12 - Campos Adicionais e Dados do Cliente
 *
 * 1. Formulário de Chamado/Problema/Mudança: posiciona Unidade/Setor/Telefone logo
 *    abaixo da Entidade, aplica maiúsculas no setor e máscara no telefone.
 * 2. Tela de configuração: cadastro de unidades, setores e telefones por entidade.
 *
 * As requisições usam jQuery: no GLPI 11 o token CSRF é incluído automaticamente.
 */
(function () {
    'use strict';

    if (window.botoesCamposLoaded) {
        return;
    }
    window.botoesCamposLoaded = true;

    function esc(str) {
        return String(str === undefined || str === null ? '' : str).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function formatarTelefone(valor) {
        var d = String(valor || '').replace(/\D/g, '').substring(0, 11);
        if (d.length === 0) {
            return '';
        }
        if (d.length <= 2) {
            return '(' + d;
        }
        if (d.length <= 6) {
            return '(' + d.substring(0, 2) + ') ' + d.substring(2);
        }
        if (d.length <= 10) {
            // fixo: (11) 3333-4444
            return '(' + d.substring(0, 2) + ') ' + d.substring(2, 6) + '-' + d.substring(6);
        }
        // celular: (11) 99999-8888
        return '(' + d.substring(0, 2) + ') ' + d.substring(2, 7) + '-' + d.substring(7);
    }

    function aplicarMascaraTelefone(input) {
        if (!input || input.getAttribute('data-botoes-mascara') === '1') {
            return;
        }
        input.setAttribute('data-botoes-mascara', '1');
        input.value = formatarTelefone(input.value);
        input.addEventListener('input', function () {
            this.value = formatarTelefone(this.value);
        });
    }

    function aplicarMaiusculas(input) {
        if (!input || input.getAttribute('data-botoes-maiusculo') === '1') {
            return;
        }
        input.setAttribute('data-botoes-maiusculo', '1');
        input.addEventListener('input', function () {
            var pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    }

    // =========================================================================
    // 1. Formulário ITIL
    // =========================================================================

    /**
     * Campo Entidade do acordeão principal do Chamado/Problema/Mudança.
     * - item novo: o GLPI marca o campo com data-testid="form-field-entities_id";
     * - item existente: a entidade é só um badge com link para entity.form.php (sem input).
     */
    function campoEntidade() {
        var corpo = document.querySelector('#item-main .accordion-body');
        if (!corpo) {
            return null;
        }

        var alvo = corpo.querySelector('[data-testid="form-field-entities_id"]');
        if (alvo) {
            return alvo;
        }

        var select = corpo.querySelector('[name="entities_id"]');
        if (select && select.closest('.form-field')) {
            return select.closest('.form-field');
        }

        var badge = corpo.querySelector('.form-field .glpi-badge a[href*="entity.form.php"]');
        if (badge) {
            return badge.closest('.form-field');
        }

        return null;
    }

    function prepararFormulario() {
        var campos = Array.prototype.slice.call(document.querySelectorAll('.plugin-botoes-campo'));
        if (campos.length === 0) {
            return;
        }

        // O hook desenha os campos no fim do acordeão: sobem para logo abaixo da Entidade
        var referencia = campoEntidade();
        if (referencia) {
            if (referencia.nextElementSibling !== campos[0]) {
                var anterior = referencia;
                campos.forEach(function (campo) {
                    anterior.after(campo);
                    anterior = campo;
                });
            }
        } else {
            // Sem campo Entidade (instalação com uma única entidade): topo do acordeão
            var corpo = document.querySelector('#item-main .accordion-body');
            if (corpo && corpo.firstElementChild !== campos[0]) {
                campos.slice().reverse().forEach(function (campo) {
                    corpo.prepend(campo);
                });
            }
        }

        Array.prototype.slice.call(document.querySelectorAll('.plugin-botoes-maiusculo')).forEach(aplicarMaiusculas);
        Array.prototype.slice.call(document.querySelectorAll('.plugin-botoes-telefone')).forEach(aplicarMascaraTelefone);

        // Listas com pesquisa (select2 do GLPI)
        if (window.jQuery && window.jQuery.fn.select2) {
            window.jQuery('.plugin-botoes-select').each(function () {
                if (!window.jQuery(this).hasClass('select2-hidden-accessible')) {
                    window.jQuery(this).select2({width: '100%'});
                }
            });
        }
    }

    // =========================================================================
    // 2. Tela de configuração - Dados do Cliente
    // =========================================================================

    function prepararDadosCliente() {
        var raiz = document.getElementById('botoes-clientedados');
        if (!raiz || !window.jQuery) {
            return;
        }
        var $ = window.jQuery;
        var url = raiz.getAttribute('data-url');
        var $recado = $(raiz).find('.botoes-cd-recado');
        var $entidade = $(raiz).find('select[name="botoes_entidade"]');
        var entidadeAtual = -1;

        // Mesma notificação que as telas nativas usam ao salvar por ajax: glpi_toast_info
        // (cor, título, tempo e animação padrão do GLPI); erros com glpi_toast_error.
        // A faixa na página fica só como reserva.
        function recado(texto, erro) {
            if (!texto) {
                $recado.empty();
                return;
            }
            var toast;
            if (erro) {
                toast = typeof glpi_toast_error === 'function' ? glpi_toast_error : undefined;
            } else {
                toast = typeof glpi_toast_info === 'function' ? glpi_toast_info : undefined;
            }
            if (typeof toast === 'function') {
                toast(esc(texto));
                return;
            }
            $recado.html('<div class="alert ' + (erro ? 'alert-danger' : 'alert-success')
                + ' py-2 mb-3">' + esc(texto) + '</div>');
            if (!erro) {
                setTimeout(function () { $recado.empty(); }, 2500);
            }
        }

        function painel(tipo) {
            return $(raiz).find('.botoes-cd-painel[data-tipo="' + tipo + '"]');
        }

        function desenharPainel(tipo, itens) {
            var $p = painel(tipo);
            var titulo = $p.attr('data-titulo');
            var icone = $p.attr('data-icone');
            var placeholder = $p.attr('data-placeholder');
            var html = '';

            html += '<div class="card h-100">';
            html += '<div class="card-header py-2"><h4 class="card-title m-0">'
                + '<i class="' + esc(icone) + ' me-1" aria-hidden="true"></i>' + esc(titulo)
                + ' <span class="badge bg-secondary-lt ms-1">' + itens.length + '</span></h4></div>';
            html += '<div class="list-group list-group-flush botoes-cd-lista">';
            if (itens.length === 0) {
                html += '<div class="list-group-item text-muted small">Nada cadastrado nesta entidade.</div>';
            }
            itens.forEach(function (item) {
                html += '<div class="list-group-item d-flex align-items-center gap-2 py-2 botoes-cd-item"'
                    + ' data-id="' + item.id + '" data-valor="' + esc(item.valor) + '">'
                    + '<span class="flex-fill text-truncate botoes-cd-texto" title="' + esc(item.texto) + '">' + esc(item.texto) + '</span>'
                    + '<button type="button" class="btn btn-sm btn-ghost-secondary btn-icon botoes-cd-editar" title="Editar" aria-label="Editar"><i class="ti ti-pencil"></i></button>'
                    + '<button type="button" class="btn btn-sm btn-ghost-danger btn-icon botoes-cd-remover" title="Remover" aria-label="Remover"><i class="ti ti-trash"></i></button>'
                    + '</div>';
            });
            html += '</div>';
            html += '<div class="card-footer py-2"><div class="input-group input-group-sm">'
                + '<input type="text" class="form-control botoes-cd-novo" maxlength="255" placeholder="' + esc(placeholder) + '"'
                + (tipo === 'telefone' ? ' inputmode="numeric"' : '') + '>'
                + '<button type="button" class="btn btn-primary botoes-cd-adicionar"><i class="ti ti-plus me-1"></i>Adicionar</button>'
                + '</div></div>';
            html += '</div>';

            $p.html(html);
            prepararEntrada(tipo, $p.find('.botoes-cd-novo')[0]);
        }

        // Setor e unidade aceitam maiúsculas e minúsculas no cadastro; telefone recebe máscara
        function prepararEntrada(tipo, input) {
            if (tipo === 'telefone') {
                aplicarMascaraTelefone(input);
            }
        }

        function desenhar(dados) {
            ['unidade', 'setor', 'telefone'].forEach(function (tipo) {
                desenharPainel(tipo, (dados && dados[tipo]) || []);
            });
        }

        function carregar(entidade) {
            entidadeAtual = parseInt(entidade, 10);
            if (isNaN(entidadeAtual) || entidadeAtual < 0) {
                return;
            }
            recado('');
            $(raiz).find('.botoes-cd-paineis').addClass('opacity-50');
            $.ajax({
                url: url,
                method: 'GET',
                data: {acao: 'listar', entidade: entidadeAtual},
                dataType: 'json'
            }).done(function (res) {
                if (res && res.success) {
                    desenhar(res.dados);
                } else {
                    recado(res && res.message ? res.message : 'Não foi possível carregar os dados.', true);
                }
            }).fail(function () {
                recado('Falha na comunicação com o servidor.', true);
            }).always(function () {
                $(raiz).find('.botoes-cd-paineis').removeClass('opacity-50');
            });
        }

        function enviar(dados, $botao) {
            if ($botao) {
                $botao.prop('disabled', true);
            }
            dados.entidade = entidadeAtual;
            return $.ajax({
                url: url,
                method: 'POST',
                data: dados,
                dataType: 'json'
            }).done(function (res) {
                if (res && res.success) {
                    desenhar(res.dados);
                    recado(res.message || 'Dados do cliente salvos com sucesso.', false);
                } else {
                    recado(res && res.message ? res.message : 'Não foi possível salvar.', true);
                }
            }).fail(function () {
                recado('Falha na comunicação com o servidor.', true);
            }).always(function () {
                if ($botao) {
                    $botao.prop('disabled', false);
                }
            });
        }

        // Adicionar (botão ou Enter)
        $(raiz).on('click', '.botoes-cd-adicionar', function () {
            var $p = $(this).closest('.botoes-cd-painel');
            var valor = $p.find('.botoes-cd-novo').val();
            if (!String(valor || '').trim()) {
                $p.find('.botoes-cd-novo').trigger('focus');
                return;
            }
            enviar({acao: 'adicionar', tipo: $p.attr('data-tipo'), valor: valor}, $(this));
        });
        $(raiz).on('keydown', '.botoes-cd-novo', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $(this).closest('.botoes-cd-painel').find('.botoes-cd-adicionar').trigger('click');
            }
        });

        // Editar: troca o texto por um campo com Salvar / Cancelar
        $(raiz).on('click', '.botoes-cd-editar', function () {
            var $item = $(this).closest('.botoes-cd-item');
            var tipo = $item.closest('.botoes-cd-painel').attr('data-tipo');
            var atual = $item.find('.botoes-cd-texto').text();
            $item.html(
                '<div class="input-group input-group-sm flex-fill">'
                + '<input type="text" class="form-control botoes-cd-edicao" maxlength="255" value="' + esc(atual) + '">'
                + '<button type="button" class="btn btn-success botoes-cd-salvar" title="Salvar" aria-label="Salvar"><i class="ti ti-check"></i></button>'
                + '<button type="button" class="btn btn-secondary botoes-cd-cancelar" title="Cancelar" aria-label="Cancelar"><i class="ti ti-x"></i></button>'
                + '</div>'
            );
            var input = $item.find('.botoes-cd-edicao')[0];
            prepararEntrada(tipo, input);
            input.focus();
        });
        $(raiz).on('click', '.botoes-cd-salvar', function () {
            var $item = $(this).closest('.botoes-cd-item');
            enviar({acao: 'atualizar', id: $item.attr('data-id'), valor: $item.find('.botoes-cd-edicao').val()}, $(this));
        });
        $(raiz).on('keydown', '.botoes-cd-edicao', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $(this).closest('.botoes-cd-item').find('.botoes-cd-salvar').trigger('click');
            } else if (e.key === 'Escape') {
                carregar(entidadeAtual);
            }
        });
        $(raiz).on('click', '.botoes-cd-cancelar', function () {
            carregar(entidadeAtual);
        });

        // Remover: pede um segundo clique para confirmar
        $(raiz).on('click', '.botoes-cd-remover', function () {
            var $botao = $(this);
            if ($botao.attr('data-confirmar') !== '1') {
                $botao.attr('data-confirmar', '1')
                    .removeClass('btn-ghost-danger btn-icon').addClass('btn-danger')
                    .html('Confirmar?');
                setTimeout(function () {
                    if ($botao.closest('body').length && $botao.attr('data-confirmar') === '1') {
                        $botao.attr('data-confirmar', '0')
                            .removeClass('btn-danger').addClass('btn-ghost-danger btn-icon')
                            .html('<i class="ti ti-trash"></i>');
                    }
                }, 3000);
                return;
            }
            enviar({acao: 'remover', id: $botao.closest('.botoes-cd-item').attr('data-id')}, $botao);
        });

        // Troca de entidade (select2 dispara o change do jQuery)
        $entidade.on('change', function () {
            carregar($(this).val());
        });

        carregar($entidade.val());
    }

    // =========================================================================
    // 3. Listas: título das colunas sem o prefixo "Plug-ins - "
    // =========================================================================

    // O GLPI prefixa toda opção de pesquisa de plugin com o grupo "Plug-ins" e não
    // oferece hook para o cabeçalho. IDs definidos em plugin_botoes_getAddSearchOptionsNew().
    var TITULOS_COLUNAS = {'15101': 'Unidade', '15102': 'Setor', '15103': 'Telefone'};
    var SELETOR_COLUNAS = Object.keys(TITULOS_COLUNAS).map(function (id) {
        return 'th[data-searchopt-id="' + id + '"]:not([data-botoes-titulo])';
    }).join(',');

    function ajustarTitulosColunas() {
        Array.prototype.slice.call(document.querySelectorAll(SELETOR_COLUNAS)).forEach(function (th) {
            var titulo = TITULOS_COLUNAS[th.getAttribute('data-searchopt-id')];
            for (var i = 0; i < th.childNodes.length; i++) {
                var no = th.childNodes[i];
                if (no.nodeType === 3 && no.nodeValue.trim() !== '') {
                    no.nodeValue = ' ' + titulo + ' ';
                    th.setAttribute('data-botoes-titulo', '1');
                    return;
                }
            }
        });
    }

    /**
     * O conteúdo do chamado (aba principal) e as listas chegam por ajax depois do
     * carregamento da página: reaplica os ajustes sempre que o DOM muda.
     * Todas as funções são idempotentes.
     */
    function observarPagina() {
        var agendado = false;
        new MutationObserver(function () {
            if (agendado) {
                return;
            }
            agendado = true;
            window.requestAnimationFrame(function () {
                agendado = false;
                prepararFormulario();
                ajustarTitulosColunas();
            });
        }).observe(document.body, {childList: true, subtree: true});
    }

    function iniciar() {
        prepararFormulario();
        prepararDadosCliente();
        ajustarTitulosColunas();
        observarPagina();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
