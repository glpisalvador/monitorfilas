<?php

/**
 * Plugin Monitor de Filas - endpoint AJAX (JSON, somente leitura por GET).
 * painel: HTML atualizado dos totais e cards. rankings: HTML dos rankings.
 * Filtro de grupos em ?grupos=1,2,3.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno ao carregar os dados.']);
    }
});

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

while (ob_get_level() > 0) {
    ob_end_clean();
}

function monitorfilas_json(array $dados): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // Leituras por GET não consomem token; mantido por compatibilidade com o padrão dos plugins
    $dados['new_token'] = '';
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

Session::checkLoginUser();

if (!PluginMonitorfilasConfig::podeVer()) {
    monitorfilas_json(['success' => false, 'mensagem' => 'Sem acesso ao monitor de filas.']);
}

$painel = new PluginMonitorfilasPainel(PluginMonitorfilasPainel::lerFiltro($_GET['grupos'] ?? ''));

switch ((string) ($_GET['action'] ?? '')) {
    case 'painel':
        monitorfilas_json(['success' => true, 'html' => $painel->conteudo(), 'hora' => date('H:i:s')]);

    case 'rankings':
        monitorfilas_json(['success' => true, 'html' => $painel->rankings()]);

    default:
        monitorfilas_json(['success' => false, 'mensagem' => 'Ação desconhecida.']);
}
