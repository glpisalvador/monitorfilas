<?php

/**
 * Plugin Monitor de Filas para GLPI 11 e 12
 * Painel em tempo real das filas dos grupos técnicos: chamados, problemas e mudanças
 * por status, técnicos, SLA, itens parados e rankings do dia.
 */

define('PLUGIN_MONITORFILAS_VERSION', '1.0.1');
define('PLUGIN_MONITORFILAS_MIN_GLPI', '11.0.0');
define('PLUGIN_MONITORFILAS_MAX_GLPI', '12.99.99');

function plugin_init_monitorfilas(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['monitorfilas'] = true;

    if (!Plugin::isPluginActive('monitorfilas')) {
        return;
    }

    Plugin::registerClass('PluginMonitorfilasConfig');
    Plugin::registerClass('PluginMonitorfilasMenu');
    Plugin::registerClass('PluginMonitorfilasDados');
    Plugin::registerClass('PluginMonitorfilasPainel');

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['monitorfilas'] = 'front/config.php';
    }

    $PLUGIN_HOOKS['menu_toadd']['monitorfilas'] = ['tools' => 'PluginMonitorfilasMenu'];
}

function plugin_version_monitorfilas(): array
{
    return [
        'name'         => 'Monitor de Filas',
        'version'      => PLUGIN_MONITORFILAS_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MONITORFILAS_MIN_GLPI,
                'max' => PLUGIN_MONITORFILAS_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_monitorfilas_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_MONITORFILAS_MIN_GLPI, 'lt')) {
        echo 'Este plugin requer GLPI ' . PLUGIN_MONITORFILAS_MIN_GLPI . ' ou superior.';
        return false;
    }
    return true;
}

function plugin_monitorfilas_check_config($verbose = false): bool
{
    return true;
}
