<?php
require_once 'config.php';

// Inicializa variáveis
$resultados = [];
$mensagem = '';
$filialOrc = isset($_POST['filialOrc']) ? $_POST['filialOrc'] : '';
$nroOrc = isset($_POST['nroOrc']) ? $_POST['nroOrc'] : '';
$dataInicial = isset($_POST['dataInicial']) ? $_POST['dataInicial'] : date('d/m/Y');
$dataFinal = isset($_POST['dataFinal']) ? $_POST['dataFinal'] : date('d/m/Y');
$tipoConsulta = isset($_POST['tipoConsulta']) ? $_POST['tipoConsulta'] : 'data';
$cdfun = isset($_POST['cdfun']) ? $_POST['cdfun'] : '';

// Processa o formulário quando enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = conectarFirebird();
        
        // Query base
        $sql = "SELECT FC15110.CDFIL, FC15100.NRORC, FC15100.SERIEO, FC15110.ITEMID, 
                FC15110.TPCMP, Cast(FC15100.VOLUME As Numeric(8,0)) As VOLUME, 
                FC15100.UNIVOL, FC15110.DESCR, FC15110.QUANT, FC15110.UNIDA, 
                Cast(FC15000.VRRQU As Numeric(8,2)) As vr_rq, 
                Cast(FC15100.PRCOBR As Numeric(8,2)) As PR_COB, 
                FC15110.UNIHP, FC15110.QUANTHP, FC15110.TPFORMAFARMA,
                FC15000.DTENTR, FC15000.CDFUN,
                FC08000.USERID, FC08000.CDFUN AS CDFUN_080
                FROM FC15100 
                Inner Join FC15110 On FC15110.CDFIL = FC15100.CDFIL 
                    And FC15110.NRORC = FC15100.NRORC 
                    And FC15110.SERIEO = FC15100.SERIEO 
                Inner Join FC15000 On FC15000.CDFIL = FC15110.CDFIL 
                    And FC15000.NRORC = FC15110.NRORC 
                Left Join FC08000 On FC08000.CDFUN = FC15000.CDFUN
                Where FC15110.TPCMP Not In ('E', 'X', 'P')";
        
        $params = [];
        
        // Adiciona condições conforme o tipo de consulta
        if ($tipoConsulta === 'orcamento' && !empty($nroOrc) && !empty($filialOrc)) {
            $sql .= " And FC15100.NRORC = :nroOrc And FC15110.CDFIL = :filialOrc";
            $params[':nroOrc'] = $nroOrc;
            $params[':filialOrc'] = $filialOrc;
            if (!empty($cdfun)) {
                $sql .= " And FC15000.CDFUN = :cdfun";
                $params[':cdfun'] = $cdfun;
            }
        } else {
            // Consulta por intervalo de data usando DTENTR
            $sql .= " And FC15000.DTENTR BETWEEN :dataInicial AND :dataFinal";
            
            // Converte as datas (dd/mm/aaaa) para o formato do Firebird (MM/DD/YYYY)
            $dtIniObj = DateTime::createFromFormat('d/m/Y', $dataInicial);
            $dtFimObj = DateTime::createFromFormat('d/m/Y', $dataFinal);

            // Fallback caso o parsing falhe
            if (!$dtIniObj) { $dtIniObj = new DateTime($dataInicial); }
            if (!$dtFimObj) { $dtFimObj = new DateTime($dataFinal); }

            $dataInicialFormatada = $dtIniObj->format('m/d/Y');
            $dataFinalFormatada = $dtFimObj->format('m/d/Y');
            
            $params[':dataInicial'] = $dataInicialFormatada;
            $params[':dataFinal'] = $dataFinalFormatada;
            
            // Adiciona filtro de filial se fornecido
            if (!empty($filialOrc)) {
                $sql .= " And FC15110.CDFIL = :filialOrc";
                $params[':filialOrc'] = $filialOrc;
            }
            // Adiciona filtro de funcionário se fornecido
            if (!empty($cdfun)) {
                $sql .= " And FC15000.CDFUN = :cdfun";
                $params[':cdfun'] = $cdfun;
            }
        }
        
        // Ordena por data e número do orçamento
         $sql .= " ORDER BY FC15000.DTENTR DESC, FC15100.NRORC DESC";
        
        $stmt = $conn->prepare($sql);
        
        // Bind de todos os parâmetros
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        
        $stmt->execute();
        $resultadosOriginais = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Agrupar resultados por número de orçamento
        $resultados = [];
        $orcamentosAgrupados = [];
        
        foreach ($resultadosOriginais as $row) {
            $chave = $row['CDFIL'] . '-' . $row['NRORC'] . '-' . $row['SERIEO'];
            
            if (!isset($orcamentosAgrupados[$chave])) {
                $orcamentosAgrupados[$chave] = [
                    'CDFIL' => $row['CDFIL'],
                    'NRORC' => $row['NRORC'],
                    'SERIEO' => $row['SERIEO'],
                    'DTENTR' => $row['DTENTR'],
                    'VOLUME' => $row['VOLUME'],
                    'UNIVOL' => $row['UNIVOL'],
                    'DESCRICOES' => [],
                    'PR_COB' => $row['PR_COB'],
                    'VR_RQ' => $row['VR_RQ'],
                    'CDFUN' => $row['CDFUN'],
                    'USERID' => $row['USERID'],
                    'CDFUN_080' => $row['CDFUN_080']
                ];
            }
            
            // Adiciona a descrição com dosagem e unidade ao array de descrições
            $descricao = $row['DESCR'];
            $dosagem = '';
            if (!empty($row['QUANTHP']) && !empty($row['UNIHP'])) {
                $valor = $row['QUANTHP'];
                $valorFormatado = (intval($valor) == $valor) ? intval($valor) : number_format($valor, 2, ',', '.');
                $dosagem = ' ' . $valorFormatado . $row['UNIHP'];
            } elseif (!empty($row['QUANT']) && !empty($row['UNIDA'])) {
                $valor = $row['QUANT'];
                $valorFormatado = (intval($valor) == $valor) ? intval($valor) : number_format($valor, 2, ',', '.');
                $dosagem = ' ' . $valorFormatado . ' ' . $row['UNIDA'];
            }
            $orcamentosAgrupados[$chave]['DESCRICOES'][] = $descricao . $dosagem;
        }
        
        // Converte o array associativo para array indexado
        foreach ($orcamentosAgrupados as $orcamento) {
            $resultados[] = $orcamento;
        }
        
        if (empty($resultados)) {
            $mensagem = "Nenhum resultado encontrado para os parâmetros informados.";
        }
    } catch (PDOException $e) {
        $mensagem = "Erro ao executar a consulta: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Orçamentos</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #34495e;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-bottom: 80px;
        }
        
        .navbar {
            background-color: var(--secondary-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            border: none;
        }
        
        .card-header {
            background-color: var(--primary-color);
            color: white;
            border-radius: 10px 10px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
            border-color: #2980b9;
        }
        
        .table {
            border-radius: 5px;
            overflow: hidden;
        }
        
        .table thead th {
            background-color: var(--secondary-color);
            color: white;
            border: none;
        }
        
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(236, 240, 241, 0.5);
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(52, 152, 219, 0.1);
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
        }
        
        .alert {
            border-radius: 10px;
        }
        
        .footer {
            background-color: var(--secondary-color);
            color: white;
            padding: 14px 0;
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            z-index: 1030;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .footer p {
            margin: 0;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-file-invoice-dollar me-2"></i>
                Sistema de Orçamentos
            </a>
        </div>
    </nav>

    <div class="container">
        <!-- Formulário de Consulta -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-search me-2"></i>Consulta de Orçamentos
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="row g-3">
                        <div class="col-12 mb-3">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="tipoConsulta" id="tipoData" value="data" <?php echo $tipoConsulta === 'data' ? 'checked' : ''; ?> onchange="toggleFormFields()">
                                <label class="form-check-label" for="tipoData">Consulta por Data</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="tipoConsulta" id="tipoOrcamento" value="orcamento" <?php echo $tipoConsulta === 'orcamento' ? 'checked' : ''; ?> onchange="toggleFormFields()">
                                <label class="form-check-label" for="tipoOrcamento">Consulta por Orçamento</label>
                            </div>
                        </div>
                        
                        <div class="col-6 col-md-3" id="filialField">
                            <label for="filialOrc" class="form-label">Filial</label>
                            <input type="number" class="form-control" id="filialOrc" name="filialOrc" value="<?php echo htmlspecialchars($filialOrc); ?>">
                        </div>

                        <div class="col-6 col-md-3" id="cdfunField">
                            <label for="cdfun" class="form-label">Funcionário</label>
                            <input type="number" inputmode="numeric" class="form-control" id="cdfun" name="cdfun" value="<?php echo htmlspecialchars($cdfun); ?>" placeholder="Ex: 123">
                        </div>
                        
                        <div class="col-md-6" id="orcamentoField">
                            <label for="nroOrc" class="form-label">Número do Orçamento</label>
                            <input type="number" class="form-control" id="nroOrc" name="nroOrc" value="<?php echo htmlspecialchars($nroOrc); ?>">
                        </div>
                        
                        <div class="col-12" id="dateFieldsRow">
                            <div class="row g-2">
                                <div class="col-6" id="dataInicialField">
                                    <label for="dataInicialPicker" class="form-label">Data Inicial</label>
                                    <input type="date" class="form-control" id="dataInicialPicker" lang="pt-BR">
                                    <input type="hidden" id="dataInicial" name="dataInicial" value="<?php echo htmlspecialchars($dataInicial); ?>">
                                </div>
                                <div class="col-6" id="dataFinalField">
                                    <label for="dataFinalPicker" class="form-label">Data Final</label>
                                    <input type="date" class="form-control" id="dataFinalPicker" lang="pt-BR">
                                    <input type="hidden" id="dataFinal" name="dataFinal" value="<?php echo htmlspecialchars($dataFinal); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i>Consultar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <script>
                const COPY_TEMPLATE = <?php echo json_encode($copy_template_config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                function toggleFormFields() {
                    const tipoData = document.getElementById('tipoData').checked;
                    const dataInicialField = document.getElementById('dataInicialField');
                    const dataFinalField = document.getElementById('dataFinalField');
                    const orcamentoField = document.getElementById('orcamentoField');
                    const filialField = document.getElementById('filialField');
                    
                    if (tipoData) {
                        dataInicialField.style.display = 'block';
                        dataFinalField.style.display = 'block';
                        orcamentoField.style.display = 'none';
                        document.getElementById('nroOrc').required = false;
                        document.getElementById('filialOrc').required = false;
                    } else {
                        dataInicialField.style.display = 'none';
                        dataFinalField.style.display = 'none';
                        orcamentoField.style.display = 'block';
                        document.getElementById('nroOrc').required = true;
                        document.getElementById('filialOrc').required = true;
                    }
                }

                function toISO(br) {
                    if (!br) return '';
                    const parts = br.split('/');
                    if (parts.length !== 3) return '';
                    const [d, m, y] = parts;
                    return `${y}-${m.padStart(2,'0')}-${d.padStart(2,'0')}`;
                }
                function toBR(iso) {
                    if (!iso) return '';
                    const parts = iso.split('-');
                    if (parts.length !== 3) return '';
                    const [y, m, d] = parts;
                    return `${d.padStart(2,'0')}/${m.padStart(2,'0')}/${y}`;
                }

                function formatPrice(value) {
                    // value pode já estar formatado "89,90"; se número, formata
                    if (typeof value === 'string') return value;
                    return (Number(value) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                
                function buildBudgetMap() {
                    const rows = document.querySelectorAll('table.table-striped.table-hover tbody tr');
                    const map = {};
                    rows.forEach(r => {
                        const nr = r.dataset.nrorc;
                        const entry = {
                            NRORC: r.dataset.nrorc,
                            SERIEO: r.dataset.serieo,
                            DTENTR: r.dataset.dtentr,
                            CDFIL: r.dataset.cdfil,
                            VOLUME: r.dataset.volume,
                            UNIVOL: r.dataset.univol,
                            PR_COB: r.dataset.pr_cob,
                            DESCRICOES: (r.querySelector('.descricoes')?.innerText || '').trim()
                        };
                        if (!map[nr]) map[nr] = [];
                        map[nr].push(entry);
                    });
                    return map;
                }
                
                function parsePriceBR(value) {
                     if (typeof value === 'number') return value;
                     if (!value) return 0;
                     const normalized = String(value).replace(/\./g, '').replace(',', '.');
                     const num = parseFloat(normalized);
                     return isNaN(num) ? 0 : num;
                 }
                 function formatNumberBR(num) {
                     return (Number(num) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                 }
                 function tpl(str, vars) {
                     return String(str || '').replace(/\{\{(\w+)\}\}/g, (_, k) => (vars[k] ?? ''));
                 }
                 function copyBudget(nr) {
                     const map = buildBudgetMap();
                     const arr = [...(map[nr] || [])];
                     if (!arr.length) { alert('Nenhum orçamento encontrado para copiar.'); return; }
                     // Ordena por série em ordem crescente
                     arr.sort((a, b) => (parseInt(a.SERIEO, 10) || 0) - (parseInt(b.SERIEO, 10) || 0));
                     const parts = arr.map(item => {
                         const vars = {
                             NRORC: item.NRORC,
                             SERIEO: item.SERIEO,
                             DTENTR: item.DTENTR,
                             CDFIL: item.CDFIL,
                             DESCRICOES: item.DESCRICOES,
                             VOLUME: item.VOLUME,
                             UNIVOL: item.UNIVOL,
                             PR_COB: formatNumberBR(parsePriceBR(item.PR_COB))
                         };
                         return [
                             tpl(COPY_TEMPLATE.item_templates.header, vars),
                             tpl(COPY_TEMPLATE.item_templates.date_filial, vars),
                             tpl(COPY_TEMPLATE.item_templates.itens, vars),
                             tpl(COPY_TEMPLATE.item_templates.volume, vars),
                             tpl(COPY_TEMPLATE.item_templates.preco, vars)
                         ].join('\n');
                     });
                     const total = arr.reduce((sum, item) => sum + parsePriceBR(item.PR_COB), 0);
                     const headerText = (COPY_TEMPLATE.header || '').trim();
                     const totalText = tpl(COPY_TEMPLATE.total_line, { TOTAL: formatNumberBR(total) });
                     const text = (headerText ? headerText + '\n\n' : '') + parts.join('\n\n') + '\n\n' + totalText;
                     navigator.clipboard.writeText(text).then(() => {
                         alert('Orçamento copiado para a área de transferência!');
                     }).catch(() => alert('Falha ao copiar o Orçamento.'));
                 }
                
                // Inicializa os campos ao carregar a página
                document.addEventListener('DOMContentLoaded', () => {
                    toggleFormFields();
                    const iniPicker = document.getElementById('dataInicialPicker');
                    const fimPicker = document.getElementById('dataFinalPicker');
                    const iniHidden = document.getElementById('dataInicial');
                    const fimHidden = document.getElementById('dataFinal');

                    // Seta o valor dos pickers a partir dos campos ocultos (dd/mm/aaaa)
                    if (iniHidden && iniHidden.value) iniPicker.value = toISO(iniHidden.value);
                    if (fimHidden && fimHidden.value) fimPicker.value = toISO(fimHidden.value);

                    // Ao alterar no calendário, atualizar campos ocultos em dd/mm/aaaa
                    iniPicker.addEventListener('change', () => {
                        if (iniPicker.value) iniHidden.value = toBR(iniPicker.value);
                    });
                    fimPicker.addEventListener('change', () => {
                        if (fimPicker.value) fimHidden.value = toBR(fimPicker.value);
                    });
                });
            </script>
        </div>

        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i><?php echo $mensagem; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($resultados)): ?>
            <!-- Resultados da Consulta -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-table me-2"></i>Resultados da Consulta
                    <span class="ms-2">(Total: <?php echo count($resultados); ?>)</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" style="font-size: 0.8rem;">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Filial</th>
                                    <th>Funcionário</th>
                                    <th>Usuário</th>                                 
                                    <th>Orçamento</th>
                                    <th>Série</th>
                                    <th>Descrição</th>
                                    <th>Volume</th>
                                    <th>Preço</th>
                                    <th>Copiar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultados as $row): ?>
                                    <tr 
                                        data-nrorc="<?php echo htmlspecialchars($row['NRORC']); ?>"
                                        data-serieo="<?php echo htmlspecialchars($row['SERIEO']); ?>"
                                        data-cdfil="<?php echo htmlspecialchars($row['CDFIL']); ?>"
                                         data-cdfun="<?php echo htmlspecialchars($row['CDFUN']); ?>"
                                         data-userid="<?php echo htmlspecialchars($row['USERID']); ?>"
                                        data-dtentr="<?php echo !empty($row['DTENTR']) ? date('d/m/Y', strtotime($row['DTENTR'])) : ''; ?>"
                                        data-volume="<?php echo htmlspecialchars($row['VOLUME']); ?>"
                                        data-univol="<?php echo htmlspecialchars($row['UNIVOL']); ?>"
                                        data-pr_cob="<?php echo number_format($row['PR_COB'], 2, ',', '.'); ?>"
                                    >
                                        <td><?php echo !empty($row['DTENTR']) ? date('d/m/Y', strtotime($row['DTENTR'])) : ''; ?></td>
                                        <td><?php echo htmlspecialchars($row['CDFIL']); ?></td>
                                        <td><?php echo htmlspecialchars($row['CDFUN']); ?></td>
                                        <td><?php echo htmlspecialchars($row['USERID']); ?></td>
                                        <td><?php echo htmlspecialchars($row['NRORC']); ?></td>
                                        <td><?php echo htmlspecialchars($row['SERIEO']); ?></td>
                                        <td class="descricoes">
                                            <?php 
                                            if (!empty($row['DESCRICOES'])) {
                                                $descricoes = array_map('htmlspecialchars', $row['DESCRICOES']);
                                                echo implode('; ', $descricoes);
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['VOLUME']) . ' ' . htmlspecialchars($row['UNIVOL']); ?></td>
                                        <td>R$ <?php echo number_format($row['PR_COB'], 2, ',', '.'); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyBudget('<?php echo htmlspecialchars($row['NRORC']); ?>')">📋 Copiar</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="footer mt-5">
        <div class="container text-center">
            <p>&copy; <?php echo date('Y'); ?> Sistema de Orçamentos</p>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>