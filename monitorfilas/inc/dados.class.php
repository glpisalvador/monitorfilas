<?php

/**
 * Plugin Monitor de Filas - consultas.
 * Sempre restritas às entidades ativas do usuário; cache curto em arquivo por escopo
 * (entidades + configuração), para vários usuários olhando o mesmo painel não repetirem as consultas.
 */
class PluginMonitorfilasDados extends CommonGLPI
{
    /** Tabelas de cada tipo ITIL */
    public const MAPA = [
        'Ticket'  => ['item' => 'glpi_tickets', 'grupo' => 'glpi_groups_tickets', 'usuario' => 'glpi_tickets_users', 'fk' => 'tickets_id'],
        'Problem' => ['item' => 'glpi_problems', 'grupo' => 'glpi_groups_problems', 'usuario' => 'glpi_problems_users', 'fk' => 'problems_id'],
        'Change'  => ['item' => 'glpi_changes', 'grupo' => 'glpi_changes_groups', 'usuario' => 'glpi_changes_users', 'fk' => 'changes_id'],
    ];

    /** Faixas de SLA (chamados) */
    public const FAIXAS = [
        'tto_prazo'           => ['Atendimento (TTO)', 'No prazo', 'ok'],
        'tto_critico'         => ['Atendimento (TTO)', 'Crítico', 'aviso'],
        'tto_vencido'         => ['Atendimento (TTO)', 'Vencido', 'erro'],
        'tto_atendido_prazo'  => ['Atendimento (TTO)', 'Atendido no prazo', 'neutro'],
        'tto_atendido_atraso' => ['Atendimento (TTO)', 'Atendido com atraso', 'atraso'],
        'ttr_prazo'           => ['Solução (TTR)', 'No prazo', 'ok'],
        'ttr_critico'         => ['Solução (TTR)', 'Crítico', 'aviso'],
        'ttr_vencido'         => ['Solução (TTR)', 'Vencido', 'erro'],
    ];

    private array $grupos;
    private array $tiposVinculo;
    private array $tipos;
    private int $slaCritico;
    private int $paradoHoras;
    private int $paradosLimite;
    private int $rankingLimite;
    private string $rankingPeriodo;
    private int $intervalo;

    public static function getTypeName($nb = 0): string
    {
        return 'Dados do monitor de filas';
    }

    public function __construct()
    {
        $C = PluginMonitorfilasConfig::class;
        $this->grupos = $C::ids('grupos');
        $this->tiposVinculo = $C::tiposVinculo();
        $this->tipos = $C::tipos();
        $this->slaCritico = $C::inteiro('sla_critico', 1, 99);
        $this->paradoHoras = $C::inteiro('parado_horas', 1, 720);
        $this->paradosLimite = $C::inteiro('parados_limite', 0, 20);
        $this->rankingLimite = $C::inteiro('ranking_limite', 3, 50);
        $periodo = (string) $C::getConfig('ranking_periodo');
        $this->rankingPeriodo = isset($C::PERIODOS[$periodo]) ? $periodo : 'hoje';
        $this->intervalo = $C::inteiro('intervalo', 30, 600);
    }

    public function grupos(): array
    {
        return $this->grupos;
    }

    public function tipos(): array
    {
        return $this->tipos;
    }

    public function slaCritico(): int
    {
        return $this->slaCritico;
    }

    public function rankingPeriodo(): string
    {
        return $this->rankingPeriodo;
    }

    /** Status "em aberto" do tipo, na ordem em que o GLPI os apresenta (inclui status personalizados) */
    public static function statusAbertos(string $tipo): array
    {
        $abertos = array_map('intval', $tipo::getNotSolvedStatusArray());
        return array_values(array_filter(array_map('intval', array_keys($tipo::getAllStatusArray())), fn($s) => in_array($s, $abertos, true)));
    }

    // =====================================================================
    // Cache e escopo
    // =====================================================================

    private function arquivoCache(string $sufixo): string
    {
        $entidades = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));
        sort($entidades);
        $chave = md5(json_encode([
            $sufixo, $entidades, $this->grupos, $this->tiposVinculo, $this->tipos, $this->slaCritico, $this->paradoHoras,
            $this->paradosLimite, $this->rankingLimite, $this->rankingPeriodo, PLUGIN_MONITORFILAS_VERSION,
        ]));
        return GLPI_TMP_DIR . '/monitorfilas_' . $sufixo . '_' . $chave . '.json';
    }

    private function emCache(string $sufixo, callable $gerar): array
    {
        $arquivo = $this->arquivoCache($sufixo);
        $validade = max(10, $this->intervalo - 5);
        if (is_file($arquivo) && time() - filemtime($arquivo) < $validade) {
            $dados = json_decode((string) file_get_contents($arquivo), true);
            if (is_array($dados)) {
                return $dados;
            }
        }
        $dados = $gerar();
        @file_put_contents($arquivo, json_encode($dados), LOCK_EX);
        return $dados;
    }

    /** Restrição às entidades ativas do usuário para a tabela/alias informado */
    private static function restricao(string $alias): array
    {
        $criterio = (new DbUtils())->getEntitiesRestrictCriteria($alias, 'entities_id', '', false);
        return $criterio ? [$criterio] : [];
    }

    /** Condição comum: item não excluído, em status aberto, ligado aos grupos monitorados */
    private function base(string $tipo, array $status = []): array
    {
        return [
            'i.is_deleted' => 0,
            'i.status'     => $status ?: self::statusAbertos($tipo),
            'g.type'       => $this->tiposVinculo,
            'g.groups_id'  => $this->grupos,
        ] + self::restricao('i');
    }

    private function juncaoGrupo(string $tipo): array
    {
        $m = self::MAPA[$tipo];
        return [$m['grupo'] . ' AS g' => ['ON' => ['g' => $m['fk'], 'i' => 'id']]];
    }

    // =====================================================================
    // Nomes (em lote)
    // =====================================================================

    public static function nomesUsuarios(array $ids): array
    {
        global $DB, $CFG_GLPI;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        $nomes = [];
        if (!$ids) {
            return $nomes;
        }
        $nomeAntes = (int) ($CFG_GLPI['names_format'] ?? 0) === 1;
        foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $ids]]) as $u) {
            $real = trim((string) $u['realname']);
            $primeiro = trim((string) $u['firstname']);
            $nome = $nomeAntes ? trim($primeiro . ' ' . $real) : trim($real . ' ' . $primeiro);
            $nomes[(int) $u['id']] = $nome !== '' ? $nome : (string) $u['name'];
        }
        return $nomes;
    }

    public static function nomesEntidades(array $ids): array
    {
        global $DB;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $nomes = [];
        if ($ids) {
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $ids]]) as $r) {
                $nomes[(int) $r['id']] = (string) $r['name'];
            }
        }
        return $nomes;
    }

    public static function nomesGrupos(array $ids): array
    {
        global $DB;
        $nomes = [];
        if ($ids) {
            foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_groups', 'WHERE' => ['id' => array_map('intval', $ids)]]) as $r) {
                $nomes[(int) $r['id']] = (string) $r['name'];
            }
        }
        return $nomes;
    }

    // =====================================================================
    // SLA
    // =====================================================================

    /** Classifica um chamado nas faixas de TTO e TTR; devolve também o % consumido */
    public function classificarSla(array $t, int $agora): array
    {
        $r = ['tto' => null, 'ttr' => null, 'tto_pct' => null, 'ttr_pct' => null];
        $abertura = strtotime((string) $t['date']) ?: $agora;

        if ((int) ($t['slas_id_tto'] ?? 0) > 0 && !empty($t['time_to_own'])) {
            $limite = strtotime((string) $t['time_to_own']);
            $total = max(1, $limite - $abertura);
            if (!empty($t['takeintoaccountdate'])) {
                $atendido = strtotime((string) $t['takeintoaccountdate']);
                $r['tto_pct'] = round(($atendido - $abertura) / $total * 100, 1);
                $r['tto'] = $atendido <= $limite ? 'tto_atendido_prazo' : 'tto_atendido_atraso';
            } else {
                $r['tto_pct'] = round(($agora - $abertura) / $total * 100, 1);
                $r['tto'] = $agora > $limite ? 'tto_vencido' : ($r['tto_pct'] >= $this->slaCritico ? 'tto_critico' : 'tto_prazo');
            }
        }
        if ((int) ($t['slas_id_ttr'] ?? 0) > 0 && !empty($t['time_to_resolve'])) {
            $limite = strtotime((string) $t['time_to_resolve']);
            $total = max(1, $limite - $abertura);
            $r['ttr_pct'] = round(($agora - $abertura) / $total * 100, 1);
            $r['ttr'] = $agora > $limite ? 'ttr_vencido' : ($r['ttr_pct'] >= $this->slaCritico ? 'ttr_critico' : 'ttr_prazo');
        }
        return $r;
    }

    // =====================================================================
    // Painel
    // =====================================================================

    public function painel(): array
    {
        return $this->emCache('painel', fn() => $this->coletarPainel());
    }

    private function coletarPainel(): array
    {
        global $DB;
        $dados = ['grupos' => [], 'usuarios' => [], 'gerado' => time()];
        if (!$this->grupos) {
            return $dados;
        }
        $nomes = self::nomesGrupos($this->grupos);
        foreach ($this->grupos as $gid) {
            $dados['grupos'][$gid] = [
                'nome'      => $nomes[$gid] ?? ('Grupo #' . $gid),
                'contagem'  => array_fill_keys($this->tipos, []),
                'validacao' => 0,
                'tecnicos'  => array_fill_keys($this->tipos, []),
                'sla'       => array_fill_keys(array_keys(self::FAIXAS), 0),
                'parados'   => [],
            ];
        }
        $usuarios = [];

        foreach ($this->tipos as $tipo) {
            $m = self::MAPA[$tipo];

            // Quantidade por grupo e status
            foreach ($DB->request([
                'SELECT'     => ['g.groups_id', 'i.status', new \Glpi\DBAL\QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('i.id') . ') AS ' . $DB->quoteName('n'))],
                'FROM'       => $m['item'] . ' AS i',
                'INNER JOIN' => $this->juncaoGrupo($tipo),
                'WHERE'      => $this->base($tipo),
                'GROUPBY'    => ['g.groups_id', 'i.status'],
            ]) as $r) {
                $dados['grupos'][(int) $r['groups_id']]['contagem'][$tipo][(int) $r['status']] = (int) $r['n'];
            }

            // Técnicos atribuídos por grupo e status
            foreach ($DB->request([
                'SELECT'     => ['g.groups_id', 'u.users_id', 'i.status', new \Glpi\DBAL\QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('i.id') . ') AS ' . $DB->quoteName('n'))],
                'FROM'       => $m['item'] . ' AS i',
                'INNER JOIN' => $this->juncaoGrupo($tipo) + [$m['usuario'] . ' AS u' => ['ON' => ['u' => $m['fk'], 'i' => 'id']]],
                'WHERE'      => $this->base($tipo) + ['u.type' => CommonITILActor::ASSIGN, 'u.users_id' => ['>', 0]],
                'GROUPBY'    => ['g.groups_id', 'u.users_id', 'i.status'],
            ]) as $r) {
                $uid = (int) $r['users_id'];
                $dados['grupos'][(int) $r['groups_id']]['tecnicos'][$tipo][$uid][(int) $r['status']] = (int) $r['n'];
                $usuarios[$uid] = $uid;
            }
        }

        if (in_array('Ticket', $this->tipos, true)) {
            // Aguardando aprovação (validação global pendente)
            foreach ($DB->request([
                'SELECT'     => ['g.groups_id', new \Glpi\DBAL\QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('i.id') . ') AS ' . $DB->quoteName('n'))],
                'FROM'       => 'glpi_tickets AS i',
                'INNER JOIN' => $this->juncaoGrupo('Ticket'),
                'WHERE'      => $this->base('Ticket') + ['i.global_validation' => CommonITILValidation::WAITING],
                'GROUPBY'    => ['g.groups_id'],
            ]) as $r) {
                $dados['grupos'][(int) $r['groups_id']]['validacao'] = (int) $r['n'];
            }

            // SLA
            $agora = time();
            $vistos = [];
            foreach ($DB->request([
                'SELECT'     => ['g.groups_id', 'i.id', 'i.date', 'i.time_to_own', 'i.time_to_resolve', 'i.takeintoaccountdate', 'i.slas_id_tto', 'i.slas_id_ttr'],
                'FROM'       => 'glpi_tickets AS i',
                'INNER JOIN' => $this->juncaoGrupo('Ticket'),
                'WHERE'      => $this->base('Ticket'),
            ]) as $r) {
                $gid = (int) $r['groups_id'];
                $chave = $gid . '-' . $r['id'];
                if (isset($vistos[$chave])) {
                    continue; // mesmo chamado ligado duas vezes ao grupo (atribuído e observador)
                }
                $vistos[$chave] = true;
                $c = $this->classificarSla($r, $agora);
                foreach (['tto', 'ttr'] as $k) {
                    if ($c[$k] !== null) {
                        $dados['grupos'][$gid]['sla'][$c[$k]]++;
                    }
                }
            }

            // Parados há mais tempo
            if ($this->paradosLimite > 0) {
                foreach ($this->parados($usuarios) as $gid => $lista) {
                    $dados['grupos'][$gid]['parados'] = $lista;
                }
            }
        }

        $dados['usuarios'] = self::nomesUsuarios($usuarios);
        return $dados;
    }

    /** Chamados há mais tempo no mesmo status (Novos sempre; demais só se abertos há mais de X horas) */
    private function parados(array &$usuarios): array
    {
        global $DB;
        $agora = time();
        $corte = date('Y-m-d H:i:s', $agora - $this->paradoHoras * 3600);
        $candidatos = [];
        foreach ($DB->request([
            'SELECT'     => ['g.groups_id', 'i.id', 'i.name', 'i.status', 'i.date', 'i.entities_id'],
            'FROM'       => 'glpi_tickets AS i',
            'INNER JOIN' => $this->juncaoGrupo('Ticket'),
            // array_merge (e não +): a restrição de entidades também usa chave numérica
            'WHERE'      => array_merge($this->base('Ticket'), [['OR' => [['i.status' => Ticket::INCOMING], ['i.date' => ['<=', $corte]]]]]),
        ]) as $r) {
            $tid = (int) $r['id'];
            $candidatos[$tid] ??= ['id' => $tid, 'name' => (string) $r['name'], 'status' => (int) $r['status'], 'date' => $r['date'], 'entities_id' => (int) $r['entities_id'], 'grupos' => []];
            $candidatos[$tid]['grupos'][(int) $r['groups_id']] = true;
        }
        if (!$candidatos) {
            return [];
        }
        $ids = array_keys($candidatos);

        $mudanca = [];
        foreach ($DB->request([
            'SELECT'  => ['items_id', new \Glpi\DBAL\QueryExpression('MAX(' . $DB->quoteName('date_mod') . ') AS ' . $DB->quoteName('ultima'))],
            'FROM'    => 'glpi_logs',
            'WHERE'   => ['itemtype' => 'Ticket', 'items_id' => $ids, 'id_search_option' => 12],
            'GROUPBY' => ['items_id'],
        ]) as $r) {
            $mudanca[(int) $r['items_id']] = strtotime((string) $r['ultima']);
        }
        $tecnicos = [];
        foreach ($DB->request(['SELECT' => ['tickets_id', 'users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $ids, 'type' => CommonITILActor::ASSIGN, 'users_id' => ['>', 0]], 'ORDER' => 'id ASC']) as $r) {
            $tecnicos[(int) $r['tickets_id']] ??= (int) $r['users_id'];
            $usuarios[(int) $r['users_id']] = (int) $r['users_id'];
        }
        $entidades = self::nomesEntidades(array_column($candidatos, 'entities_id'));

        $porGrupo = [];
        foreach ($candidatos as $tid => $c) {
            $abertura = strtotime((string) $c['date']) ?: $agora;
            $item = [
                'id'          => $tid,
                'name'        => $c['name'],
                'status'      => $c['status'],
                'no_status'   => max(0, $agora - ($mudanca[$tid] ?? $abertura)),
                'aberto'      => max(0, $agora - $abertura),
                'tecnico'     => $tecnicos[$tid] ?? 0,
                'entidade'    => $entidades[$c['entities_id']] ?? '',
            ];
            foreach (array_keys($c['grupos']) as $gid) {
                $porGrupo[$gid][] = $item;
            }
        }
        foreach ($porGrupo as $gid => $lista) {
            usort($lista, fn($a, $b) => $b['no_status'] <=> $a['no_status']);
            $porGrupo[$gid] = array_slice($lista, 0, $this->paradosLimite);
        }
        return $porGrupo;
    }

    // =====================================================================
    // Rankings
    // =====================================================================

    public function inicioPeriodo(): int
    {
        return match ($this->rankingPeriodo) {
            '7dias'  => strtotime(date('Y-m-d 00:00:00', strtotime('-6 days'))),
            '30dias' => strtotime(date('Y-m-d 00:00:00', strtotime('-29 days'))),
            default  => strtotime(date('Y-m-d 00:00:00')),
        };
    }

    public function rankings(): array
    {
        return $this->emCache('rankings', fn() => $this->coletarRankings());
    }

    private function coletarRankings(): array
    {
        global $DB;
        $dados = ['ociosos' => [], 'esperando' => [], 'atendendo' => [], 'entidades' => [], 'usuarios' => [], 'entidades_nomes' => [], 'inicio' => $this->inicioPeriodo()];
        if (!$this->grupos) {
            return $dados;
        }
        $inicio = date('Y-m-d H:i:s', $dados['inicio']);
        $emAtendimento = array_values(array_intersect([Ticket::ASSIGNED, Ticket::PLANNED], self::statusAbertos('Ticket')));
        $usuarios = [];

        // Técnicos atribuídos a chamados em aberto dos grupos (e em quais grupos aparecem)
        $tecGrupos = [];
        foreach ($DB->request([
            'SELECT'     => ['g.groups_id', 'u.users_id'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_tickets AS i',
            'INNER JOIN' => $this->juncaoGrupo('Ticket') + ['glpi_tickets_users AS u' => ['ON' => ['u' => 'tickets_id', 'i' => 'id']]],
            'WHERE'      => $this->base('Ticket') + ['u.type' => CommonITILActor::ASSIGN, 'u.users_id' => ['>', 0]],
        ]) as $r) {
            $tecGrupos[(int) $r['users_id']][(int) $r['groups_id']] = true;
        }

        // Última vez que cada técnico "puxou" um chamado: mudou o status para em atendimento.
        // O histórico guarda o autor como "Nome (ID)".
        $ultimo = [];
        if ($tecGrupos && $emAtendimento) {
            foreach ($DB->request([
                'SELECT'  => ['user_name', new \Glpi\DBAL\QueryExpression('MAX(' . $DB->quoteName('date_mod') . ') AS ' . $DB->quoteName('ultima'))],
                'FROM'    => 'glpi_logs',
                'WHERE'   => ['itemtype' => 'Ticket', 'id_search_option' => 12, 'new_value' => array_map('strval', $emAtendimento), 'date_mod' => ['>=', $inicio]],
                'GROUPBY' => ['user_name'],
            ]) as $r) {
                if (preg_match('/\((\d+)\)\s*$/', (string) $r['user_name'], $m)) {
                    $uid = (int) $m[1];
                    $ts = strtotime((string) $r['ultima']);
                    if (isset($tecGrupos[$uid]) && $ts !== false && $ts > ($ultimo[$uid] ?? 0)) {
                        $ultimo[$uid] = $ts;
                    }
                }
            }
        }
        foreach ($tecGrupos as $uid => $grupos) {
            $dados['ociosos'][] = ['uid' => $uid, 'ultimo' => $ultimo[$uid] ?? 0, 'grupos' => array_keys($grupos)];
            $usuarios[$uid] = $uid;
        }

        // Chamados novos sem técnico, abertos no período (mais antigos primeiro)
        $novos = [];
        foreach ($DB->request([
            'SELECT'     => ['g.groups_id', 'i.id', 'i.name', 'i.date'],
            'FROM'       => 'glpi_tickets AS i',
            'INNER JOIN' => $this->juncaoGrupo('Ticket'),
            'WHERE'      => $this->base('Ticket', [Ticket::INCOMING]) + ['i.date' => ['>=', $inicio]],
            'ORDER'      => 'i.date ASC',
        ]) as $r) {
            $novos[] = ['gid' => (int) $r['groups_id'], 'id' => (int) $r['id'], 'name' => (string) $r['name'], 'ts' => (int) strtotime((string) $r['date'])];
        }
        if ($novos) {
            $comTecnico = [];
            foreach ($DB->request(['SELECT' => ['tickets_id'], 'DISTINCT' => true, 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => array_column($novos, 'id'), 'type' => CommonITILActor::ASSIGN, 'users_id' => ['>', 0]]]) as $r) {
                $comTecnico[(int) $r['tickets_id']] = true;
            }
            $dados['esperando'] = array_values(array_filter($novos, fn($n) => !isset($comTecnico[$n['id']])));
        }

        // Técnicos com mais chamados em atendimento agora
        if ($emAtendimento) {
            foreach ($DB->request([
                'SELECT'     => ['g.groups_id', 'u.users_id', new \Glpi\DBAL\QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('i.id') . ') AS ' . $DB->quoteName('n'))],
                'FROM'       => 'glpi_tickets AS i',
                'INNER JOIN' => $this->juncaoGrupo('Ticket') + ['glpi_tickets_users AS u' => ['ON' => ['u' => 'tickets_id', 'i' => 'id']]],
                'WHERE'      => $this->base('Ticket', $emAtendimento) + ['u.type' => CommonITILActor::ASSIGN, 'u.users_id' => ['>', 0]],
                'GROUPBY'    => ['g.groups_id', 'u.users_id'],
            ]) as $r) {
                $dados['atendendo'][(int) $r['groups_id']][(int) $r['users_id']] = (int) $r['n'];
                $usuarios[(int) $r['users_id']] = (int) $r['users_id'];
            }
        }

        // Entidades que mais abriram chamados no período
        $entidades = [];
        foreach ($DB->request([
            'SELECT'     => ['g.groups_id', 'i.entities_id', new \Glpi\DBAL\QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('i.id') . ') AS ' . $DB->quoteName('n'))],
            'FROM'       => 'glpi_tickets AS i',
            'INNER JOIN' => $this->juncaoGrupo('Ticket'),
            'WHERE'      => [
                'i.is_deleted' => 0, 'g.type' => $this->tiposVinculo, 'g.groups_id' => $this->grupos, 'i.date' => ['>=', $inicio],
            ] + self::restricao('i'),
            'GROUPBY'    => ['g.groups_id', 'i.entities_id'],
        ]) as $r) {
            $dados['entidades'][(int) $r['groups_id']][(int) $r['entities_id']] = (int) $r['n'];
            $entidades[(int) $r['entities_id']] = (int) $r['entities_id'];
        }

        $dados['usuarios'] = self::nomesUsuarios($usuarios);
        $dados['entidades_nomes'] = self::nomesEntidades($entidades);
        return $dados;
    }

    // =====================================================================
    // Lista de chamados por faixa de SLA
    // =====================================================================

    public function chamadosSla(array $grupos, string $faixa): array
    {
        global $DB;
        if (!isset(self::FAIXAS[$faixa]) || !$grupos) {
            return [];
        }
        $tto = str_starts_with($faixa, 'tto');
        $where = $this->base('Ticket');
        $where['g.groups_id'] = array_values(array_intersect(array_map('intval', $grupos), $this->grupos));
        if (!$where['g.groups_id']) {
            return [];
        }
        $where[] = $tto ? ['i.slas_id_tto' => ['>', 0]] : ['i.slas_id_ttr' => ['>', 0]];

        $agora = time();
        $lista = [];
        foreach ($DB->request([
            'SELECT'     => ['i.id', 'i.name', 'i.status', 'i.date', 'i.time_to_own', 'i.time_to_resolve', 'i.takeintoaccountdate', 'i.slas_id_tto', 'i.slas_id_ttr', 'i.entities_id', 'g.groups_id'],
            'FROM'       => 'glpi_tickets AS i',
            'INNER JOIN' => $this->juncaoGrupo('Ticket'),
            'WHERE'      => $where,
        ]) as $r) {
            $tid = (int) $r['id'];
            if (isset($lista[$tid])) {
                $lista[$tid]['grupos'][(int) $r['groups_id']] = true;
                continue;
            }
            $c = $this->classificarSla($r, $agora);
            if ($c[$tto ? 'tto' : 'ttr'] !== $faixa) {
                continue;
            }
            $lista[$tid] = $r + $c + ['grupos' => [(int) $r['groups_id'] => true]];
        }
        if (!$lista) {
            return [];
        }
        $ids = array_keys($lista);
        $atores = [];
        foreach ($DB->request(['SELECT' => ['tickets_id', 'users_id', 'type'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $ids, 'type' => [CommonITILActor::REQUESTER, CommonITILActor::ASSIGN], 'users_id' => ['>', 0]], 'ORDER' => 'id ASC']) as $r) {
            $atores[(int) $r['tickets_id']][(int) $r['type']] ??= (int) $r['users_id'];
        }
        $nomes = self::nomesUsuarios(array_merge(...array_map('array_values', $atores ?: [[]])));
        $entidades = self::nomesEntidades(array_column($lista, 'entities_id'));
        $slas = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_slas', 'WHERE' => ['id' => array_values(array_unique(array_merge(array_column($lista, 'slas_id_tto'), array_column($lista, 'slas_id_ttr'), [0])))]]) as $s) {
            $slas[(int) $s['id']] = (string) $s['name'];
        }
        foreach ($lista as $tid => &$t) {
            $t['requerente'] = $nomes[$atores[$tid][CommonITILActor::REQUESTER] ?? 0] ?? '';
            $t['tecnico'] = $nomes[$atores[$tid][CommonITILActor::ASSIGN] ?? 0] ?? '';
            $t['entidade'] = $entidades[(int) $t['entities_id']] ?? '';
            $t['sla_nome'] = $slas[(int) ($tto ? $t['slas_id_tto'] : $t['slas_id_ttr'])] ?? '';
            $t['pct'] = (float) ($tto ? $t['tto_pct'] : $t['ttr_pct']);
        }
        unset($t);
        usort($lista, fn($a, $b) => $b['pct'] <=> $a['pct']);
        return $lista;
    }
}
