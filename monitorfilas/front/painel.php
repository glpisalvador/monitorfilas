<?php

/**
 * Plugin Monitor de Filas - painel (Ferramentas > Monitor de filas)
 */

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

Session::checkLoginUser();

if (!PluginMonitorfilasConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::header('Monitor de filas', $_SERVER['PHP_SELF'], 'tools', 'PluginMonitorfilasMenu', 'painel');
echo PluginMonitorfilasConfig::assets();
echo (new PluginMonitorfilasPainel(PluginMonitorfilasPainel::lerFiltro($_GET['grupos'] ?? '')))->pagina();
Html::footer();
