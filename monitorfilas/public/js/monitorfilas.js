/**
 * Plugin Monitor de Filas - painel: atualização automática sem recarregar a página
 * (pausa com a aba oculta), filtro de grupos, rankings sob demanda, tela cheia e multiselect.
 */
(function () {
    'use strict';

    if (window.monitorfilasJs) { return; }
    window.monitorfilasJs = true;

    var CHAVE_FILTRO = 'monitorfilas-grupos';

    function lerJson(r) {
        return r.text().then(function (t) {
            try { return JSON.parse(t); } catch (e) {
                var m = t.match(/\{[\s\S]*\}\s*$/);
                if (m) { try { return JSON.parse(m[0]); } catch (e2) { /* segue */ } }
                return { success: false, mensagem: 'Resposta inválida do servidor.' };
            }
        });
    }

    function aviso(msg) {
        if (typeof window.glpi_toast_error === 'function') { window.glpi_toast_error(msg); }
    }

    // ------------------------------------------------------------------ multiselect
    function opcoes(c) { return Array.prototype.slice.call(c.querySelectorAll('.monitorfilas-ms-opcao')); }

    function monitorfilasUpdateSelectAll(c) {
        var todos = c.querySelector('[data-monitorfilas-ms-todos]');
        if (!todos) { return; }
        var vis = opcoes(c).filter(function (o) { return o.style.display !== 'none'; });
        var marc = vis.filter(function (o) { return o.querySelector('input').checked; });
        todos.checked = vis.length > 0 && marc.length === vis.length;
        todos.indeterminate = marc.length > 0 && marc.length < vis.length;
    }

    function monitorfilasUpdateMultiselectCount(c) {
        var marc = opcoes(c).filter(function (o) { return o.querySelector('input').checked; });
        var texto = c.querySelector('.monitorfilas-ms-texto');
        if (!marc.length) {
            texto.textContent = c.getAttribute('data-placeholder') || 'Selecione...';
            texto.classList.add('monitorfilas-ms-vazio');
        } else {
            texto.textContent = marc.length <= 2 ? marc.map(function (o) { return o.querySelector('span').textContent; }).join(', ') : marc.length + ' selecionados';
            texto.classList.remove('monitorfilas-ms-vazio');
        }
        c.querySelector('.monitorfilas-ms-contador').textContent = marc.length + ' de ' + opcoes(c).length + ' selecionado(s)';
        monitorfilasUpdateSelectAll(c);
    }

    function monitorfilasReorderMultiselectOptions(c) {
        var lista = c.querySelector('.monitorfilas-ms-opcoes');
        opcoes(c).sort(function (a, b) {
            var ca = a.querySelector('input').checked, cb = b.querySelector('input').checked;
            if (ca !== cb) { return ca ? -1 : 1; }
            return a.getAttribute('data-label').localeCompare(b.getAttribute('data-label'), 'pt-BR');
        }).forEach(function (o) { lista.appendChild(o); });
    }

    function monitorfilasFilterMultiselect(c, termo) {
        termo = (termo || '').toLowerCase().trim();
        opcoes(c).forEach(function (o) { o.style.display = !termo || o.getAttribute('data-label').indexOf(termo) !== -1 ? 'flex' : 'none'; });
        monitorfilasUpdateSelectAll(c);
    }

    function fechar(c) {
        c.querySelector('.monitorfilas-ms-dropdown').hidden = true;
        c.classList.remove('monitorfilas-ms-aberto');
    }

    function monitorfilasToggleMultiselect(c) {
        var dd = c.querySelector('.monitorfilas-ms-dropdown');
        var abrir = dd.hidden;
        document.querySelectorAll('[data-monitorfilas-ms]').forEach(function (o) { if (o !== c) { fechar(o); } });
        dd.hidden = !abrir;
        c.classList.toggle('monitorfilas-ms-aberto', abrir);
        if (abrir) { c.querySelector('.monitorfilas-ms-busca').focus(); }
    }

    function monitorfilasToggleAllMultiselect(c, marcar) {
        opcoes(c).forEach(function (o) {
            if (o.style.display === 'none') { return; }
            o.querySelector('input').checked = marcar;
            o.classList.toggle('selected', marcar);
        });
        monitorfilasReorderMultiselectOptions(c);
        monitorfilasUpdateMultiselectCount(c);
    }

    function monitorfilasHandleMultiselectChange(c, opcao) {
        opcao.classList.toggle('selected', opcao.querySelector('input').checked);
        var busca = c.querySelector('.monitorfilas-ms-busca');
        if (busca.value !== '') {
            busca.value = '';
            monitorfilasFilterMultiselect(c, '');
            busca.focus();
        }
        monitorfilasReorderMultiselectOptions(c);
        monitorfilasUpdateMultiselectCount(c);
    }

    function monitorfilasGetMultiselectValues(c) {
        return opcoes(c).filter(function (o) { return o.querySelector('input').checked; }).map(function (o) { return o.querySelector('input').value; });
    }

    // ------------------------------------------------------------------ painel
    var painel = null;
    var restante = 0;
    var relogio = null;
    var carregando = false;
    var esperaFiltro = null;

    function filtroAtual() {
        var c = painel && painel.querySelector('[data-monitorfilas-filtro] [data-monitorfilas-ms]');
        return c ? monitorfilasGetMultiselectValues(c).join(',') : '';
    }

    function urlAjax(acao) {
        var p = new URLSearchParams({ action: acao });
        var f = filtroAtual();
        if (f) { p.set('grupos', f); }
        return painel.getAttribute('data-ajax') + '?' + p.toString();
    }

    function carregarRankings() {
        var bloco = painel.querySelector('[data-monitorfilas-rankings]');
        var corpo = bloco.querySelector('.monitorfilas-acordeao-corpo');
        return fetch(urlAjax('rankings'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(lerJson)
            .then(function (r) {
                corpo.innerHTML = r && r.success ? r.html : '<div class="monitorfilas-vazio monitorfilas-vazio-pequeno">' + ((r && r.mensagem) || 'Falha ao carregar.') + '</div>';
                bloco.setAttribute('data-carregado', '1');
            })
            .catch(function () { corpo.innerHTML = '<div class="monitorfilas-vazio monitorfilas-vazio-pequeno">Falha de comunicação com o servidor.</div>'; });
    }

    function atualizar() {
        if (!painel || carregando) { return; }
        carregando = true;
        painel.classList.add('monitorfilas-atualizando');
        var alvo = painel.querySelector('[data-monitorfilas-conteudo]');
        fetch(urlAjax('painel'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(lerJson)
            .then(function (r) {
                if (!r || !r.success) { throw new Error((r && r.mensagem) || 'Falha ao atualizar.'); }
                // Mantém abertos/fechados os blocos de técnicos que o usuário mexeu
                var fechados = Array.prototype.map.call(alvo.querySelectorAll('details.monitorfilas-detalhes:not([open])'), function (d) {
                    return d.closest('.monitorfilas-grupo').querySelector('h5').textContent;
                });
                alvo.innerHTML = r.html;
                alvo.querySelectorAll('details.monitorfilas-detalhes').forEach(function (d) {
                    if (fechados.indexOf(d.closest('.monitorfilas-grupo').querySelector('h5').textContent) !== -1) { d.removeAttribute('open'); }
                });
                var hora = painel.querySelector('[data-monitorfilas-hora]');
                if (hora) { hora.textContent = r.hora; }
                var bloco = painel.querySelector('[data-monitorfilas-rankings]');
                if (bloco.getAttribute('data-carregado') === '1' && !bloco.querySelector('.monitorfilas-acordeao-corpo').hidden) { carregarRankings(); }
            })
            .catch(function (erro) { aviso(erro.message); })
            .then(function () {
                carregando = false;
                painel.classList.remove('monitorfilas-atualizando');
                restante = parseInt(painel.getAttribute('data-intervalo'), 10) || 60;
                mostrarContagem();
            });
    }

    function mostrarContagem() {
        var el = painel.querySelector('[data-monitorfilas-contagem]');
        if (el) { el.textContent = Math.max(0, restante); }
    }

    function tique() {
        if (document.hidden) { return; }
        restante--;
        mostrarContagem();
        if (restante <= 0) { atualizar(); }
    }

    function aplicarFiltro() {
        var f = filtroAtual();
        var url = new URL(window.location.href);
        if (f) { url.searchParams.set('grupos', f); } else { url.searchParams.delete('grupos'); }
        history.replaceState(null, '', url.toString());
        try { if (f) { localStorage.setItem(CHAVE_FILTRO, f); } else { localStorage.removeItem(CHAVE_FILTRO); } } catch (e) { /* sem armazenamento */ }
        atualizar();
    }

    function iniciarPainel() {
        painel = document.getElementById('monitorfilas');
        if (!painel) { return; }

        // Recupera o último filtro usado neste navegador
        var url = new URL(window.location.href);
        if (!url.searchParams.has('grupos')) {
            try {
                var salvo = localStorage.getItem(CHAVE_FILTRO);
                if (salvo) {
                    url.searchParams.set('grupos', salvo);
                    window.location.replace(url.toString());
                    return;
                }
            } catch (e) { /* sem armazenamento */ }
        }

        restante = parseInt(painel.getAttribute('data-intervalo'), 10) || 60;
        mostrarContagem();
        relogio = setInterval(tique, 1000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && restante <= 0) { atualizar(); }
        });
        document.addEventListener('fullscreenchange', function () {
            painel.classList.toggle('monitorfilas-tv', document.fullscreenElement === painel);
        });
    }

    // ------------------------------------------------------------------ eventos
    document.addEventListener('click', function (ev) {
        var t = ev.target;
        var abrir = t.closest('[data-monitorfilas-ms-abrir]');
        if (abrir) { monitorfilasToggleMultiselect(abrir.closest('[data-monitorfilas-ms]')); return; }
        document.querySelectorAll('[data-monitorfilas-ms]').forEach(function (c) { if (!c.contains(t)) { fechar(c); } });

        if (t.closest('[data-monitorfilas-atualizar]')) { atualizar(); return; }
        if (t.closest('[data-monitorfilas-tela-cheia]')) {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (painel && painel.requestFullscreen) {
                painel.requestFullscreen().catch(function () { aviso('O navegador não permitiu a tela cheia.'); });
            }
            return;
        }
        var acordeao = t.closest('[data-monitorfilas-acordeao]');
        if (acordeao) {
            var bloco = acordeao.closest('[data-monitorfilas-rankings]');
            var corpo = bloco.querySelector('.monitorfilas-acordeao-corpo');
            corpo.hidden = !corpo.hidden;
            acordeao.setAttribute('aria-expanded', corpo.hidden ? 'false' : 'true');
            if (!corpo.hidden && bloco.getAttribute('data-carregado') !== '1') { carregarRankings(); }
        }
    });

    document.addEventListener('change', function (ev) {
        var c = ev.target.closest('[data-monitorfilas-ms]');
        if (!c) { return; }
        if (ev.target.matches('[data-monitorfilas-ms-todos]')) {
            monitorfilasToggleAllMultiselect(c, ev.target.checked);
        } else {
            var op = ev.target.closest('.monitorfilas-ms-opcao');
            if (op) { monitorfilasHandleMultiselectChange(c, op); }
        }
        if (c.closest('[data-monitorfilas-filtro]')) {
            clearTimeout(esperaFiltro);
            esperaFiltro = setTimeout(aplicarFiltro, 500);
        }
    });

    document.addEventListener('input', function (ev) {
        if (ev.target.matches('.monitorfilas-ms-busca')) {
            monitorfilasFilterMultiselect(ev.target.closest('[data-monitorfilas-ms]'), ev.target.value);
        }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && ev.target.matches('.monitorfilas-ms-busca')) { ev.preventDefault(); }
    });

    function iniciar() {
        document.querySelectorAll('[data-monitorfilas-ms]').forEach(monitorfilasUpdateMultiselectCount);
        iniciarPainel();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
