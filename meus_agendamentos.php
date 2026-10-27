
<?php
// ============================================================
// INICIAR SESSÃO, CONEXÃO E VALIDAR O ACESSO
// ============================================================

// Inicia a sessão para identificar a cliente autenticada.
session_start();

// Carrega a conexão PDO compartilhada pelo projeto.
require_once("conexao.php");

// Define o fuso horário utilizado pelo sistema.
date_default_timezone_set("America/Sao_Paulo");

// Permite acesso somente para clientes autenticadas.
if (
    !isset($_SESSION["usuario_id"]) ||
    ($_SESSION["usuario_tipo"] ?? "") !== "cliente"
) {
    header("Location: login.php");
    exit();
}

// Recupera os dados da cliente autenticada.
$cliente_id = (int) $_SESSION["usuario_id"];
$nome_cliente = $_SESSION["usuario_nome"] ?? "Cliente";

// Inicializa as listas de atendimentos.
$proximos_agendamentos = [];
$historico_agendamentos = [];
$erro_agendamentos = false;

// Guarda a data e o horário atuais no fuso de São Paulo.
$data_hora_atual = new DateTime(
    "now",
    new DateTimeZone("America/Sao_Paulo")
);

// ============================================================
// BUSCAR OS AGENDAMENTOS DA CLIENTE
// ============================================================

try {
    // Busca os atendimentos e seus dados relacionados.
    $sql_agendamentos = "
        SELECT
            a.id,
            a.data_agendamento AS data,
            a.horario,
            a.status,
            COALESCE(a.preco_final, s.preco) AS preco,
            s.nome_servico AS servico,
            u.nome AS profissional
        FROM agendamentos AS a
        LEFT JOIN servicos AS s
            ON s.id = a.servico_id
        LEFT JOIN usuarios AS u
            ON u.id = a.profissional_id
        WHERE a.cliente_id = :cliente_id
        ORDER BY a.data_agendamento ASC, a.horario ASC
    ";

    // Utiliza a conexão existente no arquivo conexao.php.
    $consulta_agendamentos = $conexao->prepare($sql_agendamentos);

    // Busca somente os registros da cliente autenticada.
    $consulta_agendamentos->execute([
        ":cliente_id" => $cliente_id
    ]);

    // Recupera os registros encontrados.
    $agendamentos = $consulta_agendamentos->fetchAll(PDO::FETCH_ASSOC);

    // Separa os atendimentos futuros do histórico.
    foreach ($agendamentos as $agendamento) {
        $status_atual = strtolower(
            trim((string) ($agendamento["status"] ?? ""))
        );

        // Normaliza a data e o horário para fazer a comparação.
        $data = trim((string) ($agendamento["data"] ?? ""));
        $horario = substr(
            trim((string) ($agendamento["horario"] ?? "")),
            0,
            5
        );

        // Cria a data completa do atendimento.
        $data_hora_agendamento = DateTime::createFromFormat(
            "!Y-m-d H:i",
            $data . " " . $horario,
            new DateTimeZone("America/Sao_Paulo")
        );

        // Verifica se a data e o horário foram interpretados corretamente.
        $data_valida = (
            $data_hora_agendamento !== false &&
            $data_hora_agendamento->format("Y-m-d H:i") ===
                $data . " " . $horario
        );

        // Cancelados e concluídos ficam no histórico.
        if (in_array(
            $status_atual,
            ["cancelado", "concluido"],
            true
        )) {
            $historico_agendamentos[] = $agendamento;
            continue;
        }

        // Agendamentos futuros ou no horário atual aparecem como próximos.
        if (
            $data_valida &&
            $data_hora_agendamento >= $data_hora_atual
        ) {
            $proximos_agendamentos[] = $agendamento;
        } else {
            // Atendimentos anteriores ou com data inválida ficam no histórico.
            $historico_agendamentos[] = $agendamento;
        }
    }

    // Ordena os próximos atendimentos do mais próximo ao mais distante.
    usort($proximos_agendamentos, function ($a, $b) {
        $data_a = ($a["data"] ?? "") . " " . ($a["horario"] ?? "");
        $data_b = ($b["data"] ?? "") . " " . ($b["horario"] ?? "");

        return strcmp($data_a, $data_b);
    });

    // Ordena o histórico do atendimento mais recente ao mais antigo.
    usort($historico_agendamentos, function ($a, $b) {
        $data_a = ($a["data"] ?? "") . " " . ($a["horario"] ?? "");
        $data_b = ($b["data"] ?? "") . " " . ($b["horario"] ?? "");

        return strcmp($data_b, $data_a);
    });

} catch (PDOException $erro) {
    // Registra o erro técnico no log para facilitar a correção.
    error_log(
        "Erro ao carregar agendamentos da cliente: " .
        $erro->getMessage()
    );

    // Indica que a consulta falhou, em vez de afirmar que não existem registros.
    $erro_agendamentos = true;
}

// ============================================================
// FUNÇÕES DE FORMATAÇÃO E SEGURANÇA
// ============================================================

// Protege os textos exibidos na página contra interpretação como HTML.
function escaparTexto($texto)
{
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}

// Formata a data no padrão brasileiro.
function formatarDataAgendamento($data)
{
    $data = (string) $data;

    // Aceita apenas uma data válida no formato esperado pelo banco.
    $data_objeto = DateTime::createFromFormat("!Y-m-d", $data);

    if (
        !$data_objeto ||
        $data_objeto->format("Y-m-d") !== $data
    ) {
        return "Data não informada";
    }

    return $data_objeto->format("d/m/Y");
}

// Converte o status do banco em texto e classe visual.
function apresentarStatus($status)
{
    $status = strtolower(trim((string) $status));

    // Define os nomes que podem aparecer para a cliente.
    $status_conhecidos = [
        "pendente" => "Pendente",
        "confirmado" => "Confirmado",
        "concluido" => "Concluído",
        "cancelado" => "Cancelado",
        "alterado" => "Alterado"
    ];

    // Define a classe CSS correspondente a cada status.
    $classes_status = [
        "pendente" => "status-pendente",
        "confirmado" => "status-confirmado",
        "concluido" => "status-concluido",
        "cancelado" => "status-cancelado",
        "alterado" => "status-alterado"
    ];

    return [
        "texto" => $status_conhecidos[$status] ?? "Em análise",
        "classe" => $classes_status[$status] ?? "status-indefinido"
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <!-- Configura os caracteres e a adaptação para celulares. -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Define o título da aba do navegador. -->
    <title>Meus Agendamentos | TCC Estética</title>

    <!-- Carrega o CSS compartilhado pelo projeto. -->
    <link rel="stylesheet" href="style.css">
</head>

<body class="pagina-cliente pagina-meus-agendamentos">

    <!-- ====================================================== -->
    <!-- CABEÇALHO E NAVEGAÇÃO DA CLIENTE -->
    <!-- ====================================================== -->

    <header class="cabecalho-cliente">
        <div class="logo-cliente">
            <h1>Estética</h1>
        </div>

        <!-- Mantém o menu padronizado com a área da cliente. -->
        <nav class="menu-cliente" aria-label="Navegação principal">
            <a href="painel_cliente.php">Início</a>
            <a href="servicos.php">Serviços</a>
            <a
                href="meus_agendamentos.php"
                class="ativo"
                aria-current="page"
            >Meus Agendamentos</a>
            <a href="perfil_cliente.php">Meu Perfil</a>
            <a href="logout.php" class="menu-sair">Sair</a>
        </nav>
    </header>

    <!-- ====================================================== -->
    <!-- CONTEÚDO PRINCIPAL -->
    <!-- ====================================================== -->

    <main class="meus-agendamentos-container">

        <!-- Apresentação da página. -->
        <section class="agendamentos-introducao">
            <span class="agendamentos-etiqueta">ÁREA DA CLIENTE</span>

            <h2>Meus Agendamentos</h2>

            <p>
                Olá, <?= escaparTexto($nome_cliente) ?>!
                Consulte seus próximos atendimentos e confira
                o histórico dos serviços realizados.
            </p>
        </section>

        <?php if ($erro_agendamentos): ?>

            <!-- Informa que ocorreu um problema ao consultar o banco. -->
            <div class="agendamentos-vazio" role="alert">
                <h4>Não foi possível carregar seus agendamentos</h4>
                <p>
                    Ocorreu um problema ao consultar os registros.
                    Tente atualizar a página. Se o erro continuar,
                    entre em contato com o suporte.
                </p>
            </div>

        <?php else: ?>

            <!-- ============================================== -->
            <!-- PRÓXIMOS AGENDAMENTOS -->
            <!-- ============================================== -->

            <section
                class="secao-agendamentos"
                id="proximos-agendamentos"
            >
                <div class="agendamentos-titulo-secao">
                    <div>
                        <h3>Próximos agendamentos</h3>
                        <p>
                            Confira os detalhes dos seus próximos atendimentos.
                        </p>
                    </div>

                    <!-- Mostra a quantidade de próximos atendimentos. -->
                    <span class="agendamentos-contador">
                        <?= count($proximos_agendamentos) ?>
                    </span>
                </div>

                <?php if (!empty($proximos_agendamentos)): ?>

                    <div class="lista-agendamentos">

                        <?php foreach ($proximos_agendamentos as $agendamento): ?>

                            <!-- Cartão de um próximo atendimento. -->
                            <article class="cartao-agendamento">

                                <div class="agendamento-data">
                                    <span>Data do atendimento</span>

                                    <strong>
                                        <?= escaparTexto(
                                            formatarDataAgendamento(
                                                $agendamento["data"]
                                            )
                                        ) ?>
                                    </strong>

                                    <span class="agendamento-horario">
                                        <?= escaparTexto(
                                            $agendamento["horario"] ?? "--:--"
                                        ) ?>
                                    </span>
                                </div>

                                <div class="agendamento-detalhes">
                                    <h4>
                                        <?= escaparTexto(
                                            $agendamento["servico"] ?? "Serviço"
                                        ) ?>
                                    </h4>

                                    <p>
                                        <strong>Profissional:</strong>
                                        <?= escaparTexto(
                                            $agendamento["profissional"] ??
                                            "Não informada"
                                        ) ?>
                                    </p>

                                    <p>
                                        <strong>Valor:</strong>

                                        <?php if ($agendamento["preco"] !== null): ?>
                                            R$
                                            <?= number_format(
                                                (float) $agendamento["preco"],
                                                2,
                                                ",",
                                                "."
                                            ) ?>
                                        <?php else: ?>
                                            A confirmar
                                        <?php endif; ?>
                                    </p>

                                    <!-- Exibe o status atual do atendimento. -->
                                    <?php
                                    $status = apresentarStatus(
                                        $agendamento["status"] ?? ""
                                    );
                                    ?>

                                    <span class="agendamento-status <?= escaparTexto($status["classe"]) ?>">
                                        <?= escaparTexto($status["texto"]) ?>
                                    </span>
                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <!-- Mensagem para quando não existem próximos atendimentos. -->
                    <div class="agendamentos-vazio">
                        <h4>Nenhum próximo agendamento</h4>

                        <p>
                            Quando você realizar um agendamento,
                            os detalhes aparecerão nesta seção.
                        </p>

                        <a href="servicos.php" class="agendamentos-botao">
                            Conhecer serviços
                        </a>
                    </div>

                <?php endif; ?>
            </section>

            <!-- ============================================== -->
            <!-- HISTÓRICO DE ATENDIMENTOS -->
            <!-- ============================================== -->

            <section
                class="secao-agendamentos"
                id="historico-agendamentos"
            >
                <div class="agendamentos-titulo-secao">
                    <div>
                        <h3>Histórico de atendimentos</h3>
                        <p>
                            Consulte os atendimentos anteriores e seus status.
                        </p>
                    </div>

                    <!-- Mostra a quantidade de registros no histórico. -->
                    <span class="agendamentos-contador">
                        <?= count($historico_agendamentos) ?>
                    </span>
                </div>

                <?php if (!empty($historico_agendamentos)): ?>

                    <div class="lista-agendamentos">

                        <?php foreach ($historico_agendamentos as $agendamento): ?>

                            <!-- Cartão de um registro do histórico. -->
                            <article class="cartao-agendamento cartao-historico">

                                <div class="agendamento-data">
                                    <span>Data do atendimento</span>

                                    <strong>
                                        <?= escaparTexto(
                                            formatarDataAgendamento(
                                                $agendamento["data"]
                                            )
                                        ) ?>
                                    </strong>

                                    <span class="agendamento-horario">
                                        <?= escaparTexto(
                                            $agendamento["horario"] ?? "--:--"
                                        ) ?>
                                    </span>
                                </div>

                                <div class="agendamento-detalhes">
                                    <h4>
                                        <?= escaparTexto(
                                            $agendamento["servico"] ?? "Serviço"
                                        ) ?>
                                    </h4>

                                    <p>
                                        <strong>Profissional:</strong>
                                        <?= escaparTexto(
                                            $agendamento["profissional"] ??
                                            "Não informada"
                                        ) ?>
                                    </p>

                                    <p>
                                        <strong>Valor:</strong>

                                        <?php if ($agendamento["preco"] !== null): ?>
                                            R$
                                            <?= number_format(
                                                (float) $agendamento["preco"],
                                                2,
                                                ",",
                                                "."
                                            ) ?>
                                        <?php else: ?>
                                            A confirmar
                                        <?php endif; ?>
                                    </p>

                                    <!-- Exibe o status do registro histórico. -->
                                    <?php
                                    $status = apresentarStatus(
                                        $agendamento["status"] ?? ""
                                    );
                                    ?>

                                    <span class="agendamento-status <?= escaparTexto($status["classe"]) ?>">
                                        <?= escaparTexto($status["texto"]) ?>
                                    </span>
                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <!-- Mensagem para quando não existem registros históricos. -->
                    <div class="agendamentos-vazio">
                        <h4>Nenhum registro no histórico</h4>

                        <p>
                            Seus atendimentos anteriores aparecerão aqui
                            quando houver registros correspondentes.
                        </p>
                    </div>

                <?php endif; ?>
            </section>

        <?php endif; ?>

    </main>

    <!-- Rodapé discreto da área da cliente. -->
    <footer class="rodape-agendamentos">
        <p>TCC Estética — Área da Cliente</p>
    </footer>

</body>
</html>
