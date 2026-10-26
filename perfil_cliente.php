
<?php
// ============================================================
// INICIAR SESSÃO E VALIDAR O ACESSO
// ============================================================

// Inicia a sessão da cliente.
session_start();

// Carrega a conexão com o banco de dados.
require_once("conexao.php");

// Permite acesso somente para clientes autenticadas.
if (
    !isset($_SESSION["usuario_id"]) ||
    ($_SESSION["usuario_tipo"] ?? "") !== "cliente"
) {
    header("Location: login.php");
    exit();
}

// Guarda o identificador da cliente autenticada.
$cliente_id = (int) $_SESSION["usuario_id"];

// Define o fuso horário utilizado pelo sistema.
date_default_timezone_set("America/Sao_Paulo");

// ============================================================
// PREPARAR MENSAGENS E PROTEÇÃO DO FORMULÁRIO
// ============================================================

// Inicializa as mensagens apresentadas à cliente.
$erro = "";
$sucesso = "";

// Cria um token para proteger o envio do formulário.
if (empty($_SESSION["token_perfil"])) {
    $_SESSION["token_perfil"] = bin2hex(random_bytes(32));
}

// ============================================================
// FUNÇÃO PARA PROTEGER OS TEXTOS EXIBIDOS
// ============================================================

// Impede que textos cadastrados sejam interpretados como HTML.
function escaparPerfil($valor)
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        "UTF-8"
    );
}

// ============================================================
// PROCESSAR A ATUALIZAÇÃO DO PERFIL
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        // Confere o token de segurança enviado pelo formulário.
        $token_recebido = $_POST["token_perfil"] ?? "";

        if (
            !is_string($token_recebido) ||
            !hash_equals($_SESSION["token_perfil"], $token_recebido)
        ) {
            throw new Exception(
                "Não foi possível validar o formulário. Atualize a página e tente novamente."
            );
        }

        // Recebe e organiza os dados pessoais informados.
        $nome = trim($_POST["nome"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $telefone = trim($_POST["telefone"] ?? "");

        // Recebe os campos de alteração de senha.
        $senha_atual = $_POST["senha_atual"] ?? "";
        $nova_senha = $_POST["nova_senha"] ?? "";
        $confirmar_senha = $_POST["confirmar_senha"] ?? "";

        // Valida os campos pessoais obrigatórios.
        if ($nome === "" || $email === "") {
            throw new Exception(
                "Preencha seu nome e seu e-mail."
            );
        }

        // Confere o formato do endereço de e-mail.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception(
                "Informe um endereço de e-mail válido."
            );
        }

        // Limita o tamanho dos campos conforme a estrutura esperada.
        if (
            mb_strlen($nome) > 150 ||
            mb_strlen($email) > 254 ||
            mb_strlen($telefone) > 30
        ) {
            throw new Exception(
                "Um ou mais campos ultrapassaram o tamanho permitido."
            );
        }

        // Busca os dados atuais para validar a senha e o e-mail.
        $consulta = $conexao->prepare("
            SELECT id, senha
            FROM usuarios
            WHERE id = :id
              AND tipo_usuario = 'cliente'
            LIMIT 1
        ");

        $consulta->execute([":id" => $cliente_id]);

        $cliente_atual = $consulta->fetch(PDO::FETCH_ASSOC);

        // Interrompe a operação se a conta não existir mais.
        if (!$cliente_atual) {
            throw new Exception(
                "Não foi possível localizar sua conta."
            );
        }

        // Verifica se o e-mail já pertence a outra conta.
        $consulta = $conexao->prepare("
            SELECT id
            FROM usuarios
            WHERE email = :email
              AND id <> :id
            LIMIT 1
        ");

        $consulta->execute([
            ":email" => $email,
            ":id" => $cliente_id
        ]);

        if ($consulta->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception(
                "Este e-mail já está cadastrado em outra conta."
            );
        }

        // Prepara a atualização dos dados pessoais.
        $campos_atualizacao = [
            "nome" => $nome,
            "email" => $email,
            "telefone" => $telefone
        ];

        // Só altera a senha quando algum campo de senha foi preenchido.
        $alterar_senha = (
            $senha_atual !== "" ||
            $nova_senha !== "" ||
            $confirmar_senha !== ""
        );

        if ($alterar_senha) {
            // Exige o preenchimento dos três campos de senha.
            if (
                $senha_atual === "" ||
                $nova_senha === "" ||
                $confirmar_senha === ""
            ) {
                throw new Exception(
                    "Preencha a senha atual, a nova senha e a confirmação."
                );
            }

            // Confere a senha atual usando o hash armazenado.
            if (!password_verify($senha_atual, $cliente_atual["senha"])) {
                throw new Exception(
                    "A senha atual informada está incorreta."
                );
            }

            // Exige uma nova senha com pelo menos seis caracteres.
            if (mb_strlen($nova_senha) < 6) {
                throw new Exception(
                    "A nova senha deve possuir pelo menos seis caracteres."
                );
            }

            // Confere se os dois campos de senha coincidem.
            if ($nova_senha !== $confirmar_senha) {
                throw new Exception(
                    "A nova senha e a confirmação são diferentes."
                );
            }

            // Gera um hash seguro para armazenar a nova senha.
            $campos_atualizacao["senha"] = password_hash(
                $nova_senha,
                PASSWORD_DEFAULT
            );
        }

        // Monta a atualização sem incluir campos não autorizados.
        $sql_atualizacao = "
            UPDATE usuarios
            SET nome = :nome,
                email = :email,
                telefone = :telefone
        ";

        // Inclui a senha na consulta somente se houve alteração.
        if ($alterar_senha) {
            $sql_atualizacao .= ", senha = :senha";
        }

        // Restringe a atualização à conta da cliente autenticada.
        $sql_atualizacao .= "
            WHERE id = :id
              AND tipo_usuario = 'cliente'
        ";

        // Prepara a atualização dos dados da conta.
        $atualizacao = $conexao->prepare($sql_atualizacao);

        // Define os parâmetros pessoais da consulta.
        $parametros = [
            ":nome" => $nome,
            ":email" => $email,
            ":telefone" => $telefone,
            ":id" => $cliente_id
        ];

        // Adiciona o hash da senha somente quando necessário.
        if ($alterar_senha) {
            $parametros[":senha"] = $campos_atualizacao["senha"];
        }

        // Executa a atualização no banco de dados.
        $atualizacao->execute($parametros);

        // Atualiza o nome armazenado na sessão.
        $_SESSION["usuario_nome"] = $nome;

        // Renova o token após o envio bem-sucedido.
        $_SESSION["token_perfil"] = bin2hex(random_bytes(32));

        // Confirma a atualização dos dados.
        $sucesso = "Seus dados foram atualizados com sucesso.";

    } catch (PDOException $erro_banco) {
        // Registra os detalhes técnicos sem exibi-los na página.
        error_log("Erro ao atualizar perfil da cliente: " . $erro_banco->getMessage());

        // Apresenta uma mensagem compreensível para a cliente.
        $erro = "Não foi possível atualizar os dados. Confira se o e-mail já está cadastrado e tente novamente.";

    } catch (Exception $erro_formulario) {
        // Apresenta erros de validação do próprio formulário.
        $erro = $erro_formulario->getMessage();
    }
}

// ============================================================
// CARREGAR OS DADOS ATUAIS DA CLIENTE
// ============================================================

try {
    // Consulta somente a conta da cliente autenticada.
    $consulta = $conexao->prepare("
        SELECT nome, email, telefone, data_cadastro
        FROM usuarios
        WHERE id = :id
          AND tipo_usuario = 'cliente'
        LIMIT 1
    ");

    $consulta->execute([":id" => $cliente_id]);

    $cliente = $consulta->fetch(PDO::FETCH_ASSOC);

    // Impede a exibição de um perfil que não foi localizado.
    if (!$cliente) {
        throw new Exception(
            "Não foi possível carregar os dados da sua conta."
        );
    }

} catch (Exception $erro_carregamento) {
    // Registra a falha técnica para análise posterior.
    error_log("Erro ao carregar perfil da cliente: " . $erro_carregamento->getMessage());

    // Interrompe a página sem expor detalhes do banco.
    http_response_code(500);
    exit("Não foi possível carregar seu perfil. Tente novamente mais tarde.");
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <!-- Configura os caracteres e a adaptação para celulares. -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Define o título da página. -->
    <title>Meu Perfil | TCC Estética</title>

    <!-- Carrega os estilos compartilhados do projeto. -->
    <link rel="stylesheet" href="style.css">
</head>

<body class="pagina-cliente pagina-perfil-cliente">

    <!-- ====================================================== -->
    <!-- CABEÇALHO E MENU DA CLIENTE -->
    <!-- ====================================================== -->

    <header class="cabecalho-cliente">

        <!-- Identificação visual do sistema. -->
        <div class="logo-cliente">
            <h1>Estética</h1>
        </div>

        <!-- Menu principal compartilhado com as demais páginas. -->
        <nav class="menu-cliente" aria-label="Navegação principal">
            <a href="painel_cliente.php">Início</a>
            <a href="servicos.php">Serviços</a>
            <a href="meus_agendamentos.php">Meus Agendamentos</a>
            <a href="perfil_cliente.php" class="ativo" aria-current="page">Meu Perfil</a>
            <a href="logout.php" class="menu-sair">Sair</a>
        </nav>
    </header>

    <!-- ====================================================== -->
    <!-- CONTEÚDO PRINCIPAL DO PERFIL -->
    <!-- ====================================================== -->

    <main class="perfil-container">

        <!-- Apresenta o título e a finalidade da página. -->
        <section class="perfil-introducao">
            <span class="perfil-etiqueta">MINHA CONTA</span>
            <h2>Meu Perfil</h2>
            <p>Consulte seus dados pessoais e mantenha sua conta atualizada.</p>
        </section>

        <!-- Exibe mensagens de resultado do formulário. -->
        <?php if ($sucesso !== ""): ?>
            <div class="perfil-mensagem perfil-sucesso" role="status">
                <?= escaparPerfil($sucesso) ?>
            </div>
        <?php endif; ?>

        <?php if ($erro !== ""): ?>
            <div class="perfil-mensagem perfil-erro" role="alert">
                <?= escaparPerfil($erro) ?>
            </div>
        <?php endif; ?>

        <!-- Cartão de identificação da cliente. -->
        <section class="perfil-cartao perfil-identificacao">

            <!-- Espaço reservado para a futura foto de perfil. -->
            <div class="perfil-foto">
                <span><?= escaparPerfil(mb_strtoupper(mb_substr($cliente["nome"], 0, 1))) ?></span>
            </div>

            <!-- Mostra os dados principais da conta. -->
            <div class="perfil-identificacao-texto">
                <h3><?= escaparPerfil($cliente["nome"]) ?></h3>
                <p><?= escaparPerfil($cliente["email"]) ?></p>
                <span class="perfil-tipo-conta">Cliente</span>
            </div>
        </section>

        <!-- Formulário para atualizar os dados e a senha. -->
        <form method="POST" action="perfil_cliente.php" class="perfil-formulario">

            <!-- Envia o token de proteção do formulário. -->
            <input
                type="hidden"
                name="token_perfil"
                value="<?= escaparPerfil($_SESSION["token_perfil"]) ?>"
            >

            <!-- Seção de dados pessoais. -->
            <section class="perfil-cartao">

                <div class="perfil-titulo-secao">
                    <h3>Dados pessoais</h3>
                    <p>Atualize as informações da sua conta.</p>
                </div>

                <!-- Campo para editar o nome. -->
                <div class="perfil-campo">
                    <label for="nome">Nome completo</label>
                    <input
                        type="text"
                        id="nome"
                        name="nome"
                        maxlength="150"
                        value="<?= escaparPerfil($cliente["nome"]) ?>"
                        autocomplete="name"
                        required
                    >
                </div>

                <!-- Campo para editar o e-mail. -->
                <div class="perfil-campo">
                    <label for="email">E-mail</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="254"
                        value="<?= escaparPerfil($cliente["email"]) ?>"
                        autocomplete="email"
                        required
                    >
                </div>

                <!-- Campo para editar o telefone. -->
                <div class="perfil-campo">
                    <label for="telefone">Telefone</label>
                    <input
                        type="tel"
                        id="telefone"
                        name="telefone"
                        maxlength="30"
                        value="<?= escaparPerfil($cliente["telefone"] ?? "") ?>"
                        autocomplete="tel"
                    >
                </div>

                <!-- Mostra a data de cadastro sem permitir sua edição. -->
                <div class="perfil-campo">
                    <label for="data_cadastro">Data de cadastro</label>
                    <input
                        type="text"
                        id="data_cadastro"
                        value="<?= escaparPerfil($cliente["data_cadastro"] ?? "Não informada") ?>"
                        readonly
                    >
                </div>

            </section>

            <!-- Seção para alteração opcional da senha. -->
            <section class="perfil-cartao">

                <div class="perfil-titulo-secao">
                    <h3>Segurança da conta</h3>
                    <p>
                        Para manter sua senha atual, deixe os três campos abaixo vazios.
                    </p>
                </div>

                <!-- Solicita a senha atual para autorizar a alteração. -->
                <div class="perfil-campo">
                    <label for="senha_atual">Senha atual</label>
                    <input
                        type="password"
                        id="senha_atual"
                        name="senha_atual"
                        autocomplete="current-password"
                    >
                </div>

                <!-- Recebe a nova senha, quando desejado. -->
                <div class="perfil-campo">
                    <label for="nova_senha">Nova senha</label>
                    <input
                        type="password"
                        id="nova_senha"
                        name="nova_senha"
                        minlength="6"
                        autocomplete="new-password"
                    >
                </div>

                <!-- Confirma a nova senha para evitar erros de digitação. -->
                <div class="perfil-campo">
                    <label for="confirmar_senha">Confirmar nova senha</label>
                    <input
                        type="password"
                        id="confirmar_senha"
                        name="confirmar_senha"
                        minlength="6"
                        autocomplete="new-password"
                    >
                </div>

            </section>

            <!-- Botões para salvar os dados ou consultar os atendimentos. -->
            <div class="perfil-acoes">
                <button type="submit" class="perfil-botao">
                    Salvar alterações
                </button>

                <a href="meus_agendamentos.php" class="perfil-botao-secundario">
                    Ver meus agendamentos
                </a>
            </div>

        </form>

        <!-- Área que receberá o resumo de atividades na etapa de integração. -->
        <section class="perfil-cartao perfil-resumo-atividades">

            <div class="perfil-titulo-secao">
                <h3>Minha atividade</h3>
                <p>Um resumo dos seus atendimentos será apresentado aqui.</p>
            </div>

            <!-- Os indicadores serão preenchidos com dados reais posteriormente. -->
            <div class="perfil-resumo-pendente">
                <p>
                    O total de atendimentos realizados, o próximo horário
                    e o resumo dos valores serão conectados ao banco de dados
                    na etapa de integração dos agendamentos.
                </p>

                <a href="meus_agendamentos.php" class="perfil-link-historico">
                    Consultar meus atendimentos
                </a>
            </div>

        </section>

    </main>

    <!-- Rodapé da área da cliente. -->
    <footer class="rodape-agendamentos">
        <p>TCC Estética — Área da Cliente</p>
    </footer>

</body>
</html>