#!/usr/bin/env php
<?php
/**
 * cron_scan.php
 * Chamar via cron a cada 12h ou executar manualmente.
 *
 * Coloque esse arquivo em: /var/www/html/glpi/plugins/manualipcheck/cron_scan.php
 */

date_default_timezone_set(@date_default_timezone_get());

/**
 * Função de log robusta: tenta usar Toolbox::logInFile (GLPI) ou fallback para error_log.
 */
function logMessage(string $text) {
    $msg = '[' . date('Y-m-d H:i:s') . '] [manualipcheck] ' . $text;
    if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
        Toolbox::logInFile('manualipcheck', $msg . PHP_EOL);
    } else {
        error_log($msg);
    }
}

/**
 * 1) Tentar bootstrap do GLPI (caminhos comuns)
 */
$glpi_bootstrapped = false;
$bootstrapCandidates = [
    dirname(dirname(__DIR__)) . '/inc/includes.php',
    '/var/www/html/glpi/inc/includes.php',
    '/usr/share/glpi/inc/includes.php',
    '/usr/local/share/glpi/inc/includes.php'
];

foreach ($bootstrapCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        $glpi_bootstrapped = true;
        break;
    }
}

if ($glpi_bootstrapped) {
    logMessage("GLPI bootstrap carregado com sucesso.");
} else {
    logMessage("Erro: GLPI bootstrap não encontrado. Usando fallback de diretórios.");
    if (!defined('GLPI_PLUGIN_DOC_DIR')) {
        define('GLPI_PLUGIN_DOC_DIR', dirname(dirname(__DIR__)) . '/files/_plugins');
    }
}

/**
 * 2) Garantir existência do setup.php no plugin
 */
$setupPath = __DIR__ . '/setup.php';
if (!file_exists($setupPath)) {
    logMessage("Erro: setup.php não encontrado em " . __DIR__);
    exit(1);
}

/**
 * 3) Preparar diretório de storage e arquivo de lock
 */
$storage_dir = (defined('GLPI_PLUGIN_DOC_DIR') ? rtrim(GLPI_PLUGIN_DOC_DIR, '/\\') : dirname(dirname(__DIR__)) . '/files/_plugins') . '/manualipcheck/storage';
if (!is_dir($storage_dir)) {
    if (!@mkdir($storage_dir, 0755, true) || !is_dir($storage_dir)) {
        logMessage("Erro: Não foi possível criar o diretório de armazenamento: $storage_dir");
        exit(2);
    }
}
if (!is_writable($storage_dir)) {
    logMessage("Erro: Diretório de armazenamento $storage_dir não é gravável.");
    exit(2);
}

// Lock file com flock para evitar concorrência
$lockFile = $storage_dir . '/cron_scan.lock';
$fp = @fopen($lockFile, 'c');
if (!$fp) {
    logMessage("Erro: Não foi possível criar/abrir lockfile: $lockFile");
    exit(3);
}

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    logMessage("Abortando: Já existe uma execução em andamento (lock ativo em $lockFile).");
    fclose($fp);
    exit(0);
}

// Grava PID/timestamp no lockfile
ftruncate($fp, 0);
fwrite($fp, getmypid() . ' ' . date('c') . PHP_EOL);
fflush($fp);

/**
 * 4) Incluir o setup do plugin
 */
require_once $setupPath;

/**
 * 5) Verificar se a função existe e executar
 */
if (!function_exists('plugin_manualipcheck_scan_inventory')) {
    logMessage("Erro: Função plugin_manualipcheck_scan_inventory não definida em setup.php.");
    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink($lockFile);
    exit(4);
}

logMessage("Iniciando plugin_manualipcheck_scan_inventory() via cron.");
$found = 0;
try {
    $ret = plugin_manualipcheck_scan_inventory();

    if (is_int($ret)) {
        $found = $ret;
        logMessage("Scan de inventário concluído. Encontrados $found IPs manuais.");
    } else {
        logMessage("Aviso: plugin_manualipcheck_scan_inventory retornou valor inesperado: " . print_r($ret, true));
        $storage_file = $storage_dir . '/manual_ips.txt';
        if (file_exists($storage_file)) {
            $content = @file_get_contents($storage_file);
            $arr = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($arr)) {
                $found = count($arr);
                logMessage("Contagem de IPs manuais obtida de manual_ips.txt: $found IPs.");
            } else {
                logMessage("Aviso: manual_ips.txt existe mas não pôde ser decodificado. Erro: " . json_last_error_msg());
                $found = 0;
            }
        } else {
            logMessage("Aviso: manual_ips.txt não encontrado em $storage_file.");
            $found = 0;
        }
    }

    $msg = "Scan de inventário concluído. Encontrados $found IPs manuais.";
    logMessage($msg);
    echo $msg . PHP_EOL;
    $exitCode = 0;
} catch (Throwable $e) {
    $msg = "Exceção durante o scan: " . $e->getMessage() . " (Arquivo: " . $e->getFile() . ", Linha: " . $e->getLine() . ")";
    logMessage($msg);
    echo $msg . PHP_EOL;
    $exitCode = 5;
} finally {
    // Garantir que o lock seja liberado e o arquivo removido
    flock($fp, LOCK_UN);
    fclose($fp);
    if (file_exists($lockFile)) {
        @unlink($lockFile);
        logMessage("Lock file $lockFile removido com sucesso.");
    } else {
        logMessage("Aviso: Lock file $lockFile não existia ao tentar remover.");
    }
}

exit($exitCode);
?>