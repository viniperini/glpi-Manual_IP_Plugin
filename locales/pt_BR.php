<?php
$LANG['plugin_manualipcheck'] = [
   // Interface
   'title'               => 'Verificador de IP Manual',
   'menu'                => 'Verificador de IP Manual',
   'scan_button'         => 'Escanear agora',
   'scan_processing'     => 'Escaneando...',
   'scan_done'           => 'Scan concluído. Foram encontrados %d computadores com IP manual.',
   'nomanual'            => 'Nenhum IP manual detectado. Foram processados %d JSONs nos últimos 30 dias.',
   // Erros
   'error_query'         => 'Erro ao executar a consulta no banco de dados.',
   'db_connection_failed' => 'Falha na conexão com o banco de dados durante a instalação ou desinstalação do ManualIPCheck.',
   'invalid_json'        => 'JSON de inventário inválido recebido do agente: %s.',
   'invalid_hardware'    => 'JSON de inventário inválido: campo "hardware" ausente ou inválido.',
   'invalid_networks'    => 'JSON de inventário inválido: campo "networks" ausente ou inválido.',
   'decode_error'        => 'Erro ao decodificar JSON de inventário: %s.',
   'no_json_received'    => 'Nenhum JSON recebido no input do agente para NetworkPort.',
   'storage_not_writable' => 'Diretório de armazenamento %s não é gravável.',
   'json_decode_error'   => 'Erro ao decodificar %s: %s.',
   'inventory_dir_error' => 'Diretório de inventário %s não acessível ou não existe.',
   // Sucesso
   'install_success'     => 'Plugin ManualIPCheck instalado com sucesso.',
   'uninstall_success'   => 'Plugin ManualIPCheck desinstalado com sucesso.',
   'permission_added'    => 'Permissão de leitura para o plugin ManualIPCheck adicionada ao perfil.',
   'json_processed'      => 'JSON de inventário processado com sucesso. IPs manuais detectados e salvos.',
   // Outros
   'version_incompatible' => 'ManualIPCheck requer versão do GLPI entre %s e %s. Versão atual: %s.'
];
?>