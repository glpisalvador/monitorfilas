<?php

/**
 * Plugin Monitor de Filas - configuração: acesso, grupos e opções do painel
 */

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

Session::checkLoginUser();

$C = PluginMonitorfilasConfig::class;
if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = [$C, 'e'];
$acao = $C::url('config.form.php');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_action'])) {
    $ids = fn($campo) => array_values(array_unique(array_filter(array_map('intval', (array) ($_POST[$campo] ?? [])), fn($v) => $v > 0)));
    switch ((string) $_POST['save_action']) {
        case 'salvar_acesso':
            $C::setArrayConfig('acesso_perfis', $ids('acesso_perfis'));
            $C::setArrayConfig('acesso_usuarios', $ids('acesso_usuarios'));
            Session::addMessageAfterRedirect('Acesso salvo.', true, INFO);
            break;

        case 'salvar_grupos':
            $C::setArrayConfig('grupos', $ids('grupos'));
            $vinculo = (string) ($_POST['vinculo'] ?? 'ambos');
            $C::setConfig('vinculo', isset($C::VINCULOS[$vinculo]) ? $vinculo : 'ambos');
            $tipos = array_values(array_intersect(array_keys($C::TIPOS), (array) ($_POST['tipos'] ?? [])));
            $C::setArrayConfig('tipos', $tipos ?: ['Ticket']);
            Session::addMessageAfterRedirect('Grupos e filas salvos.', true, INFO);
            break;

        case 'salvar_opcoes':
            $intervalo = (int) ($_POST['intervalo'] ?? 60);
            $C::setConfig('intervalo', (string) (isset($C::INTERVALOS[$intervalo]) ? $intervalo : 60));
            $C::setConfig('sla_critico', (string) max(1, min(99, (int) ($_POST['sla_critico'] ?? 80))));
            $C::setConfig('parado_horas', (string) max(1, min(720, (int) ($_POST['parado_horas'] ?? 24))));
            $C::setConfig('parados_limite', (string) max(0, min(20, (int) ($_POST['parados_limite'] ?? 3))));
            $C::setConfig('ranking_limite', (string) max(3, min(50, (int) ($_POST['ranking_limite'] ?? 10))));
            $periodo = (string) ($_POST['ranking_periodo'] ?? 'hoje');
            $C::setConfig('ranking_periodo', isset($C::PERIODOS[$periodo]) ? $periodo : 'hoje');
            Session::addMessageAfterRedirect('Opções do painel salvas.', true, INFO);
            break;
    }
    // Configuração mudou: descarta o cache para o painel refletir na hora
    foreach (glob(GLPI_TMP_DIR . '/monitorfilas_*.json') ?: [] as $arquivo) {
        @unlink($arquivo);
    }
}

Html::header('Monitor de filas', $_SERVER['PHP_SELF'], 'tools', 'PluginMonitorfilasMenu', 'config');
echo $C::assets();

function monitorfilas_card(string $titulo, string $icone, string $dica = ''): string
{
    return '<div class="card monitorfilas-card"><div class="card-header"><h5><i class="' . $icone . '"></i>' . PluginMonitorfilasConfig::e($titulo) . '</h5></div><div class="card-body">'
        . ($dica !== '' ? '<p class="monitorfilas-dica"><i class="ti ti-info-circle"></i><span>' . $dica . '</span></p>' : '');
}

function monitorfilas_rodape(): string
{
    // O token CSRF (GLPI 11) entra pelo Html::closeForm()
    return '<div class="monitorfilas-rodape"><button type="submit" class="btn btn-sm monitorfilas-btn-salvar"><i class="ti ti-device-floppy me-1"></i>Salvar</button></div></div></div>';
}

echo '<div class="monitorfilas monitorfilas-config">';

// ------------------------------------------------------------------ Acesso
echo '<form method="post" action="' . $e($acao) . '"><input type="hidden" name="save_action" value="salvar_acesso">';
echo monitorfilas_card('Quem pode ver o painel', 'ti ti-shield-lock', 'Administradores (Configurar &gt; Atualizar) sempre veem. Os números respeitam as entidades do perfil ativo de quem está olhando.');
echo '<div class="monitorfilas-grade-2">';
echo '<div class="monitorfilas-campo"><label>Perfis</label>' . $C::multiselect('acesso_perfis', $C::listarPerfis(), $C::ids('acesso_perfis'), 'Nenhum perfil') . '</div>';
echo '<div class="monitorfilas-campo"><label>Usuários</label>' . $C::multiselect('acesso_usuarios', $C::listarUsuarios(), $C::ids('acesso_usuarios'), 'Nenhum usuário') . '</div>';
echo '</div>' . monitorfilas_rodape();
Html::closeForm();

// ------------------------------------------------------------------ Grupos e filas
echo '<form method="post" action="' . $e($acao) . '"><input type="hidden" name="save_action" value="salvar_grupos">';
echo monitorfilas_card('Grupos e filas', 'ti ti-users-group', 'Cada grupo marcado vira um card no painel.');
echo '<div class="monitorfilas-campo"><label>Grupos monitorados</label>' . $C::multiselect('grupos', $C::listarGrupos(), $C::ids('grupos'), 'Nenhum grupo') . '</div>';
echo '<div class="monitorfilas-grade-2 mt-3">';
echo '<div class="monitorfilas-campo"><label>Considerar o grupo quando ele é</label>';
foreach ($C::VINCULOS as $chave => $rotulo) {
    echo '<label class="monitorfilas-inline"><input type="radio" name="vinculo" value="' . $chave . '"' . ($C::vinculo() === $chave ? ' checked' : '') . '> ' . $e($rotulo) . '</label>';
}
echo '<small>Atribuído é a fila de trabalho do grupo; observador é quando o grupo só acompanha.</small></div>';
echo '<div class="monitorfilas-campo"><label>Itens exibidos</label>';
foreach ($C::TIPOS as $tipo => $def) {
    echo '<label class="monitorfilas-inline"><input type="checkbox" class="monitorfilas-check" name="tipos[]" value="' . $tipo . '"' . (in_array($tipo, $C::tipos(), true) ? ' checked' : '') . '> <i class="' . $def['icone'] . '"></i> ' . $e($def['rotulo']) . '</label>';
}
echo '<small>Os status mostrados são os status em aberto do próprio GLPI, inclusive os personalizados.</small></div>';
echo '</div>' . monitorfilas_rodape();
Html::closeForm();

// ------------------------------------------------------------------ Opções
echo '<form method="post" action="' . $e($acao) . '"><input type="hidden" name="save_action" value="salvar_opcoes">';
echo monitorfilas_card('Opções do painel', 'ti ti-adjustments');
echo '<div class="monitorfilas-grade-3">';
echo '<div class="monitorfilas-campo"><label for="monitorfilas-intervalo">Atualização automática</label><select id="monitorfilas-intervalo" name="intervalo" class="form-select form-select-sm">';
foreach ($C::INTERVALOS as $valor => $rotulo) {
    echo '<option value="' . $valor . '"' . ((int) $C::getConfig('intervalo') === $valor ? ' selected' : '') . '>' . $e($rotulo) . '</option>';
}
echo '</select><small>O painel atualiza sem recarregar a página e pausa quando a aba não está visível.</small></div>';
echo '<div class="monitorfilas-campo"><label for="monitorfilas-critico">SLA crítico a partir de</label><div class="input-group input-group-sm monitorfilas-curto"><input type="number" min="1" max="99" id="monitorfilas-critico" name="sla_critico" class="form-control" value="' . $C::inteiro('sla_critico', 1, 99) . '"><span class="input-group-text">% do prazo</span></div></div>';
echo '<div class="monitorfilas-campo"><label for="monitorfilas-parado">Considerar parado após</label><div class="input-group input-group-sm monitorfilas-curto"><input type="number" min="1" max="720" id="monitorfilas-parado" name="parado_horas" class="form-control" value="' . $C::inteiro('parado_horas', 1, 720) . '"><span class="input-group-text">horas</span></div><small>Chamados novos entram sempre.</small></div>';
echo '<div class="monitorfilas-campo"><label for="monitorfilas-parados">Parados por grupo</label><input type="number" min="0" max="20" id="monitorfilas-parados" name="parados_limite" class="form-control form-control-sm monitorfilas-curto" value="' . $C::inteiro('parados_limite', 0, 20) . '"><small>0 esconde a seção.</small></div>';
echo '<div class="monitorfilas-campo"><label for="monitorfilas-ranking">Itens por ranking</label><input type="number" min="3" max="50" id="monitorfilas-ranking" name="ranking_limite" class="form-control form-control-sm monitorfilas-curto" value="' . $C::inteiro('ranking_limite', 3, 50) . '"></div>';
echo '<div class="monitorfilas-campo"><label for="monitorfilas-periodo">Período dos rankings</label><select id="monitorfilas-periodo" name="ranking_periodo" class="form-select form-select-sm monitorfilas-curto">';
foreach ($C::PERIODOS as $valor => $rotulo) {
    echo '<option value="' . $valor . '"' . ((string) $C::getConfig('ranking_periodo') === $valor ? ' selected' : '') . '>' . $e($rotulo) . '</option>';
}
echo '</select></div>';
echo '</div>' . monitorfilas_rodape();
Html::closeForm();

echo '</div>';
Html::footer();
