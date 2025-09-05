<?php

class PluginManualipcheckManualipcheck extends CommonGLPI {
    static $rightname = 'plugin_manualipcheck';

    /**
     * Obter o nome do tipo
     */
    static function getTypeName($nb = 0) {
        return _n('Verificador de IP Manual', 'Verificadores de IP Manual', $nb, 'manualipcheck');
    }

    /**
     * Verificar se a configuração de rede indica um IP manual
     */
    static function isManualIP($network) {
        // Filtros adicionais: ipdhcp, IPv6, virtual
        if (!isset($network['ipaddress']) || empty($network['ipaddress'])) {
            return false;
        }
        if (strpos($network['ipaddress'], ':') !== false) {
            return false; // IPv6
        }
        if (!empty($network['ipdhcp'])) {
            return false; // Tem DHCP
        }
        if ((isset($network['virtualdev']) && $network['virtualdev']) ||
            (isset($network['name']) && stripos($network['name'], 'Virtual Ethernet Adapter') !== false) ||
            (isset($network['description']) && stripos($network['description'], 'Virtual Ethernet Adapter') !== false)) {
            return false; // Adaptador virtual
        }
        // Lógica original para dhcp/dhcpserver
        if (isset($network['dhcp']) && $network['dhcp'] === 'no') {
            return true;
        } elseif (!isset($network['dhcpserver']) || empty($network['dhcpserver'])) {
            return true;
        }
        return false;
    }

    /**
     * Exibir o formulário com o botão de escanear manualmente
     */
    public function showForm($ID, $options = []) {
        if (!Session::haveRight(self::$rightname, READ)) {
            error_log("[" . date('Y-m-d H:i:s') . "] [INFO] Usuário " . ($_SESSION['glpiID'] ?? 'desconhecido') . " (perfil " . ($_SESSION['glpiactiveprofile']['id'] ?? 'desconhecido') . ") não tem permissão READ para exibir formulário.");
            return false;
        }

        echo "<div class='center'>";
        echo "<h2>" . __('Verificador de IP Manual', 'manualipcheck') . "</h2>";
    echo "<form method='post' action='" . $this->getFormURL() . "'>";
    echo "<input type='hidden' name='id' value='$ID'>";
    echo "<input type='submit' name='scan' value='" . __('Escanear Manualmente', 'manualipcheck') . "' class='submit'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo "</form>";

    // Botão de limpar dados
    echo "<form method='post' action='" . $this->getFormURL() . "' style='margin-top:10px;'>";
    echo "<input type='hidden' name='id' value='$ID'>";
    echo "<input type='submit' name='clear_storage' value='" . __('Limpar Dados', 'manualipcheck') . "' class='submit' style='background:#c00;color:#fff;'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo "</form>";

        // Exibir resultados, se existirem
        $manual_ips = $this->getManualIPs();
        if (!empty($manual_ips)) {
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr><th>" . __('Computador', 'manualipcheck') . "</th><th>" . __('IP', 'manualipcheck') . "</th><th>" . __('MAC', 'manualipcheck') . "</th><th>" . __('Grupo de Trabalho', 'manualipcheck') . "</th><th>" . __('Último Usuário', 'manualipcheck') . "</th><th>" . __('Data', 'manualipcheck') . "</th></tr>";
            foreach ($manual_ips as $ip_data) {
                echo "<tr>";
                echo "<td>" . ($ip_data['computer'] ?? 'N/A') . "</td>";
                echo "<td>" . ($ip_data['ip'] ?? 'N/A') . "</td>";
                echo "<td>" . ($ip_data['mac'] ?? 'N/A') . "</td>";
                echo "<td>" . ($ip_data['workgroup'] ?? 'N/A') . "</td>";
                echo "<td>" . ($ip_data['last_user'] ?? 'N/A') . "</td>";
                echo "<td>" . ($ip_data['date'] ?? 'N/A') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>" . __('Nenhum IP manual encontrado.', 'manualipcheck') . "</p>";
        }

        echo "</div>";

        return true;
    }

    /**
     * Obter a lista de IPs manuais do arquivo de armazenamento
     */
    public function getManualIPs() {
        $storage_dir = GLPI_PLUGIN_DOC_DIR . '/manualipcheck/storage/';
        $file_path   = $storage_dir . 'manual_ips.txt';

        if (!file_exists($file_path)) {
            return [];
        }

        $content = file_get_contents($file_path);
        $manual_ips = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[" . date('Y-m-d H:i:s') . "] [ERRO] Falha ao decodificar $file_path: " . json_last_error_msg());
            return [];
        }

        return $manual_ips;
    }

    /**
     * Processar o formulário de escaneamento manual
     */
    public function postForm($post) {
        if (!Session::haveRight(self::$rightname, READ)) {
            error_log("[" . date('Y-m-d H:i:s') . "] [INFO] Usuário " . ($_SESSION['glpiID'] ?? 'desconhecido') . " (perfil " . ($_SESSION['glpiactiveprofile']['id'] ?? 'desconhecido') . ") não tem permissão READ para processar formulário.");
            return false;
        }

        if (isset($post['clear_storage'])) {
            $storage_dir = GLPI_PLUGIN_DOC_DIR . '/manualipcheck/storage/';
            $removed = 0;
            if (is_dir($storage_dir)) {
                foreach (glob($storage_dir . '*') as $file) {
                    if (is_file($file)) {
                        unlink($file);
                        $removed++;
                    }
                }
            }
            Session::addMessageAfterRedirect(
                sprintf(__('Dados da pasta storage removidos: %d arquivos.', 'manualipcheck'), $removed),
                true,
                INFO
            );
            Html::redirect($this->getFormURL());
        }
        if (isset($post['scan'])) {
            $count = plugin_manualipcheck_scan_inventory();
            Session::addMessageAfterRedirect(
                sprintf(__('Varredura manual concluída: %d IPs manuais encontrados.', 'manualipcheck'), $count),
                true,
                INFO
            );
            Html::redirect($this->getFormURL());
        }

        return true;
    }
}
?>