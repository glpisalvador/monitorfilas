<?php

/**
 * Plugin Monitor de Filas - chamados de um ou mais grupos numa faixa de SLA
 * (?grupos=1,2&faixa=ttr_vencido)
 */

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

Session::checkLoginUser();

$C = PluginMonitorfilasConfig::class;
if (!$C::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = [$C, 'e'];
$F = PluginMonitorfilasDados::FAIXAS;

$dados = new PluginMonitorfilasDados();
$grupos = array_values(array_intersect(PluginMonitorfilasPainel::lerFiltro($_GET['grupos'] ?? ''), $dados->grupos())) ?: $dados->grupos();
$faixa = isset($F[$_GET['faixa'] ?? '']) ? (string) $_GET['faixa'] : 'ttr_vencido';
$lista = $dados->chamadosSla($grupos, $faixa);
$nomesGrupos = PluginMonitorfilasDados::nomesGrupos($grupos);

Html::header('Chamados por SLA', $_SERVER['PHP_SELF'], 'tools', 'PluginMonitorfilasMenu', 'sla');
echo $C::assets();

echo '<div class="monitorfilas">';
echo '<div class="monitorfilas-barra">';
echo '<div class="monitorfilas-barra-filtro"><a class="btn btn-sm btn-outline-secondary" href="' . $e($C::url('painel.php', count($grupos) < count($dados->grupos()) ? ['grupos' => implode(',', $grupos)] : [])) . '"><i class="ti ti-arrow-left me-1"></i>Painel</a>';
echo '<span class="monitorfilas-rotulo"><i class="ti ti-users-group"></i> ' . $e(implode(', ', $nomesGrupos)) . '</span></div>';
echo '<div class="monitorfilas-barra-acoes monitorfilas-faixas">';
$atual = '';
foreach ($F as $chave => [$grupo, $rotulo, $var]) {
    if ($grupo !== $atual) {
        echo '<span class="monitorfilas-sla-grupo">' . $e($grupo) . '</span>';
        $atual = $grupo;
    }
    echo '<a class="monitorfilas-chip monitorfilas-chip-' . $var . ($chave === $faixa ? ' ativo' : '') . '" href="' . $e($C::url('sla.php', ['grupos' => implode(',', $grupos), 'faixa' => $chave])) . '">' . $e($rotulo) . '</a>';
}
echo '</div></div>';

[$grupoFaixa, $rotuloFaixa] = $F[$faixa];
echo '<div class="card monitorfilas-card"><div class="card-header"><h5><i class="ti ti-clock-exclamation"></i>' . $e($grupoFaixa . ' · ' . $rotuloFaixa) . '</h5><span class="monitorfilas-grupo-total">' . count($lista) . '</span></div>';
if (!$lista) {
    echo '<div class="monitorfilas-vazio"><i class="ti ti-checks"></i><span>Nenhum chamado nesta faixa de SLA.</span></div>';
} else {
    $tto = str_starts_with($faixa, 'tto');
    echo '<div class="table-responsive"><table class="table table-hover table-sm monitorfilas-tabela"><thead><tr>'
        . '<th>ID</th><th>Título</th><th>Status</th><th>Entidade</th><th>Requerente</th><th>Técnico</th><th>Abertura</th><th>Prazo</th><th style="width:170px">Consumido</th><th>SLA</th></tr></thead><tbody>';
    foreach ($lista as $t) {
        $url = Ticket::getFormURLWithID((int) $t['id']);
        $pct = (float) $t['pct'];
        $classe = $pct > 100 ? 'erro' : ($pct >= $dados->slaCritico() ? 'aviso' : 'ok');
        $prazo = $tto ? ($t['takeintoaccountdate'] ? 'Atendido ' . Html::convDateTime($t['takeintoaccountdate']) : Html::convDateTime($t['time_to_own'])) : Html::convDateTime($t['time_to_resolve']);
        echo '<tr>'
            . '<td><a href="' . $e($url) . '" target="_blank">' . (int) $t['id'] . '</a></td>'
            . '<td><a href="' . $e($url) . '" target="_blank">' . $e($t['name']) . '</a></td>'
            . '<td class="text-nowrap">' . PluginMonitorfilasPainel::iconeStatus('Ticket', (int) $t['status']) . $e(Ticket::getStatus((int) $t['status'])) . '</td>'
            . '<td>' . $e($t['entidade']) . '</td>'
            . '<td>' . $e($t['requerente'] ?: '—') . '</td>'
            . '<td>' . $e($t['tecnico'] ?: '—') . '</td>'
            . '<td class="text-nowrap">' . $e(Html::convDateTime($t['date'])) . '</td>'
            . '<td class="text-nowrap">' . $e($prazo) . '</td>'
            . '<td><span class="monitorfilas-progresso monitorfilas-progresso-' . $classe . '"><span style="width:' . min(100, max(0, $pct)) . '%"></span><em>' . number_format($pct, 0, ',', '.') . '%</em></span></td>'
            . '<td>' . $e($t['sla_nome']) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div>';

Html::footer();
