<?php
// Configurações do banco de dados Firebird
$db_config = [
    'path' => 'D:\\sistemas\\fcerta\\DB\\ALTERDB.ib',
    'host' => '25.90.252.41',
    'username' => 'SYSDBA',
    'password' => 'masterkey',
    'port' => 3050,
    'charset' => 'WIN1252'
];

// Função para conectar ao banco de dados Firebird
function conectarFirebird() {
    global $db_config;
    
    $dsn = "firebird:dbname={$db_config['host']}/{$db_config['port']}:{$db_config['path']};charset={$db_config['charset']}";
    
    try {
        $conn = new PDO($dsn, $db_config['username'], $db_config['password']);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch (PDOException $e) {
        throw new PDOException("Erro na conexão com o banco de dados: " . $e->getMessage(), (int)$e->getCode());
    }
}

// Configurações de template para cópia de orçamentos
$copy_template_config = [
    'header' => '*Farmácia Teste - Orçamentos*',
    'item_templates' => [
        'header' => '🧾 *Orçamento nº* {{NRORC}}-{{SERIEO}}',
        'date_filial' => '📅 *Data* {{DTENTR}} • 🏢 Filial {{CDFIL}}',
        'itens' => '🧪 *Itens:* {{DESCRICOES}}',
        'volume' => '📦 *Volume:* {{VOLUME}} {{UNIVOL}}',
        'preco' => '💰 *Preço:* R$ {{PR_COB}}'
    ],
    'total_line' => '🧮 *Total:* R$ {{TOTAL}}'
];
?>