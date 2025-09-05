<?php
// Tentar carregar o bootstrap do GLPI
$bootstrapCandidates = [
    dirname(__DIR__, 3) . '/inc/includes.php', // Caminho relativo: plugins/manualipcheck/front -> ../../../inc/includes.php
    '/var/www/html/glpi/inc/includes.php',
    '/usr/share/glpi/inc/includes.php',
    '/usr/local/share/glpi/inc/includes.php'
];

$glpi_bootstrapped = false;
foreach ($bootstrapCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        $glpi_bootstrapped = true;
        break;
    }
}

if (!$glpi_bootstrapped) {
    error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Não foi possível carregar o bootstrap do GLPI em manualipcheck.php.");
    die("Erro: Não foi possível carregar o ambiente do GLPI.");
}

if (!defined('READ')) {
    define('READ', 1);
}
// Verificar sessão e permissões
if (!isset($_SESSION['glpiID']) || !Session::getLoginUserID()) {
    error_log("[" . date('Y-m-d H:i:s') . "] [INFO] Sessão não iniciada ou usuário não autenticado em manualipcheck.php.");
    Html::header(__('Verificador de IP Manual', 'manualipcheck'), '', 'tools', 'pluginmanualipcheckmanualipcheck');
    echo "<div class='center'>";
    echo "<p style='color:red'>" . __('Por favor, faça login para acessar esta página.', 'manualipcheck') . "</p>";
    echo "</div>";
    Html::footer();
    exit;
}

// if (!Session::checkRight("plugin_manualipcheck", READ)) {
//    error_log("[" . date('Y-m-d H:i:s') . "] [INFO] Usuário " . ($_SESSION['glpiID'] ?? 'desconhecido') . " (perfil " . ($_SESSION['glpiactiveprofile']['id'] ?? 'desconhecido') . ") não tem permissão READ em manualipcheck.php.");
//    Html::header(__('Verificador de IP Manual', 'manualipcheck'), '', 'tools', 'pluginmanualipcheckmanualipcheck');
//    echo "<div class='center'>";
//    echo "<p style='color:red'>" . __('Você não tem permissão para acessar esta página.', 'manualipcheck') . "</p>";
//    echo "</div>";
//    Html::footer();
//    exit;
//}

Html::header(__('Verificador de IP Manual', 'manualipcheck'), '', 'tools', 'pluginmanualipcheckmanualipcheck');

echo "<div class='center'>";
echo "<h2>" . __('Computadores com IP Manual', 'manualipcheck') . "</h2>";

// Diretório de armazenamento
$storage_dir = GLPI_PLUGIN_DOC_DIR . '/manualipcheck/storage/';
if (!is_dir($storage_dir)) {
    mkdir($storage_dir, 0755, true);
}
if (!is_writable($storage_dir)) {
    error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Diretório de armazenamento $storage_dir não é gravável.");
    echo "<p style='color:red'>" . sprintf(__('Diretório de armazenamento %s não é gravável.', 'manualipcheck'), $storage_dir) . "</p>";
    Html::footer();
    exit;
}

// Limpar dados de armazenamento
if (isset($_POST['clear_storage']) && $_POST['clear_storage'] == '1') {
    $files_to_clear = [
        $storage_dir . 'manual_ips.txt',
        $storage_dir . 'jsons_processed_log.json',
        $storage_dir . 'processed_count.json'
    ];
    foreach ($files_to_clear as $file) {
        if (file_exists($file)) {
            if (unlink($file)) {
                error_log("[" . date('Y-m-d H:i:s') . "] [INFO] Arquivo $file removido com sucesso.");
            } else {
                error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Falha ao remover arquivo $file.");
            }
        }
    }
    Session::addMessageAfterRedirect(
        __('Dados de armazenamento limpos com sucesso.', 'manualipcheck'),
        true,
        INFO
    );
    Html::redirect($_SERVER['PHP_SELF']);
    exit;
}

// Botão de escaneamento manual
$lock_file = $storage_dir . 'scan_in_progress.lock';
$processing = (isset($_GET['scan']) && $_GET['scan'] == "1");

if ($processing && !file_exists($lock_file)) {
    // Cria lock de execução com flock
    $fp = @fopen($lock_file, 'c');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Não foi possível adquirir lock para scan manual. Já existe um scan em andamento.");
        echo '<p><button disabled style="background:#ccc;">' . __('Escaneando...', 'manualipcheck') . '</button></p>';
        echo "<p style='color:red'>" . __('Já existe um scan em andamento.', 'manualipcheck') . "</p>";
        Html::footer();
        exit;
    }

    ftruncate($fp, 0);
    fwrite($fp, getmypid() . ' ' . date('c') . PHP_EOL);
    fflush($fp);

    error_log("[" . date('Y-m-d H:i:s') . "] [manualipcheck] Botão clicado, iniciando scan manual para usuário " . ($_SESSION['glpiID'] ?? 'desconhecido') . " (perfil " . ($_SESSION['glpiactiveprofile']['id'] ?? 'desconhecido') . ").");

    echo '<p><button disabled style="background:#ccc;">' . __('Escaneando...', 'manualipcheck') . '</button></p>';

    // Executa o scan
    require_once dirname(__DIR__) . '/setup.php';
    $total = plugin_manualipcheck_scan_inventory();

    error_log("[" . date('Y-m-d H:i:s') . "] [manualipcheck] Scan concluído. Encontrados $total IPs manuais.");

    // Remove lock
    flock($fp, LOCK_UN);
    fclose($fp);
    if (file_exists($lock_file)) {
        unlink($lock_file);
    }

    // Mensagem para interface GLPI
    Session::addMessageAfterRedirect(
        sprintf(__('Scan concluído. Foram encontrados %d computadores com IP manual.', 'manualipcheck'), $total),
        true,
        INFO
    );

    // Redireciona para a própria página para evitar reenvio
    Html::redirect($_SERVER['PHP_SELF']);
    exit;
} elseif ($processing) {
    error_log("[" . date('Y-m-d H:i:s') . "] [INFO] Scan solicitado, mas já existe um scan em andamento (lock file: $lock_file).");
    echo '<p><button disabled style="background:#ccc;">' . __('Escaneando...', 'manualipcheck') . '</button></p>';
} else {
    // Exibir botões de ação
    echo "<div style='margin-bottom: 10px;'>";
    echo "<form method='get' action='" . $_SERVER['PHP_SELF'] . "' style='display: inline-block; margin-right: 10px;'>";
    echo "<input type='hidden' name='scan' value='1'>";
    echo "<input type='submit' value='" . __('Escanear Manualmente', 'manualipcheck') . "' class='submit'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo "</form>";
    echo "<form method='post' action='" . $_SERVER['PHP_SELF'] . "' style='display: inline-block;'>";
    echo "<input type='hidden' name='clear_storage' value='1'>";
    echo "<input type='submit' value='" . __('Limpar Dados', 'manualipcheck') . "' class='submit' style='background:#c00;color:#fff;'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo "</form>";
    echo "</div>";
}

// Caminhos dos arquivos de resultados
$file_path = $storage_dir . 'manual_ips.txt';
$count_file = $storage_dir . 'jsons_processed_log.json';

// Data limite de 30 dias atrás
$thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));

// Carregar processed_count.json
$processed_count = 0;
if (file_exists($count_file)) {
    $content = file_get_contents($count_file);
    $counts = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Erro ao decodificar $count_file: " . json_last_error_msg());
        echo "<p style='color:red'>" . sprintf(__('Erro ao decodificar %s: %s.', 'manualipcheck'), 'jsons_processed_log.json', json_last_error_msg()) . "</p>";
    } elseif (!empty($counts)) {
        $filtered = array_filter($counts, function($entry) use ($thirty_days_ago) {
            return isset($entry['date']) && strtotime($entry['date']) >= strtotime($thirty_days_ago);
        });
        $processed_count = count($filtered);
    }
}

// Carregar manual_ips.txt
if (file_exists($file_path)) {
    $content = file_get_contents($file_path);
    $manual_ips = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Erro ao decodificar $file_path: " . json_last_error_msg());
        echo "<p style='color:red'>" . sprintf(__('Erro ao decodificar %s: %s.', 'manualipcheck'), 'manual_ips.txt', json_last_error_msg()) . "</p>";
    } elseif (!empty($manual_ips)) {
        // Filtrar IPs dos últimos 30 dias
        $filtered_ips = array_filter($manual_ips, function($entry) use ($thirty_days_ago) {
            return isset($entry['date']) && strtotime($entry['date']) >= strtotime($thirty_days_ago);
        });

        $ip_count = count($filtered_ips);
        echo "<p>" . ($ip_count > 0
            ? "$ip_count IPs manuais detectados. Foram processados $processed_count JSONs nos últimos 30 dias."
            : sprintf(__('Nenhum IP manual detectado. Foram processados %d JSONs nos últimos 30 dias.', 'manualipcheck'), $processed_count)
        ) . "</p>";

        if ($ip_count > 0) {
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr><th>Computador</th><th>Grupo de Trabalho</th><th>Último Usuário</th><th>IP</th><th>MAC</th><th>Data</th></tr>";
            foreach ($filtered_ips as $ip_data) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($ip_data['computer'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</td>";
                echo "<td>" . htmlspecialchars($ip_data['workgroup'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</td>";
                echo "<td>" . htmlspecialchars($ip_data['last_user'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . "</td>";
                echo "<td>" . htmlspecialchars($ip_data['ip'] ?? '-', ENT_QUOTES, 'UTF-8') . "</td>";
                echo "<td>" . htmlspecialchars($ip_data['mac'] ?? '-', ENT_QUOTES, 'UTF-8') . "</td>";
                echo "<td>" . htmlspecialchars($ip_data['date'] ?? '-', ENT_QUOTES, 'UTF-8') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } else {
        echo "<p>" . sprintf(__('Nenhum IP manual detectado. Foram processados %d JSONs nos últimos 30 dias.', 'manualipcheck'), $processed_count) . "</p>";
    }
} else {
    echo "<p>" . sprintf(__('Nenhum IP manual detectado. Foram processados %d JSONs nos últimos 30 dias.', 'manualipcheck'), $processed_count) . "</p>";
}

echo "</div>";

Html::footer();
?>