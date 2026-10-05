<?php

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

Session::checkLoginUser();

if (!PluginMonitorfilasConfig::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::redirect(PluginMonitorfilasConfig::url('config.form.php'));
