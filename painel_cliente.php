<?php

// ============================================================
// INICIAR SESSÃO E CONEXÃO
// ============================================================

// Inicia a sessão para reconhecer a cliente que está logada.
session_start();

// Inclui o arquivo responsável pela conexão com o banco de dados.
require_once("conexao.php");


// ============================================================
// VERIFICAÇÃO DE SEGURANÇA
// ============================================================

// Verifica se existe uma cliente logada no sistema.
if (!isset($_SESSION["usuario_id"])) {

    // Se não estiver logada, volta para a tela de login.
    header("Location: login.php");
    exit();
}

// Verifica se o usuário logado realmente é uma cliente.
if (!isset($_SESSION["usuario_tipo"]) || $_SESSION["usuario_tipo"] !== "cliente") {

    // Profissionais ou outros usuários não podem acessar o painel da cliente.
    header("Location: login.php");
    exit();
}


// ============================================================
// DADOS DA CLIENTE
// ============================================================

// Guarda o ID da cliente atualmente logada.
$cliente_id = (int)$_SESSION["usuario_id"];

// Guarda o nome salvo na sessão.
// Caso não exista, utiliza "Cliente" como nome padrão.
$nome_cliente = $_SESSION["usuario_nome"] ?? "Cliente";

// Separa o primeiro nome para deixar a saudação mais amigável.
$primeiro_nome = explode(" ", trim($nome_cliente))[0];


// ============================================================
// DEFINIR A SEÇÃO ATUAL
// ============================================================

// Define a seção que será mostrada no painel.

// Se não houver nenhuma seção informada na URL,
// o painel começa na tela inicial.
$secao = $_GET["secao"] ?? "inicio";

// Permite somente as seções que realmente existem no painel.
$secoes_permitidas = [
    "inicio",
    "servicos",
    "agendamentos"
];

// Se alguém tentar acessar uma seção inexistente,
// volta automaticamente para a tela inicial.
if (!in_array($secao, $secoes_permitidas, true)) {
    $secao = "inicio";
}


// ============================================================
// CATEGORIA DE SERVIÇO
// ============================================================

// Define a categoria selecionada na tela de serviços.

// A categoria será:
// nail      = Unhas
// lash      = Cílios
// eyebrow   = Sobrancelhas
$categoria = $_GET["categoria"] ?? "";

// Lista as categorias permitidas.
$categorias_permitidas = [
    "nail",
    "lash",
    "eyebrow"
];

// Se a categoria informada não existir,
// ela será considerada vazia.
if (!in_array($categoria, $categorias_permitidas, true)) {
    $categoria = "";
}


// ============================================================
// TRADUÇÃO DAS CATEGORIAS
// ============================================================

// Define o nome que será mostrado para cada especialidade.
$nomes_categorias = [
    "nail" => "Unhas",
    "lash" => "Cílios",
    "eyebrow" => "Sobrancelhas"
];


// ============================================================
// BUSCAR DADOS DA CLIENTE
// ============================================================

// Inicializa os dados para evitar variáveis inexistentes.
$cliente = [
    "nome" => $nome_cliente,
    "email" => "",
    "telefone" => ""
];

try {

    // Busca os dados atuais da cliente diretamente no banco.
    $sql_cliente = "SELECT nome, email, telefone
                      FROM usuarios
                     WHERE id = :id
                       AND tipo_usuario = 'cliente'
                     LIMIT 1";

    // Prepara a consulta.
    $stmt_cliente = $conexao->prepare($sql_cliente);

    // Envia o ID da cliente com segurança.
    $stmt_cliente->bindValue(":id", $cliente_id, PDO::PARAM_INT);

    // Executa a consulta.
    $stmt_cliente->execute();

    // Recupera os dados encontrados.
    $dados_cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);

    // Se encontrou a cliente, atualiza os dados.
    if ($dados_cliente) {

        $cliente = $dados_cliente;

        // Atualiza também o nome usado na sessão.
        $_SESSION["usuario_nome"] = $cliente["nome"];

        // Atualiza o primeiro nome mostrado na tela.
        $primeiro_nome = explode(" ", trim($cliente["nome"]))[0];
    }

} catch (PDOException $erro) {

    // Registra o erro no log sem interromper o funcionamento do painel.
    error_log("Erro ao buscar dados da cliente: " . $erro->getMessage());
}


// ============================================================
// PRÓXIMO AGENDAMENTO DA CLIENTE
// ============================================================

// Começa sem nenhum agendamento encontrado.
$proximo_agendamento = null;

try {

    // Busca o próximo agendamento futuro ou do dia atual.
    // Também busca o serviço e a profissional responsável.
    $sql_proximo = "SELECT
                        a.id,
                        a.data_pagamento,
                        a.horario,
                        a.status,
                        s.nome_servico,
                        s.preco,
                        s.duracao,
                        u.nome AS profissional_nome,
                        u.especialidade
                    FROM agendamentos a

                    LEFT JOIN servicos s
                        ON a.servico_id = s.id

                    LEFT JOIN usuarios u
                        ON a.profissional_id = u.id

                    WHERE a.cliente_id = :cliente_id
                      AND a.data_pagamento >= CURDATE()
                      AND a.status = 'pendente'

                    ORDER BY a.data_pagamento ASC,
                             a.horario ASC

                    LIMIT 1";

    // Prepara a consulta.
    $stmt_proximo = $conexao->prepare($sql_proximo);

    // Envia o ID da cliente.
    $stmt_proximo->bindValue(
        ":cliente_id",
        $cliente_id,
        PDO::PARAM_INT
    );

    // Executa.
    $stmt_proximo->execute();

    // Guarda o próximo agendamento.
    $proximo_agendamento = $stmt_proximo->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $erro) {

    // Registra qualquer problema no log.
    error_log("Erro ao buscar próximo agendamento: " . $erro->getMessage());
}


// ============================================================
// BUSCAR TODOS OS AGENDAMENTOS DA CLIENTE
// ============================================================

// Cria uma lista vazia para os agendamentos.
$agendamentos_cliente = [];

try {

    // Busca os agendamentos da cliente.
    $sql_agendamentos = "SELECT
                            a.id,
                            a.data_pagamento,
                            a.horario,
                            a.status,
                            s.nome_servico,
                            s.preco,
                            s.duracao,
                            u.nome AS profissional_nome,
                            u.especialidade
                         FROM agendamentos a

                         LEFT JOIN servicos s
                            ON a.servico_id = s.id

                         LEFT JOIN usuarios u
                            ON a.profissional_id = u.id

                         WHERE a.cliente_id = :cliente_id

                         ORDER BY a.data_pagamento DESC,
                                  a.horario DESC";

    // Prepara a consulta.
    $stmt_agendamentos = $conexao->prepare($sql_agendamentos);

    // Envia o ID da cliente.
    $stmt_agendamentos->bindValue(
        ":cliente_id",
        $cliente_id,
        PDO::PARAM_INT
    );

    // Executa a consulta.
    $stmt_agendamentos->execute();

    // Recupera todos os agendamentos.
    $agendamentos_cliente = $stmt_agendamentos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $erro) {

    // Registra o erro no log.
    error_log("Erro ao buscar agendamentos da cliente: " . $erro->getMessage());
}


// ============================================================
// BUSCAR SERVIÇOS
// ============================================================

// Cria uma lista vazia para os serviços.
$servicos = [];

try {

    /*
       A especialidade da profissional é usada para organizar
       os serviços em:

       nail    = Unhas
       lash    = Cílios
       eyebrow = Sobrancelhas

       A tabela vinculacao liga a profissional ao serviço.
    */

    $sql_servicos = "SELECT
                        s.id,
                        s.nome_servico,
                        s.tipo_servico,
                        s.descricao,
                        s.preco,
                        s.duracao,
                        s.status,

                        u.id AS profissional_id,
                        u.nome AS profissional_nome,
                        u.especialidade AS profissional_especialidade

                     FROM servicos s

                     INNER JOIN vinculacao v
                        ON v.servico_id = s.id

                     INNER JOIN usuarios u
                        ON u.id = v.profissional_id

                     WHERE s.status = 'ativo'
                       AND u.tipo_usuario = 'profissional'

                     ORDER BY s.tipo_servico ASC,
                              s.nome_servico ASC";

    // Prepara a consulta.
    $stmt_servicos = $conexao->prepare($sql_servicos);

    // Executa a consulta.
    $stmt_servicos->execute();

    // Recupera os serviços.
    $servicos = $stmt_servicos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $erro) {

    // Registra o problema no log.
    error_log("Erro ao buscar serviços: " . $erro->getMessage());
}


// ============================================================
// FILTRAR SERVIÇOS PELA CATEGORIA ESCOLHIDA
// ============================================================

// Começa com uma lista vazia.
$servicos_categoria = [];

// Somente filtra quando uma categoria foi selecionada.
if ($categoria !== "") {

    // Percorre todos os serviços encontrados.
    foreach ($servicos as $servico) {

        // Verifica se a especialidade da profissional
        // corresponde à categoria escolhida.
        if ($servico["profissional_especialidade"] === $categoria) {

            // Adiciona o serviço à lista da categoria.
            $servicos_categoria[] = $servico;
        }
    }
}


// ============================================================
// DATA ATUAL
// ============================================================

// Define o fuso horário brasileiro.
date_default_timezone_set("America/Sao_Paulo");

// Guarda a data atual.
$data_atual = date("d/m/Y");

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <!-- Define a codificação dos caracteres. -->
    <meta charset="UTF-8">

    <!-- Permite adaptação em celulares e tablets. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Nome da página no navegador. -->
    <title>Painel do Cliente | TCC Estética</title>

    <!-- Arquivo principal de estilos do sistema. -->
    <link rel="stylesheet" href="style.css">

</head>

<body class="pagina-cliente">


<!-- ============================================================
     CABEÇALHO DO PAINEL DO CLIENTE
     ============================================================ -->

<header class="cabecalho-cliente">

    <!-- Nome do sistema. -->
    <div class="logo-cliente">

        <h1>Estética</h1>

    </div>


    <!-- Menu principal da cliente. -->
    <nav class="menu-cliente">

        <!-- Link para a tela inicial. -->
        <a
            href="painel_cliente.php"
            class="<?= $secao === 'inicio' ? 'ativo' : '' ?>"
        >
            Início
        </a>


        <!-- Link para os serviços. -->
        <a
            href="servicos.php"
            class="<?= $secao === 'servicos' ? 'ativo' : '' ?>"
        >
            Serviços
        </a>


        <!-- Link para os agendamentos. -->
        <a
            href="meus_agendamentos.php"
            class="<?= $secao === 'agendamentos' ? 'ativo' : '' ?>"
        >
            Meus Agendamentos
        </a>

        <a href="perfil_cliente.php">Meu Perfil</a>

        <!-- Botão para sair do sistema. -->
        <a
            href="logout.php"
            class="menu-sair"
        >
            Sair
        </a>

    </nav>

</header>


<!-- ============================================================
     CONTEÚDO PRINCIPAL
     ============================================================ -->

<main class="conteudo-cliente">


<?php if ($secao === "inicio"): ?>

    <!-- ========================================================
         TELA INICIAL
         ======================================================== -->

    <section class="topo-cliente">

        <div>
            <h2>
                Bem-vinda, <?= htmlspecialchars($primeiro_nome) ?>!
            </h2>

            <p>
                Aqui você pode acompanhar seus atendimentos,
                conhecer nossos procedimentos e encontrar horários disponíveis.
            </p>

        </div>


        <!-- Mostra a data atual. -->
        <div class="data-cliente">

            <?= htmlspecialchars($data_atual) ?>

        </div>

    </section>


    <!-- ========================================================
         QUADROS DE INFORMAÇÕES
         ======================================================== -->

    <section class="cards-cliente">


        <!-- ====================================================
             QUADRO DE FIDELIDADE
             ==================================================== -->

        <article class="card-cliente card-fidelidade">

            <span class="card-etiqueta">
                BENEFÍCIO
            </span>

            <h3>
                Programa de Fidelidade
            </h3>

            <p>
                A cada 10 atendimentos realizados,
                você ganha 15% de desconto no próximo procedimento.
            </p>

            <span class="card-detalhe">
                Acumule benefícios pensados especialmente para você.
            </span>

        </article>


        <!-- ====================================================
             QUADRO DO PRÓXIMO ATENDIMENTO
             ==================================================== -->

        <article class="card-cliente">

            <span class="card-etiqueta">
                SEU ATENDIMENTO
            </span>


            <?php if ($proximo_agendamento): ?>

                <h3>
                    Próximo atendimento
                </h3>

                <p class="destaque-card">
                    <?= htmlspecialchars($proximo_agendamento["nome_servico"] ?? "Serviço") ?>
                </p>

                <p>
                    <strong>Data:</strong>
                    <?= date(
                        "d/m/Y",
                        strtotime($proximo_agendamento["data_pagamento"])
                    ) ?>
                </p>

                <p>
                    <strong>Horário:</strong>
                    <?= htmlspecialchars($proximo_agendamento["horario"]) ?>
                </p>

                <p>
                    <strong>Profissional:</strong>
                    <?= htmlspecialchars($proximo_agendamento["profissional_nome"] ?? "--") ?>
                </p>


                <a
                    href="meus_agendamentos.php"
                    class="link-card"
                >
                    Ver meus agendamentos
                </a>


            <?php else: ?>

                <h3>
                    Nenhum horário marcado
                </h3>

                <p>
                    Você ainda não possui um atendimento agendado.
                </p>

                <a
                    href="servicos.php"
                    class="link-card"
                >
                    Conhecer nossos serviços
                </a>

            <?php endif; ?>

        </article>


        <!-- ====================================================
             QUADRO DE AVISOS
             ==================================================== -->

        <article class="card-cliente card-aviso">

            <span class="card-etiqueta">
                AVISO
            </span>

            <h3>
                Informações importantes
            </h3>

            <p>
                Fique atenta às informações sobre seus atendimentos,
                horários e procedimentos.
            </p>

            <p class="card-detalhe">
                Novos avisos poderão aparecer aqui conforme
                as informações forem disponibilizadas pela equipe.
            </p>

        </article>

    </section>


    <!-- ========================================================
         ÁREA DE ACESSO RÁPIDO
         ======================================================== -->

    <section class="acesso-rapido">

        <div class="titulo-secao">

            <span>ACESSO RÁPIDO</span>

            <h2>
                O que você deseja fazer?
            </h2>

        </div>


        <div class="atalhos-cliente">


            <!-- Acesso aos serviços. -->
            <a
                href="servicos.php"
                class="atalho-cliente"
            >

                <h3>
                    Conhecer os serviços
                </h3>

                <p>
                    Veja unhas, cílios e sobrancelhas
                    e conheça nossos procedimentos.
                </p>

            </a>


            <!-- Acesso aos agendamentos. -->
            <a
                href="meus_agendamentos.php"
                class="atalho-cliente"
            >

                <h3>
                    Meus agendamentos
                </h3>

                <p>
                    Consulte seus horários
                    e acompanhe seus atendimentos.
                </p>

            </a>

        </div>

    </section>


<?php elseif ($secao === "servicos"): ?>

    <!-- ========================================================
         TELA DE SERVIÇOS
         ======================================================== -->

    <section class="topo-secao-cliente">

        <div>

            <span class="etiqueta-cliente">
                PROCEDIMENTOS
            </span>

            <h2>
                Conheça nossos serviços
            </h2>

            <p>
                Escolha uma categoria para conhecer os procedimentos
                e a profissional responsável.
            </p>

        </div>

    </section>


    <!-- ========================================================
         CATEGORIAS DOS SERVIÇOS
         ======================================================== -->

    <section class="categorias-servicos">


        <!-- Categoria Unhas. -->
        <a
            href="servicos.php?categoria=nail"
            class="categoria-servico <?= $categoria === 'nail' ? 'ativo' : '' ?>"
        >

            <span>
                UNHAS
            </span>

            <h3>
                Nail Designer
            </h3>

            <p>
                Conheça os procedimentos para unhas.
            </p>

        </a>


        <!-- Categoria Cílios. -->
        <a
            href="servicos.php?categoria=lash"
            class="categoria-servico <?= $categoria === 'lash' ? 'ativo' : '' ?>"
        >

            <span>
                CÍLIOS
            </span>

            <h3>
                Lash Designer
            </h3>

            <p>
                Conheça nossas opções para cílios.
            </p>

        </a>


        <!-- Categoria Sobrancelhas. -->
        <a
            href="servicos.php?categoria=eyebrow"
            class="categoria-servico <?= $categoria === 'eyebrow' ? 'ativo' : '' ?>"
        >

            <span>
                SOBRANCELHAS
            </span>

            <h3>
                Designer de Sobrancelhas
            </h3>

            <p>
                Veja os procedimentos para sobrancelhas.
            </p>

        </a>

    </section>


    <?php if ($categoria !== ""): ?>

        <!-- ====================================================
             PROFISSIONAL RESPONSÁVEL
             ==================================================== -->

        <?php

        // Guarda a profissional responsável pelo primeiro serviço.
        $profissional_categoria = $servicos_categoria[0]["profissional_nome"] ?? null;

        ?>


        <section class="categoria-conteudo">

            <div class="categoria-cabecalho">

                <div>

                    <span class="etiqueta-cliente">
                        <?= strtoupper($nomes_categorias[$categoria]) ?>
                    </span>

                    <h2>
                        Procedimentos disponíveis
                    </h2>

                </div>


                <?php if ($profissional_categoria): ?>

                    <div class="profissional-responsavel">

                        <span>
                            PROFISSIONAL RESPONSÁVEL
                        </span>

                        <strong>
                            <?= htmlspecialchars($profissional_categoria) ?>
                        </strong>

                    </div>

                <?php endif; ?>

            </div>


            <!-- ==================================================
                 LISTA DE PROCEDIMENTOS
                 ================================================== -->

            <?php if (count($servicos_categoria) > 0): ?>

                <div class="grade-servicos-cliente">


                    <?php foreach ($servicos_categoria as $servico): ?>

                        <article class="servico-card">

                            <div class="servico-card-conteudo">

                                <span class="servico-tipo">
                                    <?= htmlspecialchars(
                                        ucfirst($servico["tipo_servico"])
                                    ) ?>
                                </span>


                                <h3>
                                    <?= htmlspecialchars(
                                        $servico["nome_servico"]
                                    ) ?>
                                </h3>


                                <p class="servico-descricao">

                                    <?= htmlspecialchars(
                                        $servico["descricao"] ?? "Procedimento realizado com cuidado e atenção."
                                    ) ?>

                                </p>


                                <!-- Duração do procedimento. -->
                                <div class="servico-informacao">

                                    <strong>
                                        Duração
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars(
                                            $servico["duracao"] ?? "--"
                                        ) ?>
                                    </span>

                                </div>


                                <!-- Preço do procedimento. -->
                                <div class="servico-informacao">

                                    <strong>
                                        Valor
                                    </strong>

                                    <span class="servico-preco">

                                        R$
                                        <?= number_format(
                                            (float)$servico["preco"],
                                            2,
                                            ",",
                                            "."
                                        ) ?>

                                    </span>

                                </div>


                                <!-- Profissional responsável. -->
                                <div class="servico-profissional">

                                    <span>
                                        Profissional
                                    </span>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $servico["profissional_nome"]
                                        ) ?>
                                    </strong>

                                </div>


                                <!-- Botão que leva para a agenda da profissional. -->
                                <a
                                    href="agenda.php?servico_id=<?= (int)$servico["id"] ?>"
                                    class="btn-horarios"
                                >
                                    Ver horários disponíveis
                                </a>

                            </div>

                        </article>

                    <?php endforeach; ?>


                </div>


            <?php else: ?>

                <!-- Mensagem caso não existam serviços cadastrados. -->
                <div class="sem-servicos">

                    <h3>
                        Nenhum procedimento disponível
                    </h3>

                    <p>
                        Ainda não existem procedimentos cadastrados
                        para esta categoria.
                    </p>

                </div>

            <?php endif; ?>

        </section>


    <?php else: ?>

        <!-- ====================================================
             MENSAGEM ANTES DE ESCOLHER UMA CATEGORIA
             ==================================================== -->

        <section class="mensagem-servicos">

            <h2>
                Escolha uma categoria
            </h2>

            <p>
                Selecione entre unhas, cílios ou sobrancelhas
                para visualizar os procedimentos disponíveis.
            </p>

        </section>

    <?php endif; ?>


<?php elseif ($secao === "agendamentos"): ?>

    <!-- ========================================================
         TELA DE MEUS AGENDAMENTOS
         ======================================================== -->

    <section class="topo-secao-cliente">

        <div>

            <span class="etiqueta-cliente">
                MINHA AGENDA
            </span>

            <h2>
                Meus Agendamentos
            </h2>

            <p>
                Consulte seus atendimentos anteriores e futuros.
            </p>

        </div>

    </section>


    <!-- ========================================================
         LISTA DE AGENDAMENTOS
         ======================================================== -->

    <section class="lista-agendamentos">

        <?php if (count($agendamentos_cliente) > 0): ?>


            <?php foreach ($agendamentos_cliente as $agendamento): ?>

                <article class="agendamento-card">

                    <div class="agendamento-data">

                        <span>
                            DATA
                        </span>

                        <strong>

                            <?= date(
                                "d/m/Y",
                                strtotime($agendamento["data_pagamento"])
                            ) ?>

                        </strong>

                        <small>

                            <?= htmlspecialchars(
                                $agendamento["horario"]
                            ) ?>

                        </small>

                    </div>


                    <div class="agendamento-informacoes">

                        <span class="card-etiqueta">
                            PROCEDIMENTO
                        </span>

                        <h3>
                            <?= htmlspecialchars(
                                $agendamento["nome_servico"] ?? "--"
                            ) ?>
                        </h3>

                        <p>
                            <strong>
                                Profissional:
                            </strong>

                            <?= htmlspecialchars(
                                $agendamento["profissional_nome"] ?? "--"
                            ) ?>
                        </p>

                        <p>
                            <strong>
                                Duração:
                            </strong>

                            <?= htmlspecialchars(
                                $agendamento["duracao"] ?? "--"
                            ) ?>
                        </p>

                    </div>


                    <div class="agendamento-status">

                        <?php

                        // Define a classe visual conforme o status.
                        $classe_status = "status-pendente";

                        if ($agendamento["status"] === "concluido") {
                            $classe_status = "status-concluido";
                        }

                        if ($agendamento["status"] === "cancelado") {
                            $classe_status = "status-cancelado";
                        }

                        ?>

                        <span class="<?= $classe_status ?>">

                            <?= htmlspecialchars(
                                ucfirst($agendamento["status"])
                            ) ?>

                        </span>

                    </div>

                </article>

            <?php endforeach; ?>


        <?php else: ?>

            <!-- Mensagem quando a cliente ainda não possui agendamentos. -->
            <div class="sem-agendamentos">

                <h3>
                    Você ainda não possui agendamentos
                </h3>

                <p>
                    Conheça nossos serviços e escolha um procedimento
                    para encontrar um horário disponível.
                </p>

                <a
                    href="servicos.php"
                    class="btn-principal-cliente"
                >
                    Conhecer serviços
                </a>

            </div>

        <?php endif; ?>

    </section>

<?php endif; ?>


</main>


<!-- ============================================================
     RODAPÉ
     ============================================================ -->

<footer class="rodape-cliente">

    <p>
        TCC Estética — Área da Cliente
    </p>

</footer>


</body>

</html>