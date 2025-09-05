<?php
// Definir constantes do plugin
if (!defined('PLUGIN_MANUALIPCHECK_VERSION')) {
    define('PLUGIN_MANUALIPCHECK_VERSION', '1.5.1');
}
if (!defined('PLUGIN_MANUALIPCHECK_MIN_GLPI')) {
    define('PLUGIN_MANUALIPCHECK_MIN_GLPI', '10.0.0');
}
if (!defined('PLUGIN_MANUALIPCHECK_MAX_GLPI')) {
    define('PLUGIN_MANUALIPCHECK_MAX_GLPI', '10.1.99');
}

// Declarar conformidade com CSRF no escopo global
global $PLUGIN_HOOKS;
$PLUGIN_HOOKS['csrf_compliant']['manualipcheck'] = true;

/**
 * Função para varrer recursivamente os JSONs de inventário e contabilizar IPs manuais
 */
function plugin_manualipcheck_scan_inventory() {
    $inventory_dir = defined('GLPI_INVENTORY_DIR') ? GLPI_INVENTORY_DIR : '/var/www/html/glpi/files/_inventories/';
    $inventory_dir = rtrim($inventory_dir, '/') . '/computer/';
    $storage_dir   = GLPI_PLUGIN_DOC_DIR . '/manualipcheck/storage/';
    $file_path     = $storage_dir . 'manual_ips.txt';
    $count_file    = $storage_dir . 'processed_count.json';

    error_log("[" . date('Y-m-d H:i:s') . "] [SCAN] Iniciando varredura no diretório $inventory_dir.");

    if (!is_dir($inventory_dir) || !is_readable($inventory_dir)) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Diretório de inventário $inventory_dir não existe ou não é legível.");
        return 0;
    }
    if (!is_dir($storage_dir)) {
        mkdir($storage_dir, 0755, true);
    }
    if (!is_writable($storage_dir)) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Diretório $storage_dir não é gravável.");
        return 0;
    }

    // Carregar IPs existentes
    $existing_ips = [];
    if (file_exists($file_path)) {
        $content = file_get_contents($file_path);
        $existing_ips = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Falha ao decodificar $file_path: " . json_last_error_msg());
            $existing_ips = [];
        }
    }

    $manual_ips   = [];
    $existing_map = [];

    foreach ($existing_ips as $ip_data) {
        $key = ($ip_data['computer'] ?? 'N/A') . '|' . ($ip_data['mac'] ?? 'N/A');
        $existing_map[$key] = $ip_data;
    }

    $json_count = 0;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inventory_dir));
    $processed_jsons = [];
    foreach ($rii as $file) {
        if ($file->isDir() || strtolower($file->getExtension()) !== 'json') continue;

        $json_count++;
        $processed_jsons[$file->getPathname()] = [
            'file' => $file->getPathname(),
            'date' => date('Y-m-d H:i:s')
        ];
        error_log("[" . date('Y-m-d H:i:s') . "] [SCAN] Processando arquivo $json_count: " . $file->getPathname());

        $json_content = file_get_contents($file->getPathname());
        if ($json_content === false) {
            error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Falha ao ler arquivo: " . $file->getPathname());
            continue;
        }

        $data = json_decode($json_content, true);
        // Ajuste: buscar hardware/networks dentro de 'content' se necessário
        if (isset($data['content'])) {
            $hardware = $data['content']['hardware'] ?? null;
            $networks = $data['content']['networks'] ?? null;
        } else {
            $hardware = $data['hardware'] ?? null;
            $networks = $data['networks'] ?? null;
        }
        if (!$hardware || !$networks || !is_array($networks)) {
            error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] JSON inválido em: " . $file->getPathname() . " - Campos 'hardware' ou 'networks' ausentes.");
            continue;
        }

        $computer_name  = $hardware['name'] ?? 'N/A';
        $workgroup      = $hardware['workgroup'] ?? 'N/A';
        $last_user      = $hardware['lastloggeduser'] ?? 'N/A';

        foreach ($networks as $network) {
            $mac = $network['mac'] ?? 'N/A';
            $key = $computer_name . '|' . $mac;
            $ipaddress = $network['ipaddress'] ?? '';
            $name = $network['name'] ?? '';
            $description = $network['description'] ?? '';
            $ipdhcp = $network['ipdhcp'] ?? '';

            // Filtros: desconsiderar IPv6, adaptadores virtuais, adaptadores com DHCP
            if (strpos($ipaddress, ':') !== false) continue; // IPv6
            if (stripos($name, 'Virtual Ethernet Adapter') !== false || stripos($description, 'Virtual Ethernet Adapter') !== false) continue;
            if (!empty($ipdhcp)) continue; // Tem DHCP

            if (class_exists('PluginManualipcheckManualipcheck') && PluginManualipcheckManualipcheck::isManualIP($network)) {
                $manual_ips[$key] = [
                    'ip'        => $ipaddress,
                    'mac'       => $mac,
                    'computer'  => $computer_name,
                    'workgroup' => $workgroup,
                    'last_user' => $last_user,
                    'date'      => date('Y-m-d H:i:s')
                ];
            } elseif (isset($existing_map[$key])) {
                unset($existing_map[$key]); // remove se mudou para DHCP
            }
        }
        if ($json_count % 25 === 0) {
            error_log("[" . date('Y-m-d H:i:s') . "] [SCAN] Progresso: $json_count JSONs processados.");
        }
    }

    // Mesclar dados
    $updated_ips      = array_merge($existing_map, $manual_ips);
    $thirty_days_ago  = strtotime('-30 days');
    $filtered_data    = array_filter($updated_ips, fn($entry) => isset($entry['date']) && strtotime($entry['date']) >= $thirty_days_ago);

    file_put_contents($file_path, json_encode(array_values($filtered_data), JSON_PRETTY_PRINT));

    // Atualizar contagem detalhada de JSONs
    $json_log_file = $storage_dir . 'jsons_processed_log.json';
    $json_log = [];
    if (file_exists($json_log_file)) {
        $log_content = file_get_contents($json_log_file);
        $json_log = json_decode($log_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_log = [];
        }
    }
    // Converter formato antigo (array) para mapa
    $log_map = [];
    if (is_array($json_log) && isset($json_log[0])) {
        foreach ($json_log as $entry) {
            if (isset($entry['file'], $entry['date'])) {
                // Manter a data mais recente se duplicado
                if (!isset($log_map[$entry['file']]) || strtotime($entry['date']) > strtotime($log_map[$entry['file']]['date'])) {
                    $log_map[$entry['file']] = ['file' => $entry['file'], 'date' => $entry['date']];
                }
            }
        }
    } else {
        $log_map = $json_log;
    }
    // Adiciona os arquivos processados nesta execução
    $log_map = array_merge($log_map, $processed_jsons);
    file_put_contents($json_log_file, json_encode(array_values($log_map), JSON_PRETTY_PRINT));

    // Contar JSONs dos últimos 30 dias
    $thirty_days_ago = strtotime('-30 days');
    $jsons_last_30 = array_filter($log_map, fn($entry) => isset($entry['date']) && strtotime($entry['date']) >= $thirty_days_ago);
    $json_count_30 = count($jsons_last_30);

    error_log("[" . date('Y-m-d H:i:s') . "] [SCAN] Concluído: " . count($filtered_data) . " IPs manuais ativos. Foram processados $json_count_30 JSONs nos últimos 30 dias.");
    return count($filtered_data);
}

/**
 * Inicialização do plugin
 */
function plugin_init_manualipcheck() {
    global $PLUGIN_HOOKS;

    // Verificar se a sessão está iniciada e o usuário está autenticado
    if (!isset($_SESSION['glpiID']) || !Session::getLoginUserID()) {
        error_log("[" . date('Y-m-d H:i:s') . "] [INFO] Sessão não iniciada ou usuário não autenticado para plugin_manualipcheck.");
        return;
    }

    // Verificar se estamos em uma página relevante
    $current_page = $_SERVER['SCRIPT_NAME'] ?? '';
    $relevant_pages = [
        '/plugins/manualipcheck/front/manualipcheck.php',
        '/front/central.php',
        '/front/marketplace.php'
    ];
    if (!in_array($current_page, $relevant_pages)) {
        return;
    }

    if (!defined('GLPI_VERSION') || !class_exists('Session')) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] GLPI não inicializado corretamente.");
        return;
    }

    if (version_compare(GLPI_VERSION, PLUGIN_MANUALIPCHECK_MIN_GLPI, '<') ||
        version_compare(GLPI_VERSION, PLUGIN_MANUALIPCHECK_MAX_GLPI, '>')) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Versão GLPI (" . GLPI_VERSION . ") não compatível.");
        if (class_exists('Session') && method_exists('Session', 'addMessageAfterRedirect')) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('version_incompatible', 'manualipcheck'),
                    PLUGIN_MANUALIPCHECK_MIN_GLPI,
                    PLUGIN_MANUALIPCHECK_MAX_GLPI,
                    GLPI_VERSION
                ),
                false,
                ERROR
            );
        }
        return;
    }

    $user_id    = $_SESSION['glpiID'] ?? 'desconhecido';
    $profile_id = $_SESSION['glpiactiveprofile']['id'] ?? 'desconhecido';

    if (Session::haveRight('plugin_manualipcheck', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['manualipcheck'] = [
            'tools' => PluginManualipcheckManualipcheck::class
        ];
        error_log("[" . date('Y-m-d H:i:s') . "] [OK] Usuário $user_id (perfil $profile_id) inicializou o plugin com permissão READ.");
    } else {
        error_log("[" . date('Y-m-d H:i:s') . "] [INFO] Usuário $user_id (perfil $profile_id) não tem permissão READ.");
    }
}

/**
 * Versão do plugin
 */
function plugin_version_manualipcheck() {
    return [
        'name'        => __('Verificador de IP Manual', 'manualipcheck'),
        'version'     => PLUGIN_MANUALIPCHECK_VERSION,
        'author'      => 'vlperini',
        'license'     => 'GPLv2+',
        'homepage'    => 'https://github.com/vlperini/glpi-manualipcheck',
        'description' => __('Identifica computadores com IPs configurados manualmente no inventário GLPI.', 'manualipcheck'),
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MANUALIPCHECK_MIN_GLPI,
                'max' => PLUGIN_MANUALIPCHECK_MAX_GLPI
            ],
            'php' => [
                'min' => '7.4'
            ]
        ]
    ];
}

/**
 * Instalação do plugin
 */
function plugin_manualipcheck_install() {
    global $DB;

    error_log("[" . date('Y-m-d H:i:s') . "] [INSTALL] Iniciando instalação.");

    if (!$DB instanceof DBmysql) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Conexão com banco não inicializada.");
        return false;
    }

    $inventory_dir = defined('GLPI_INVENTORY_DIR') ? GLPI_INVENTORY_DIR : '/var/www/html/glpi/files/_inventories/';
    $inventory_dir = rtrim($inventory_dir, '/') . '/computer/';
    if (!is_dir($inventory_dir) || !is_readable($inventory_dir)) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Diretório de inventário $inventory_dir inválido.");
        return false;
    }

    try {
        // Adicionar permissão aos perfis 4 e 12
        $profile_ids = [4, 12];
        foreach ($profile_ids as $profile_id) {
            $query = "INSERT INTO glpi_profilerights (profiles_id, name, rights)
                      VALUES ($profile_id, 'plugin_manualipcheck', 1)
                      ON DUPLICATE KEY UPDATE rights = 1";
            if (!$DB->query($query)) {
                error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Falha ao adicionar permissão READ para perfil $profile_id: " . $DB->error());
                return false;
            }

            // Verificar se o perfil existe
            $query = "SELECT id FROM glpi_profiles WHERE id = $profile_id";
            $result = $DB->query($query);
            if (!$result || $DB->numrows($result) == 0) {
                error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Perfil $profile_id não encontrado no banco de dados.");
                return false;
            }
        }

        $storage_dir = GLPI_PLUGIN_DOC_DIR . '/manualipcheck/storage/';
        if (!is_dir($storage_dir)) {
            mkdir($storage_dir, 0755, true);
        }
        if (!is_writable($storage_dir)) {
            error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Diretório $storage_dir não é gravável.");
            return false;
        }

        // Executa scan em background, sem redirecionamento
        if (class_exists('PluginManualipcheckManualipcheck')) {
            plugin_manualipcheck_scan_inventory();
        }

        error_log("[" . date('Y-m-d H:i:s') . "] [INSTALL] Concluída com sucesso.");
        return true;
    } catch (Exception $e) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Falha na instalação: " . $e->getMessage());
        return false;
    }
}

/**
 * Desinstalação do plugin
 */
function plugin_manualipcheck_uninstall() {
    global $DB;

    error_log("[" . date('Y-m-d H:i:s') . "] [UNINSTALL] Iniciando desinstalação.");

    if (!$DB instanceof DBmysql) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Conexão com banco não inicializada.");
        return false;
    }

    try {
        $delete = "DELETE FROM glpi_profilerights WHERE name = 'plugin_manualipcheck'";
        if (!$DB->query($delete)) {
            error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Falha ao remover permissões: " . $DB->error());
            return false;
        }

        error_log("[" . date('Y-m-d H:i:s') . "] [UNINSTALL] Concluída com sucesso.");
        return true;
    } catch (Exception $e) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Falha na desinstalação: " . $e->getMessage());
        return false;
    }
}

/**
 * Hook para processar JSONs de inventário
 */
function plugin_manualipcheck_pre_item_add($item) {
    if ($item instanceof NetworkPort && isset($item->input['_json']) && is_string($item->input['_json'])) {
        $data = validate_json($item->input['_json']);
        if ($data === false) return;

        // Inicializar $item->input como array, se necessário
        if (!is_array($item->input)) {
            $item->input = [];
        }

        // Definir date_mod apenas se não existir
        if (!isset($item->input['date_mod'])) {
            $item->input['date_mod'] = date('Y-m-d H:i:s');
        }

        $manual_ips = process_network_data($data);
        update_manual_ips($manual_ips, $data);
        update_processed_count(!empty($manual_ips));
    }
}

/**
 * Validação de JSON
 */
function validate_json($json_string) {
    $data = json_decode($json_string, true);
    // Ajuste: buscar hardware/networks dentro de 'content' se necessário
    if (isset($data['content'])) {
        $hardware = $data['content']['hardware'] ?? null;
        $networks = $data['content']['networks'] ?? null;
    } else {
        $hardware = $data['hardware'] ?? null;
        $networks = $data['networks'] ?? null;
    }
    if (!$hardware || !$networks || !is_array($hardware) || !is_array($networks)) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] JSON inválido: campos 'hardware' ou 'networks' ausentes ou inválidos.");
        return false;
    }
    // Retorna estrutura compatível
    return ['hardware' => $hardware, 'networks' => $networks];
}

/**
 * Processamento dos dados de rede
 */
function process_network_data($data) {
    $manual_ips   = [];
    $hardware     = $data['hardware'];
    $computer     = $hardware['name'] ?? 'N/A';
    $workgroup    = $hardware['workgroup'] ?? 'N/A';
    $last_user    = $hardware['lastloggeduser'] ?? 'N/A';

    foreach ($data['networks'] as $network) {
        if (class_exists('PluginManualipcheckManualipcheck') && PluginManualipcheckManualipcheck::isManualIP($network)) {
            $manual_ips[] = [
                'ip'        => $network['ipaddress'],
                'mac'       => $network['mac'] ?? 'N/A',
                'computer'  => $computer,
                'workgroup' => $workgroup,
                'last_user' => $last_user,
                'date'      => date('Y-m-d H:i:s')
            ];
        }
    }
    return $manual_ips;
}

/**
 * Atualiza lista de IPs manuais
 */
function update_manual_ips($manual_ips, $data = null) {
    $storage_dir = GLPI_PLUGIN_DOC_DIR . '/manualipcheck/storage/';
    $file_path   = $storage_dir . 'manual_ips.txt';

    if (!is_dir($storage_dir)) mkdir($storage_dir, 0755, true);
    if (!is_writable($storage_dir)) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Diretório $storage_dir não é gravável.");
        return;
    }

    $current_data = [];
    if (file_exists($file_path)) {
        $content = file_get_contents($file_path);
        $current_data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Falha ao decodificar $file_path: " . json_last_error_msg());
            $current_data = [];
        }
    }

    $existing_map = [];
    foreach ($current_data as $ip_data) {
        $key = ($ip_data['computer'] ?? 'N/A') . '|' . ($ip_data['mac'] ?? 'N/A');
        $existing_map[$key] = $ip_data;
    }

    foreach ($manual_ips as $ip_data) {
        $key = ($ip_data['computer'] ?? 'N/A') . '|' . ($ip_data['mac'] ?? 'N/A');
        $existing_map[$key] = $ip_data;
    }

    if ($data && isset($data['hardware'], $data['networks'])) {
        $computer = $data['hardware']['name'] ?? 'N/A';
        foreach ($data['networks'] as $network) {
            $mac = $network['mac'] ?? 'N/A';
            $key = $computer . '|' . $mac;
            if (class_exists('PluginManualipcheckManualipcheck') && !PluginManualipcheckManualipcheck::isManualIP($network) && isset($existing_map[$key])) {
                unset($existing_map[$key]);
            }
        }
    }

    $thirty_days_ago = strtotime('-30 days');
    $filtered_data   = array_filter($existing_map, fn($entry) => isset($entry['date']) && strtotime($entry['date']) >= $thirty_days_ago);

    file_put_contents($file_path, json_encode(array_values($filtered_data), JSON_PRETTY_PRINT));
}

/**
 * Atualiza contagem de JSONs processados
 */
function update_processed_count() {
    $storage_dir = GLPI_PLUGIN_DOC_DIR . '/manualipcheck/storage/';
    $count_file  = $storage_dir . 'processed_count.json';

    if (!is_dir($storage_dir)) mkdir($storage_dir, 0755, true);
    if (!is_writable($storage_dir)) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Diretório $storage_dir não é gravável.");
        return;
    }

    $current_counts = [];
    if (file_exists($count_file)) {
        $content = file_get_contents($count_file);
        $current_counts = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Falha ao decodificar $count_file: " . json_last_error_msg());
            $current_counts = [];
        }
    }

    // Nova lógica: registrar todos os JSONs processados
    $current_counts[date('Y-m-d H:i:s')] = true;
    file_put_contents($count_file, json_encode($current_counts, JSON_PRETTY_PRINT));

    // Adicional: registrar lista detalhada dos arquivos processados
    $json_log_file = $storage_dir . 'jsons_processed_log.json';
    $json_log = [];
    if (file_exists($json_log_file)) {
        $log_content = file_get_contents($json_log_file);
        $json_log = json_decode($log_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_log = [];
        }
    }
    // Adiciona os arquivos processados nesta execução
    if (isset($GLOBALS['processed_jsons']) && is_array($GLOBALS['processed_jsons'])) {
        $json_log = array_merge($json_log, $GLOBALS['processed_jsons']);
        file_put_contents($json_log_file, json_encode($json_log, JSON_PRETTY_PRINT));
    }
}
?>