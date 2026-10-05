<?php

/**
 * Plugin Monitor de Filas - desenho do painel (visual nativo do GLPI).
 * Os números abrem a busca nativa já filtrada pelo grupo e status.
 */
class PluginMonitorfilasPainel extends CommonGLPI
{
    private PluginMonitorfilasDados $dados;
    private array $ativos;

    public static function getTypeName($nb = 0): string
    {
        return 'Monitor de filas';
    }

    public static function canView(): bool
    {
        return PluginMonitorfilasConfig::podeVer();
    }

    public function __construct(array $filtro = [])
    {
        $this->dados = new PluginMonitorfilasDados();
        $grupos = $this->dados->grupos();
        $filtro = array_values(array_intersect(array_map('intval', $filtro), $grupos));
        $this->ativos = $filtro ?: $grupos;
    }

    /** Filtro de grupos vindo da URL: ?grupos=1,2,3 */
    public static function lerFiltro($valor): array
    {
        $lista = is_array($valor) ? $valor : explode(',', (string) $valor);
        return array_values(array_unique(array_filter(array_map('intval', $lista), fn($v) => $v > 0)));
    }

    private static function e($t): string
    {
        return PluginMonitorfilasConfig::e($t);
    }

    // =====================================================================
    // Links para a busca nativa
    // =====================================================================

    /** Critério de grupo (um ou vários; atribuído, observador ou os dois) */
    private static function criterioGrupos(array $grupos): array
    {
        $campos = match (PluginMonitorfilasConfig::vinculo()) {
            'observador' => [65],
            'ambos'      => [8, 65],
            default      => [8],
        };
        $sub = [];
        foreach ($grupos as $gid) {
            foreach ($campos as $campo) {
                $sub[] = ['link' => $sub ? 'OR' : 'AND', 'field' => $campo, 'searchtype' => 'equals', 'value' => (int) $gid];
            }
        }
        return count($sub) === 1 ? $sub[0] : ['link' => 'AND', 'criteria' => $sub];
    }

    public static function urlBusca(string $tipo, array $grupos, $status = 'notold', array $extras = []): string
    {
        $criterios = [['link' => 'AND', 'field' => 12, 'searchtype' => 'equals', 'value' => (string) $status], self::criterioGrupos($grupos)];
        foreach ($extras as $c) {
            $criterios[] = ['link' => 'AND'] + $c;
        }
        return $tipo::getSearchURL() . '?' . http_build_query(['criteria' => $criterios, 'reset' => 'reset']);
    }

    public static function iconeStatus(string $tipo, int $status): string
    {
        $classe = $tipo::getStatusClass($status);
        return $classe !== '' ? '<i class="' . self::e($classe) . '"></i>' : '<i class="ti ti-circle"></i>';
    }

    private static function link(string $url, string $conteudo, string $classe = '', string $titulo = ''): string
    {
        return '<a href="' . self::e($url) . '" target="_blank" class="' . self::e($classe) . '"' . ($titulo !== '' ? ' title="' . self::e($titulo) . '"' : '') . '>' . $conteudo . '</a>';
    }

    // =====================================================================
    // Página
    // =====================================================================

    public function pagina(): string
    {
        $C = PluginMonitorfilasConfig::class;
        $e = [$C, 'e'];
        $grupos = $this->dados->grupos();

        if (!$grupos) {
            return '<div class="monitorfilas-vazio"><i class="ti ti-users-group"></i><span>Nenhum grupo configurado para monitoramento.</span>'
                . ($C::ehAdmin() ? '<a class="btn btn-sm btn-outline-secondary" href="' . $e($C::url('config.form.php')) . '"><i class="ti ti-settings me-1"></i>Configurar</a>' : '') . '</div>';
        }

        $intervalo = $C::inteiro('intervalo', 30, 600);
        $h = '<div class="monitorfilas" id="monitorfilas" data-ajax="' . $e($C::url('ajax.php')) . '" data-intervalo="' . $intervalo . '">';

        // Barra de ferramentas
        $h .= '<div class="monitorfilas-barra">';
        $h .= '<div class="monitorfilas-barra-filtro"><span class="monitorfilas-rotulo"><i class="ti ti-filter"></i> Grupos</span>'
            . '<div class="monitorfilas-filtro-grupos" data-monitorfilas-filtro>'
            . $C::multiselect('filtro', PluginMonitorfilasDados::nomesGrupos($grupos), count($this->ativos) === count($grupos) ? [] : $this->ativos, 'Todos os grupos', false)
            . '</div></div>';
        $h .= '<div class="monitorfilas-barra-acoes">';
        $h .= '<span class="monitorfilas-relogio"><i class="ti ti-clock"></i> Atualizado às <b data-monitorfilas-hora>' . date('H:i:s') . '</b>'
            . ' · próxima em <b data-monitorfilas-contagem>' . $intervalo . '</b>s</span>';
        $h .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-monitorfilas-atualizar title="Atualizar agora"><i class="ti ti-refresh"></i><span>Atualizar</span></button>';
        $h .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-monitorfilas-tela-cheia title="Tela cheia (TV)"><i class="ti ti-arrows-maximize"></i><span>Tela cheia</span></button>';
        if ($C::ehAdmin()) {
            $h .= '<a class="btn btn-sm btn-ghost-secondary" href="' . $e($C::url('config.form.php')) . '" title="Configuração"><i class="ti ti-settings"></i></a>';
        }
        $h .= '</div></div>';

        // Rankings (carregados ao abrir)
        $periodo = PluginMonitorfilasConfig::PERIODOS[$this->dados->rankingPeriodo()] ?? '';
        $h .= '<div class="card monitorfilas-card monitorfilas-rankings" data-monitorfilas-rankings>'
            . '<button type="button" class="monitorfilas-acordeao" data-monitorfilas-acordeao aria-expanded="false">'
            . '<span><i class="ti ti-chart-bar"></i> Rankings <small>(' . $e(mb_strtolower($periodo)) . ')</small></span><i class="ti ti-chevron-down monitorfilas-seta"></i></button>'
            . '<div class="monitorfilas-acordeao-corpo" hidden><div class="monitorfilas-carregando"><i class="ti ti-loader"></i> Carregando...</div></div></div>';

        $h .= '<div data-monitorfilas-conteudo>' . $this->conteudo() . '</div>';
        return $h . '</div>';
    }

    // =====================================================================
    // Conteúdo (totais e grupos) - também usado na atualização automática
    // =====================================================================

    public function conteudo(): string
    {
        $dados = $this->dados->painel();
        $tipos = $this->dados->tipos();
        $grupos = array_intersect_key($dados['grupos'], array_flip($this->ativos));
        $usuarios = $dados['usuarios'] ?? [];

        // Totais somados dos grupos filtrados (um item em dois grupos conta em cada um, como nas filas)
        $totais = array_fill_keys($tipos, []);
        $validacao = 0;
        $sla = array_fill_keys(array_keys(PluginMonitorfilasDados::FAIXAS), 0);
        foreach ($grupos as $g) {
            foreach ($tipos as $t) {
                foreach ($g['contagem'][$t] ?? [] as $s => $n) {
                    $totais[$t][$s] = ($totais[$t][$s] ?? 0) + $n;
                }
            }
            $validacao += (int) $g['validacao'];
            foreach ($g['sla'] as $f => $n) {
                $sla[$f] += $n;
            }
        }

        $ids = array_keys($grupos);
        $h = '<div class="monitorfilas-totais">';
        foreach ($tipos as $tipo) {
            $h .= $this->cardTotal($tipo, $totais[$tipo], $ids);
        }
        if (in_array('Ticket', $tipos, true)) {
            $h .= '<div class="monitorfilas-total"><div class="monitorfilas-total-topo"><span><i class="ti ti-clock-exclamation"></i> SLA dos chamados</span></div><div class="monitorfilas-chips">';
            $algum = false;
            foreach (['tto_vencido', 'ttr_vencido', 'tto_critico', 'ttr_critico', 'tto_atendido_atraso'] as $f) {
                if ($sla[$f] > 0) {
                    $algum = true;
                    [$grupo, $rotulo, $var] = PluginMonitorfilasDados::FAIXAS[$f];
                    $h .= self::link(PluginMonitorfilasConfig::url('sla.php', ['grupos' => implode(',', $ids), 'faixa' => $f]),
                        '<b>' . $sla[$f] . '</b> ' . self::e(substr($f, 0, 3) === 'tto' ? 'TTO' : 'TTR') . ' ' . self::e(mb_strtolower($rotulo)), 'monitorfilas-chip monitorfilas-chip-' . $var, $grupo);
                }
            }
            if ($validacao > 0) {
                $algum = true;
                $h .= self::link(self::urlBusca('Ticket', $ids, 'notold', [['field' => 52, 'searchtype' => 'equals', 'value' => CommonITILValidation::WAITING]]),
                    '<i class="ti ti-thumb-up"></i><b>' . $validacao . '</b> aguardando aprovação', 'monitorfilas-chip');
            }
            if (!$algum) {
                $h .= '<span class="monitorfilas-chip monitorfilas-chip-ok"><i class="ti ti-circle-check"></i> Nada vencido ou crítico</span>';
            }
            $h .= '</div></div>';
        }
        $h .= '</div>';

        $h .= '<div class="monitorfilas-grupos">';
        foreach ($grupos as $gid => $g) {
            $h .= $this->cardGrupo((int) $gid, $g, $tipos, $usuarios);
        }
        return $h . '</div>';
    }

    private function cardTotal(string $tipo, array $contagem, array $grupos): string
    {
        $def = PluginMonitorfilasConfig::TIPOS[$tipo];
        $total = array_sum($contagem);
        $h = '<div class="monitorfilas-total"><div class="monitorfilas-total-topo"><span><i class="' . $def['icone'] . '"></i> ' . self::e($def['rotulo']) . '</span>'
            . self::link(self::urlBusca($tipo, $grupos), (string) $total, 'monitorfilas-total-numero', 'Ver todos em aberto') . '</div><div class="monitorfilas-chips">';
        if ($total === 0) {
            $h .= '<span class="monitorfilas-chip monitorfilas-chip-ok"><i class="ti ti-circle-check"></i> Nenhum em aberto</span>';
        }
        foreach (PluginMonitorfilasDados::statusAbertos($tipo) as $s) {
            if (!empty($contagem[$s])) {
                $h .= self::link(self::urlBusca($tipo, $grupos, $s), self::iconeStatus($tipo, $s) . '<b>' . (int) $contagem[$s] . '</b> ' . self::e($tipo::getStatus($s)), 'monitorfilas-chip');
            }
        }
        return $h . '</div></div>';
    }

    private function cardGrupo(int $gid, array $g, array $tipos, array $usuarios): string
    {
        $total = 0;
        foreach ($tipos as $t) {
            $total += array_sum($g['contagem'][$t] ?? []);
        }
        $h = '<div class="card monitorfilas-card monitorfilas-grupo">';
        $h .= '<div class="card-header"><h5><i class="ti ti-users-group"></i>' . self::e($g['nome']) . '</h5><span class="monitorfilas-grupo-total" title="Itens em aberto">' . $total . '</span></div>';
        $h .= '<div class="card-body">';

        // Filas por tipo e status
        foreach ($tipos as $tipo) {
            $def = PluginMonitorfilasConfig::TIPOS[$tipo];
            $contagem = $g['contagem'][$tipo] ?? [];
            $soma = array_sum($contagem);
            $h .= '<div class="monitorfilas-secao"><div class="monitorfilas-secao-titulo"><span><i class="' . $def['icone'] . '"></i> ' . self::e($def['rotulo']) . '</span>'
                . ($soma > 0 ? self::link(self::urlBusca($tipo, [$gid]), (string) $soma, 'monitorfilas-contador', 'Ver todos em aberto') : '<span class="monitorfilas-contador monitorfilas-zero">0</span>') . '</div>';
            foreach (PluginMonitorfilasDados::statusAbertos($tipo) as $s) {
                if (!empty($contagem[$s])) {
                    $h .= '<div class="monitorfilas-linha">' . self::iconeStatus($tipo, $s) . '<span>' . self::e($tipo::getStatus($s)) . '</span>'
                        . self::link(self::urlBusca($tipo, [$gid], $s), (string) (int) $contagem[$s], 'monitorfilas-valor') . '</div>';
                }
            }
            if ($tipo === 'Ticket' && $g['validacao'] > 0) {
                $h .= '<div class="monitorfilas-linha"><i class="ti ti-thumb-up"></i><span>Aguardando aprovação</span>'
                    . self::link(self::urlBusca('Ticket', [$gid], 'notold', [['field' => 52, 'searchtype' => 'equals', 'value' => CommonITILValidation::WAITING]]), (string) (int) $g['validacao'], 'monitorfilas-valor') . '</div>';
            }
            $h .= '</div>';
        }

        $h .= $this->secaoTecnicos($gid, $g['tecnicos'] ?? [], $tipos, $usuarios);
        $h .= $this->secaoSla($gid, $g['sla'] ?? []);
        $h .= $this->secaoParados($g['parados'] ?? [], $usuarios);
        return $h . '</div></div>';
    }

    private function secaoTecnicos(int $gid, array $tecnicos, array $tipos, array $usuarios): string
    {
        $linhas = '';
        foreach ($tipos as $tipo) {
            $porTecnico = $tecnicos[$tipo] ?? [];
            if (!$porTecnico) {
                continue;
            }
            uasort($porTecnico, fn($a, $b) => array_sum($b) <=> array_sum($a));
            foreach ($porTecnico as $uid => $status) {
                $chips = '';
                foreach (PluginMonitorfilasDados::statusAbertos($tipo) as $s) {
                    if (!empty($status[$s])) {
                        $chips .= self::link(self::urlBusca($tipo, [$gid], $s, [['field' => 5, 'searchtype' => 'equals', 'value' => (int) $uid]]),
                            self::iconeStatus($tipo, $s) . (int) $status[$s], 'monitorfilas-mini', $tipo::getStatus($s));
                    }
                }
                $linhas .= '<div class="monitorfilas-tecnico"><i class="' . PluginMonitorfilasConfig::TIPOS[$tipo]['icone'] . ' monitorfilas-tipo" title="' . self::e(PluginMonitorfilasConfig::TIPOS[$tipo]['rotulo']) . '"></i>'
                    . '<span class="monitorfilas-tecnico-nome">' . self::e($usuarios[$uid] ?? ('Usuário #' . $uid)) . '</span>'
                    . '<span class="monitorfilas-tecnico-status">' . $chips . '</span>'
                    . self::link(self::urlBusca($tipo, [$gid], 'notold', [['field' => 5, 'searchtype' => 'equals', 'value' => (int) $uid]]), (string) array_sum($status), 'monitorfilas-valor', 'Total do técnico')
                    . '</div>';
            }
        }
        if ($linhas === '') {
            return '';
        }
        return '<details class="monitorfilas-secao monitorfilas-detalhes" open><summary class="monitorfilas-secao-titulo"><span><i class="ti ti-user-check"></i> Técnicos atribuídos</span><i class="ti ti-chevron-down monitorfilas-seta"></i></summary>' . $linhas . '</details>';
    }

    private function secaoSla(int $gid, array $sla): string
    {
        if (array_sum($sla) === 0) {
            return '';
        }
        $h = '<div class="monitorfilas-secao"><div class="monitorfilas-secao-titulo"><span><i class="ti ti-clock-exclamation"></i> SLA dos chamados</span></div>';
        $atual = '';
        foreach (PluginMonitorfilasDados::FAIXAS as $f => [$grupo, $rotulo, $var]) {
            if (empty($sla[$f])) {
                continue;
            }
            if ($grupo !== $atual) {
                $h .= ($atual !== '' ? '</div>' : '') . '<div class="monitorfilas-sla"><span class="monitorfilas-sla-grupo">' . self::e($grupo) . '</span>';
                $atual = $grupo;
            }
            $h .= self::link(PluginMonitorfilasConfig::url('sla.php', ['grupos' => $gid, 'faixa' => $f]), '<b>' . (int) $sla[$f] . '</b> ' . self::e($rotulo), 'monitorfilas-chip monitorfilas-chip-' . $var);
        }
        return $h . '</div></div>';
    }

    private function secaoParados(array $parados, array $usuarios): string
    {
        if (!$parados) {
            return '';
        }
        $h = '<div class="monitorfilas-secao"><div class="monitorfilas-secao-titulo"><span><i class="ti ti-clock-pause"></i> Parados há mais tempo</span></div>';
        foreach ($parados as $t) {
            $tecnico = (int) $t['tecnico'] > 0 ? ($usuarios[(int) $t['tecnico']] ?? '') : 'Sem técnico';
            $h .= self::link(Ticket::getFormURLWithID((int) $t['id']),
                '<span class="monitorfilas-parado-id">' . self::iconeStatus('Ticket', (int) $t['status']) . '#' . (int) $t['id'] . '</span>'
                . '<span class="monitorfilas-parado-texto"><span class="monitorfilas-parado-titulo">' . self::e($t['name']) . '</span>'
                . '<small><i class="ti ti-user"></i> ' . self::e($tecnico) . ($t['entidade'] !== '' ? ' · <i class="ti ti-building"></i> ' . self::e($t['entidade']) : '') . '</small></span>'
                . '<span class="monitorfilas-parado-tempo" title="No status atual / desde a abertura"><b>' . self::e(PluginMonitorfilasConfig::duracao((int) $t['no_status'])) . '</b><small>aberto há ' . self::e(PluginMonitorfilasConfig::duracao((int) $t['aberto'])) . '</small></span>',
                'monitorfilas-parado', Ticket::getStatus((int) $t['status']));
        }
        return $h . '</div>';
    }

    // =====================================================================
    // Rankings
    // =====================================================================

    public function rankings(): string
    {
        $r = $this->dados->rankings();
        $limite = PluginMonitorfilasConfig::inteiro('ranking_limite', 3, 50);
        $ativos = array_flip($this->ativos);
        $nomesGrupos = PluginMonitorfilasDados::nomesGrupos($this->ativos);
        $usuarios = $r['usuarios'] ?? [];
        $agora = time();

        // 1. Técnicos há mais tempo sem puxar um chamado
        $ociosos = [];
        foreach ($r['ociosos'] as $o) {
            $grupos = array_values(array_filter($o['grupos'], fn($g) => isset($ativos[$g])));
            if (!$grupos) {
                continue;
            }
            $referencia = max((int) $o['ultimo'], (int) $r['inicio']);
            $ociosos[] = [
                'titulo'  => $usuarios[$o['uid']] ?? ('Usuário #' . $o['uid']),
                'sub'     => (int) $o['ultimo'] > 0 ? 'último atendimento às ' . date('H:i', (int) $o['ultimo']) . ((int) $o['ultimo'] < strtotime('today') ? ' de ' . date('d/m', (int) $o['ultimo']) : '') : 'sem atender no período',
                'valor'   => $agora - $referencia,
                'texto'   => PluginMonitorfilasConfig::duracao($agora - $referencia),
            ];
        }
        usort($ociosos, fn($a, $b) => $b['valor'] <=> $a['valor']);

        // 2. Novos sem técnico
        $esperando = [];
        foreach ($r['esperando'] as $n) {
            if (!isset($ativos[$n['gid']]) || isset($esperando[$n['id']])) {
                continue;
            }
            $esperando[$n['id']] = [
                'titulo' => '#' . $n['id'] . ' ' . $n['name'], 'sub' => $nomesGrupos[$n['gid']] ?? '',
                'valor' => $agora - $n['ts'], 'texto' => PluginMonitorfilasConfig::duracao($agora - $n['ts']), 'url' => Ticket::getFormURLWithID($n['id']),
            ];
        }
        $esperando = array_values($esperando);
        usort($esperando, fn($a, $b) => $b['valor'] <=> $a['valor']);

        // 3. Técnicos com mais chamados em atendimento
        $soma = [];
        $gruposTec = [];
        foreach ($r['atendendo'] as $gid => $porTecnico) {
            if (!isset($ativos[$gid])) {
                continue;
            }
            foreach ($porTecnico as $uid => $n) {
                $soma[$uid] = ($soma[$uid] ?? 0) + $n;
                $gruposTec[$uid][$gid] = true;
            }
        }
        $atendendo = [];
        foreach ($soma as $uid => $n) {
            $qg = count($gruposTec[$uid]);
            $atendendo[] = ['titulo' => $usuarios[$uid] ?? ('Usuário #' . $uid), 'sub' => $qg === 1 ? 'em 1 grupo' : 'em ' . $qg . ' grupos', 'valor' => $n, 'texto' => (string) $n];
        }
        usort($atendendo, fn($a, $b) => $b['valor'] <=> $a['valor']);

        // 4. Entidades que mais abriram chamados
        $ent = [];
        foreach ($r['entidades'] as $gid => $porEntidade) {
            if (!isset($ativos[$gid])) {
                continue;
            }
            foreach ($porEntidade as $eid => $n) {
                $ent[$eid] = ($ent[$eid] ?? 0) + $n;
            }
        }
        $entidades = [];
        foreach ($ent as $eid => $n) {
            $entidades[] = ['titulo' => $r['entidades_nomes'][$eid] ?? ('Entidade #' . $eid), 'sub' => '', 'valor' => $n, 'texto' => $n === 1 ? '1 chamado' : $n . ' chamados'];
        }
        usort($entidades, fn($a, $b) => $b['valor'] <=> $a['valor']);

        $h = '<div class="monitorfilas-ranking-grade">';
        $h .= self::blocoRanking('Técnicos há mais tempo sem puxar chamado', 'ti ti-hourglass-empty', 'Tempo desde o último chamado colocado em atendimento', array_slice($ociosos, 0, $limite));
        $h .= self::blocoRanking('Chamados novos sem técnico', 'ti ti-clock-exclamation', 'Abertos no período, esperando há mais tempo', array_slice($esperando, 0, $limite));
        $h .= self::blocoRanking('Técnicos com mais chamados em atendimento', 'ti ti-user-check', 'Situação agora', array_slice($atendendo, 0, $limite));
        $h .= self::blocoRanking('Entidades que mais abriram chamados', 'ti ti-building', 'Chamados abertos no período', array_slice($entidades, 0, $limite));
        return $h . '</div>';
    }

    private static function blocoRanking(string $titulo, string $icone, string $descricao, array $linhas): string
    {
        $h = '<div class="monitorfilas-ranking"><div class="monitorfilas-ranking-titulo"><i class="' . $icone . '"></i> ' . self::e($titulo) . '</div><small class="monitorfilas-ranking-desc">' . self::e($descricao) . '</small>';
        if (!$linhas) {
            return $h . '<div class="monitorfilas-vazio monitorfilas-vazio-pequeno">Sem dados no período.</div></div>';
        }
        $max = max(1, max(array_column($linhas, 'valor')));
        foreach ($linhas as $i => $l) {
            $conteudo = '<span class="monitorfilas-ranking-pos">' . ($i + 1) . '</span><span class="monitorfilas-ranking-info"><span class="monitorfilas-ranking-linha"><span class="monitorfilas-ranking-nome">'
                . self::e($l['titulo']) . '</span><b>' . self::e($l['texto']) . '</b></span>'
                . ($l['sub'] !== '' ? '<small>' . self::e($l['sub']) . '</small>' : '')
                . '<span class="monitorfilas-ranking-barra"><span style="width:' . max(4, round($l['valor'] / $max * 100)) . '%"></span></span></span>';
            $h .= isset($l['url']) ? self::link($l['url'], $conteudo, 'monitorfilas-ranking-item') : '<div class="monitorfilas-ranking-item">' . $conteudo . '</div>';
        }
        return $h . '</div>';
    }
}
