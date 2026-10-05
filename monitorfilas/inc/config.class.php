<?php

/**
 * Plugin Monitor de Filas - configurações, acesso e utilitários comuns
 */
class PluginMonitorfilasConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    /** Tipos ITIL acompanhados */
    public const TIPOS = [
        'Ticket'  => ['rotulo' => 'Chamados', 'icone' => 'ti ti-ticket'],
        'Problem' => ['rotulo' => 'Problemas', 'icone' => 'ti ti-alert-triangle'],
        'Change'  => ['rotulo' => 'Mudanças', 'icone' => 'ti ti-arrows-exchange'],
    ];

    /** Como o grupo aparece no item: observador (type 3), atribuído (type 2) ou qualquer um dos dois */
    public const VINCULOS = [
        'atribuido'  => 'Grupo atribuído (técnico)',
        'observador' => 'Grupo observador',
        'ambos'      => 'Atribuído ou observador',
    ];

    public const INTERVALOS = [30 => '30 segundos', 60 => '1 minuto', 120 => '2 minutos', 180 => '3 minutos', 300 => '5 minutos', 600 => '10 minutos'];

    public const PERIODOS = ['hoje' => 'Hoje', '7dias' => 'Últimos 7 dias', '30dias' => 'Últimos 30 dias'];

    public static function getTypeName($nb = 0): string
    {
        return 'Monitor de Filas';
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'acesso_perfis'   => [],
            'acesso_usuarios' => [],
            'grupos'          => [],
            'tipos'           => array_keys(self::TIPOS),
            // Padrão abrangente: o grupo pode estar como atribuído ou como observador nos itens
            'vinculo'         => 'ambos',
            'intervalo'       => '60',
            'sla_critico'     => '80',
            'parado_horas'    => '24',
            'parados_limite'  => '3',
            'ranking_limite'  => '10',
            'ranking_periodo' => 'hoje',
        ];
    }

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['value'], 'FROM' => 'glpi_plugin_monitorfilas_configs', 'WHERE' => ['name' => $name], 'LIMIT' => 1]) as $row) {
            return $row['value'];
        }
        if ($default === null) {
            $padrao = self::padroes()[$name] ?? null;
            return is_array($padrao) ? json_encode($padrao) : $padrao;
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => 'glpi_plugin_monitorfilas_configs', 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            return $DB->update('glpi_plugin_monitorfilas_configs', ['value' => $value], ['name' => $name]);
        }
        return $DB->insert('glpi_plugin_monitorfilas_configs', ['name' => $name, 'value' => $value]);
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_monitorfilas_configs']) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value)));
    }

    public static function ids(string $name): array
    {
        return array_values(array_unique(array_filter(array_map('intval', self::getArrayConfig($name)), fn($v) => $v > 0)));
    }

    public static function inteiro(string $name, int $min, int $max): int
    {
        return max($min, min($max, (int) self::getConfig($name)));
    }

    /** Tipos habilitados, na ordem padrão */
    public static function tipos(): array
    {
        $marcados = self::getArrayConfig('tipos');
        $lista = array_values(array_filter(array_keys(self::TIPOS), fn($t) => in_array($t, $marcados, true)));
        return $lista ?: ['Ticket'];
    }

    public static function vinculo(): string
    {
        $v = (string) self::getConfig('vinculo');
        return isset(self::VINCULOS[$v]) ? $v : 'ambos';
    }

    /** Valores de "type" nas tabelas de grupos dos itens (2 = atribuído, 3 = observador) */
    public static function tiposVinculo(): array
    {
        return match (self::vinculo()) {
            'observador' => [CommonITILActor::OBSERVER],
            'atribuido'  => [CommonITILActor::ASSIGN],
            default      => [CommonITILActor::ASSIGN, CommonITILActor::OBSERVER],
        };
    }

    // =====================================================================
    // Acesso
    // =====================================================================

    /** Administradores sempre; demais pelos perfis ou usuários marcados na configuração */
    public static function podeVer(): bool
    {
        if (!Session::getLoginUserID()) {
            return false;
        }
        if (self::ehAdmin()) {
            return true;
        }
        $perfil = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        return in_array($perfil, self::ids('acesso_perfis'), true)
            || in_array((int) Session::getLoginUserID(), self::ids('acesso_usuarios'), true);
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/monitorfilas/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** URL de arquivo de public/ com versão (o GLPI 11/12 serve public/ em /plugins/<nome>/) */
    public static function urlAsset(string $caminho): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/monitorfilas/' . preg_replace('#^public/#', '', $caminho) . '?v=' . PLUGIN_MONITORFILAS_VERSION;
    }

    public static function assets(): string
    {
        return '<link rel="stylesheet" href="' . self::e(self::urlAsset('public/css/monitorfilas.css')) . '">'
            . '<script src="' . self::e(self::urlAsset('public/js/monitorfilas.js')) . '"></script>';
    }

    /** "3 horas", "2 dias"... */
    public static function duracao(int $segundos): string
    {
        $segundos = max(0, $segundos);
        if ($segundos < 3600) {
            $m = max(1, intdiv($segundos, 60));
            return $m . ($m === 1 ? ' minuto' : ' minutos');
        }
        $h = intdiv($segundos, 3600);
        if ($h < 48) {
            return $h . ($h === 1 ? ' hora' : ' horas');
        }
        $d = intdiv($segundos, 86400);
        if ($d < 14) {
            return $d . ' dias';
        }
        if ($d < 60) {
            return intdiv($d, 7) . ' semanas';
        }
        $meses = intdiv($d, 30);
        return $meses . ($meses === 1 ? ' mês' : ' meses');
    }

    public static function listarPerfis(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $r) {
            $lista[(int) $r['id']] = (string) $r['name'];
        }
        return $lista;
    }

    /** glpi_groups não tem is_deleted */
    public static function listarGrupos(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'completename'], 'FROM' => 'glpi_groups', 'ORDER' => 'completename ASC']) as $r) {
            $lista[(int) $r['id']] = (string) ($r['completename'] ?: $r['name']);
        }
        return $lista;
    }

    public static function listarUsuarios(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'firstname', 'realname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => ['realname ASC', 'firstname ASC', 'name ASC'],
        ]) as $u) {
            $partes = array_filter([trim((string) $u['firstname']), trim((string) $u['realname'])]);
            $nome = $partes ? implode(' ', $partes) : (string) $u['name'];
            $lista[(int) $u['id']] = $nome . ($partes ? ' (' . $u['name'] . ')' : '');
        }
        return $lista;
    }

    /** Multiselect com pesquisa, marcar todos e selecionados primeiro */
    public static function multiselect(string $name, array $opcoes, array $selecionados, string $placeholder = 'Selecione...', bool $comCampo = true): string
    {
        $selecionados = array_map('intval', $selecionados);
        $marcados = [];
        $demais = [];
        foreach ($opcoes as $id => $rotulo) {
            if (in_array((int) $id, $selecionados, true)) {
                $marcados[$id] = $rotulo;
            } else {
                $demais[$id] = $rotulo;
            }
        }
        asort($marcados, SORT_NATURAL | SORT_FLAG_CASE);
        asort($demais, SORT_NATURAL | SORT_FLAG_CASE);

        $h = '<div class="monitorfilas-ms" data-monitorfilas-ms data-placeholder="' . self::e($placeholder) . '">';
        if ($comCampo) {
            $h .= '<input type="hidden" name="' . self::e($name) . '[]" value="0">';
        }
        $h .= '<button type="button" class="monitorfilas-ms-cabecalho form-select form-select-sm" data-monitorfilas-ms-abrir><span class="monitorfilas-ms-texto"></span></button>';
        $h .= '<div class="monitorfilas-ms-dropdown" hidden>';
        $h .= '<div class="monitorfilas-ms-topo"><input type="text" class="form-control form-control-sm monitorfilas-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>';
        $h .= '<label class="monitorfilas-ms-todos"><input type="checkbox" class="monitorfilas-check" data-monitorfilas-ms-todos> Marcar/desmarcar todos</label>';
        $h .= '<div class="monitorfilas-ms-opcoes">';
        foreach ($marcados + $demais as $id => $rotulo) {
            $marcado = isset($marcados[$id]);
            $h .= '<label class="monitorfilas-ms-opcao' . ($marcado ? ' selected' : '') . '" data-label="' . self::e(mb_strtolower((string) $rotulo)) . '">'
                . '<input type="checkbox" class="monitorfilas-check"' . ($comCampo ? ' name="' . self::e($name) . '[]"' : '') . ' value="' . (int) $id . '"' . ($marcado ? ' checked' : '') . '>'
                . '<span>' . self::e($rotulo) . '</span></label>';
        }
        $h .= '</div></div><div class="monitorfilas-ms-contador"></div></div>';
        return $h;
    }
}
