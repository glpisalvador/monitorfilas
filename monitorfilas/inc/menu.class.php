<?php

/**
 * Plugin Monitor de Filas - item "Monitor de filas" no menu Ferramentas
 */
class PluginMonitorfilasMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Monitor de filas';
    }

    public static function getMenuName(): string
    {
        return 'Monitor de filas';
    }

    public static function getIcon(): string
    {
        return 'ti ti-layout-dashboard';
    }

    public static function canView(): bool
    {
        return PluginMonitorfilasConfig::podeVer();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getMenuContent(): array
    {
        if (!self::canView()) {
            return [];
        }
        $painel = '/plugins/monitorfilas/front/painel.php';
        $menu = [
            'title'   => self::getMenuName(),
            'page'    => $painel,
            'icon'    => self::getIcon(),
            'links'   => ['search' => $painel],
            'options' => [
                'painel' => ['title' => 'Painel', 'page' => $painel, 'icon' => self::getIcon(), 'links' => ['search' => $painel]],
                'sla'    => ['title' => 'Chamados por SLA', 'page' => '/plugins/monitorfilas/front/sla.php', 'icon' => 'ti ti-clock-exclamation', 'links' => ['search' => $painel]],
            ],
        ];
        if (PluginMonitorfilasConfig::ehAdmin()) {
            $config = '/plugins/monitorfilas/front/config.form.php';
            $menu['links']['config'] = $config;
            $menu['options']['config'] = ['title' => 'Configuração', 'page' => $config, 'icon' => 'ti ti-settings', 'links' => ['search' => $config]];
        }
        return $menu;
    }
}
