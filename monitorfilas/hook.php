<?php

/**
 * Plugin Monitor de Filas - instalação
 */

function plugin_monitorfilas_install(): bool
{
    global $DB;

    $tabela = 'glpi_plugin_monitorfilas_configs';
    if (!$DB->tableExists($tabela)) {
        $DB->doQuery("CREATE TABLE `$tabela` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` text,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    include_once __DIR__ . '/inc/config.class.php';
    foreach (PluginMonitorfilasConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => $tabela, 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert($tabela, ['name' => $nome, 'value' => is_array($valor) ? json_encode($valor) : (string) $valor]);
        }
    }
    return true;
}

function plugin_monitorfilas_uninstall(): bool
{
    // A tabela de configuração é mantida: reinstalar recupera tudo. Só o cache temporário é apagado.
    foreach (glob(GLPI_TMP_DIR . '/monitorfilas_*.json') ?: [] as $arquivo) {
        @unlink($arquivo);
    }
    return true;
}
