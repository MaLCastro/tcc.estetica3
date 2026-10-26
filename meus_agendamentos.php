
<?php
// ============================================================
// INICIAR SESSÃO E VALIDAR O ACESSO
// ============================================================

// Inicia a sessão para identificar a cliente autenticada.
session_start();

// Permite acesso somente para clientes logadas.
if (
    !isset($_SESSION["usuario_id"]) ||
    ($_SESSION["usuario_tipo"] ?? "") !== "cliente"
) {
    // Redireciona usuários não autorizados para o login.
    header("Location: login.php");
    exit();
}

// Guarda o identificador da cliente para a futura consulta ao banco.
$cliente_id = (int) $_SESSION["usuario_id"];

// Define o fuso horário utilizado pelo sistema.
date_default_timezone_set("America/Sao_Paulo");

// ============================================================
// PREPARAR OS DADOS DA PÁGINA
// ============================================================

// Inicializa as listas que receberão os dados do banco futuramente.
// Nesta etapa, a conexão com os agendamentos ainda não foi implementada.
$proximos_agendamentos = [];
$historico_agendamentos = [];

// Guarda o nome da cliente para personalizar a página.
$nome_cliente = $_SESSION["usuario_nome"] ?? "Cliente";

// ============================================================
// FUNÇÃO PARA FORMATAR E PROTEGER OS TEXTOS
// ============================================================

// Evita que os dados apresentados na página sejam interpretados como HTML.
function escaparTexto($texto)
{
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}

// Formata uma data no padrão brasileiro.
// Retorna um texto alternativo quando a data não é válida.
function formatarDataAgendamento($data)
{
    // Valida o formato da data antes de formatá-la.
    $data_objeto = DateTime::createFromFormat("!Y-m-d", (string) $data);

    // Confere se a data realmente corresponde ao valor recebido.
    if (
        !$data_objeto ||
        $data_objeto->format("Y-m-d") !== (string) $data
    ) {
        return "Data não informada";
    }

    // Apresenta a data no formato dia/mês/ano.
    return $data_objeto->format("d/m/Y");
}

// ============================================================
// PREPARAR A APRESENTAÇÃO DO STATUS
// ============================================================

// Converte o status em um rótulo e uma classe visual.
// Os valores definitivos serão ajustados conforme o banco de dados.
function apresentarStatus($status)
{
    // Normaliza o texto recebido.
    $status = strtolower(trim((string) $status));

    // Define os rótulos conhecidos pelo sistema.
    $status_conhecidos = [
        "pendente" => "Pendente",
        "confirmado" => "Confirmado",
        "concluido" => "Concluído",
        "cancelado" => "Cancelado"
    ];

    // Define uma classe visual segura para cada status conhecido.
    $classes_status = [
        "pendente" => "status-pendente",
        "confirmado" => "status-confirmado",
        "concluido" => "status-concluido",
        "cancelado" => "status-cancelado"
    ];

    // Utiliza um rótulo neutro quando o status não for reconhecido.
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

    <!-- Define o título apresentado na aba do navegador. -->
    <title>Meus Agendamentos | TCC Estética</title>

    <!-- Carrega o CSS compartilhado por todas as páginas do projeto. -->
    <link rel="stylesheet" href="style.css">
</head>

<body class="pagina-cliente pagina-meus-agendamentos">

    <!-- ====================================================== -->
    <!-- CABEÇALHO E NAVEGAÇÃO DA CLIENTE -->
    <!-- ====================================================== -->

    <!-- Mantém a navegação consistente com o painel da cliente. -->
    <header class="cabecalho-cliente">

        <!-- Identificação visual do sistema de estética. -->
        <div class="logo-cliente">
            <h1>Estética</h1>
        </div>

        <!-- Links principais da área da cliente. -->
        <nav class="menu-cliente" aria-label="Navegação principal">
            <a href="painel_cliente.php">Início</a>
            <a href="servicos.php">Serviços</a>
            <a href="meus_agendamentos.php" class="ativo"
               aria-current="page">Meus Agendamentos</a>
            <a href="perfil_cliente.php">Meu Perfil</a>
            <a href="logout.php" class="menu-sair">Sair</a>
        </nav>
    </header>

    <!-- ====================================================== -->
    <!-- CONTEÚDO PRINCIPAL -->
    <!-- ====================================================== -->

    <main class="meus-agendamentos-container">

        <!-- Apresentação da página e orientação para a cliente. -->
        <section class="agendamentos-introducao">
            <span class="agendamentos-etiqueta">
                ÁREA DA CLIENTE
            </span>

            <h2>Meus Agendamentos</h2>

            <p>
                Olá, <?= escaparTexto($nome_cliente) ?>!
                Consulte seus próximos atendimentos e confira
                o histórico dos serviços realizados.
            </p>
        </section>

        <!-- ================================================== -->
        <!-- SEÇÃO DE PRÓXIMOS AGENDAMENTOS -->
        <!-- ================================================== -->

        <section class="secao-agendamentos"
                 id="proximos-agendamentos">

            <!-- Título e descrição da seção. -->
            <div class="agendamentos-titulo-secao">
                <div>
                    <h3>Próximos agendamentos</h3>
                    <p>Confira os detalhes dos seus próximos atendimentos.</p>
                </div>

                <!-- Quantidade calculada a partir dos registros carregados. -->
                <span class="agendamentos-contador">
                    <?= count($proximos_agendamentos) ?>
                </span>
            </div>

            <?php if (!empty($proximos_agendamentos)): ?>

                <!-- Lista os atendimentos futuros da cliente. -->
                <div class="lista-agendamentos">

                    <?php foreach ($proximos_agendamentos as $agendamento): ?>

                        <!-- Cartão individual de cada agendamento. -->
                        <article class="cartao-agendamento">

                            <!-- Apresenta a data e o horário do atendimento. -->
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

                            <!-- Apresenta as informações do serviço escolhido. -->
                            <div class="agendamento-detalhes">
                                <h4>
                                    <?= escaparTexto(
                                        $agendamento["servico"] ?? "Serviço"
                                    ) ?>
                                </h4>

                                <p>
                                    <strong>Profissional:</strong>
                                    <?= escaparTexto(
                                        $agendamento["profissional"] ?? "Não informada"
                                    ) ?>
                                </p>

                                <p>
                                    <strong>Valor:</strong>
                                    <?php if (isset($agendamento["preco"])): ?>
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

                                <!-- Apresenta o status com destaque visual. -->
                                <?php $status = apresentarStatus(
                                    $agendamento["status"] ?? ""
                                ); ?>

                                <span class="agendamento-status <?= escaparTexto($status["classe"]) ?>">
                                    <?= escaparTexto($status["texto"]) ?>
                                </span>
                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <!-- Mensagem apresentada quando não há próximos atendimentos. -->
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

        <!-- ================================================== -->
        <!-- SEÇÃO DO HISTÓRICO -->
        <!-- ================================================== -->

        <section class="secao-agendamentos"
                 id="historico-agendamentos">

            <!-- Título e quantidade de registros anteriores. -->
            <div class="agendamentos-titulo-secao">
                <div>
                    <h3>Histórico de atendimentos</h3>
                    <p>Consulte os atendimentos de datas anteriores.</p>
                </div>

                <!-- Mostra a quantidade de registros históricos. -->
                <span class="agendamentos-contador">
                    <?= count($historico_agendamentos) ?>
                </span>
            </div>

            <?php if (!empty($historico_agendamentos)): ?>

                <!-- Lista os registros históricos da cliente. -->
                <div class="lista-agendamentos">

                    <?php foreach ($historico_agendamentos as $agendamento): ?>

                        <!-- Cartão de um atendimento anterior. -->
                        <article class="cartao-agendamento cartao-historico">

                            <!-- Data e horário do atendimento anterior. -->
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

                            <!-- Resumo do serviço que consta no histórico. -->
                            <div class="agendamento-detalhes">
                                <h4>
                                    <?= escaparTexto(
                                        $agendamento["servico"] ?? "Serviço"
                                    ) ?>
                                </h4>

                                <p>
                                    <strong>Profissional:</strong>
                                    <?= escaparTexto(
                                        $agendamento["profissional"] ?? "Não informada"
                                    ) ?>
                                </p>

                                <p>
                                    <strong>Valor:</strong>
                                    <?php if (isset($agendamento["preco"])): ?>
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

                                <!-- Identifica o resultado do atendimento. -->
                                <?php $status = apresentarStatus(
                                    $agendamento["status"] ?? ""
                                ); ?>

                                <span class="agendamento-status <?= escaparTexto($status["classe"]) ?>">
                                    <?= escaparTexto($status["texto"]) ?>
                                </span>
                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <!-- Mensagem apresentada quando o histórico está vazio. -->
                <div class="agendamentos-vazio">
                    <h4>Nenhum registro no histórico</h4>

                    <p>
                        Seus atendimentos anteriores aparecerão
                        aqui quando os registros forem carregados.
                    </p>
                </div>

            <?php endif; ?>

        </section>

    </main>

    <!-- Rodapé discreto da área da cliente. -->
    <footer class="rodape-agendamentos">
        <p>TCC Estética — Área da Cliente</p>
    </footer>

</body>
</html>