
<?php
// ============================================================
// 1. INICIAR SESSÃO E VALIDAR ACESSO
// ============================================================

session_start(); // Inicia a sessão da profissional.
require_once("conexao.php"); // Carrega a conexão com o banco.

// Permite acesso somente às profissionais autenticadas.
if (
    !isset($_SESSION["usuario_id"]) ||
    ($_SESSION["usuario_tipo"] ?? "") !== "profissional"
) {
    header("Location: login.php");
    exit();
}

// Guarda o ID da profissional autenticada.
$profissional_id = (int) $_SESSION["usuario_id"];

// Inicializa as mensagens da página.
$mensagem_sucesso = "";
$mensagem_erro = "";
$erroConsulta = false;


// ============================================================
// 2. CARREGAR DADOS ATUAIS DO PERFIL
// ============================================================

try {
    // Busca os dados pessoais e a chave Pix cadastrada.
    $consulta = $conexao->prepare("
        SELECT
            id,
            nome,
            email,
            telefone,
            especialidade,
            chave_pix
        FROM usuarios
        WHERE id = :id
        LIMIT 1
    ");

    $consulta->execute([
        ":id" => $profissional_id
    ]);

    // Recupera os dados salvos no banco.
    $usuario = $consulta->fetch(PDO::FETCH_ASSOC);

    // Verifica se a conta existe.
    if (!$usuario) {
        throw new Exception("Perfil não encontrado.");
    }

} catch (Exception $e) {
    // Registra detalhes técnicos no log do servidor.
    error_log("Erro ao carregar perfil: " . $e->getMessage());

    $mensagem_erro = "Não foi possível carregar os dados do perfil.";
    $usuario = [];
    $erroConsulta = true;
}


// ============================================================
// 3. SALVAR ALTERAÇÕES DO PERFIL
// ============================================================

// Mantém o valor digitado no campo caso ocorra um erro.
$chave_pix_formulario = $usuario["chave_pix"] ?? "";

// Processa o formulário enviado.
if (
    !$erroConsulta &&
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["acao"] ?? "") === "salvar"
) {
    try {
        // Recupera os dados pessoais enviados.
        $nome = trim($_POST["nome"] ?? "");
        $telefone = trim($_POST["telefone"] ?? "");
        $especialidade = trim($_POST["especialidade"] ?? "");
        $chave_pix_formulario = trim($_POST["chave_pix"] ?? "");

        // Recupera os campos de alteração de senha.
        $senha_atual = $_POST["senha_atual"] ?? "";
        $nova_senha = $_POST["nova_senha"] ?? "";
        $confirma_senha = $_POST["confirma_senha"] ?? "";

        // Define as especialidades permitidas.
        $especialidadesPermitidas = [
            "lash",
            "nail",
            "eyebrow"
        ];

        // Valida os campos obrigatórios.
        if ($nome === "" || $especialidade === "") {
            throw new Exception(
                "Nome e especialidade são obrigatórios."
            );
        }

        // Impede o envio de uma especialidade inválida.
        if (!in_array($especialidade, $especialidadesPermitidas, true)) {
            throw new Exception("Selecione uma especialidade válida.");
        }

        // Verifica o tamanho máximo da chave Pix.
        if (strlen($chave_pix_formulario) > 255) {
            throw new Exception(
                "A chave Pix deve ter até 255 caracteres."
            );
        }

        // Prepara os dados básicos para atualização.
        $parametros = [
            ":nome" => $nome,
            ":tel" => $telefone,
            ":esp" => $especialidade,
            ":pix" => $chave_pix_formulario !== ""
                ? $chave_pix_formulario
                : null,
            ":id" => $profissional_id
        ];

        // Atualiza os dados pessoais e a chave Pix.
        $sqlAtualizacao = "
            UPDATE usuarios
            SET
                nome = :nome,
                telefone = :tel,
                especialidade = :esp,
                chave_pix = :pix
        ";

        // Altera a senha somente se uma nova foi informada.
        if ($nova_senha !== "") {

            // Exige a senha atual para autorizar a troca.
            if ($senha_atual === "") {
                throw new Exception(
                    "Informe sua senha atual para alterá-la."
                );
            }

            // Verifica o tamanho mínimo da nova senha.
            if (strlen($nova_senha) < 6) {
                throw new Exception(
                    "A nova senha deve ter pelo menos 6 caracteres."
                );
            }

            // Confere a nova senha com a confirmação.
            if ($nova_senha !== $confirma_senha) {
                throw new Exception(
                    "A confirmação da senha não confere."
                );
            }

            // Recupera a senha criptografada atualmente salva.
            $verifica = $conexao->prepare("
                SELECT senha
                FROM usuarios
                WHERE id = :id
                LIMIT 1
            ");

            $verifica->execute([
                ":id" => $profissional_id
            ]);

            $hash_atual = $verifica->fetchColumn();

            // Confere a senha atual antes de permitir a troca.
            if (
                !$hash_atual ||
                !password_verify($senha_atual, $hash_atual)
            ) {
                throw new Exception("Senha atual incorreta.");
            }

            // Criptografa a nova senha.
            $hash_nova = password_hash(
                $nova_senha,
                PASSWORD_DEFAULT
            );

            // Inclui a nova senha na atualização.
            $sqlAtualizacao .= ", senha = :senha";
            $parametros[":senha"] = $hash_nova;
        }

        // Limita a atualização à profissional autenticada.
        $sqlAtualizacao .= " WHERE id = :id";

        // Executa a atualização dos dados.
        $comando = $conexao->prepare($sqlAtualizacao);
        $comando->execute($parametros);

        // Atualiza os dados da sessão.
        $_SESSION["usuario_nome"] = $nome;
        $_SESSION["especialidade"] = $especialidade;

        // Informa que as alterações foram salvas.
        $mensagem_sucesso = "Perfil atualizado com sucesso!";

        // Recarrega os dados confirmados no banco.
        $consulta->execute([
            ":id" => $profissional_id
        ]);

        $usuario = $consulta->fetch(PDO::FETCH_ASSOC);

        // Sincroniza o campo com a chave salva.
        $chave_pix_formulario = $usuario["chave_pix"] ?? "";

    } catch (Exception $e) {
        // Registra detalhes técnicos para depuração.
        error_log("Erro ao salvar perfil: " . $e->getMessage());

        // Lista as mensagens de validação conhecidas.
        $mensagensPermitidas = [
            "Nome e especialidade são obrigatórios.",
            "Selecione uma especialidade válida.",
            "A chave Pix deve ter até 255 caracteres.",
            "Informe sua senha atual para alterá-la.",
            "A nova senha deve ter pelo menos 6 caracteres.",
            "A confirmação da senha não confere.",
            "Senha atual incorreta."
        ];

        // Exibe mensagens seguras para a profissional.
        if (in_array($e->getMessage(), $mensagensPermitidas, true)) {
            $mensagem_erro = $e->getMessage();
        } else {
            $mensagem_erro = "Não foi possível salvar as alterações.";
        }

        // Mantém os dados digitados nos campos.
        $usuario["nome"] = $nome ?? ($usuario["nome"] ?? "");
        $usuario["telefone"] = $telefone ?? ($usuario["telefone"] ?? "");
        $usuario["especialidade"] = $especialidade ?? ($usuario["especialidade"] ?? "");

        // Não altera usuario["chave_pix"] aqui:
        // esse valor representa a chave realmente salva no banco.
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">

    <!-- Adapta a página a diferentes tamanhos de tela. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Meu Perfil | Estética</title>

    <!-- Carrega o estilo principal do projeto. -->
    <link rel="stylesheet" href="style.css">
</head>

<body>

<!-- =========================================================
     4. MENU LATERAL
     ========================================================= -->

<aside class="sidebar">

    <div class="logo">
        <h1>Estética</h1>

        <!-- Exibe a especialidade da profissional. -->
        <p>
            <?= htmlspecialchars(
                $_SESSION["especialidade"] ?? "Profissional",
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </p>
    </div>

    <nav class="menu">
        <a href="painel_profissional.php">Início</a>
        <a href="atendimentos.php">Atendimentos</a>
        <a href="minha_agenda.php">Minha Agenda</a>
        <a href="meus_servicos.php">Meus Serviços</a>

        <div class="separador"></div>

        <!-- Destaca a página atual. -->
        <a href="meu_perfil.php" class="ativo">Meu Perfil</a>
    </nav>

    <!-- Permite encerrar a sessão. -->
    <a href="logout.php" class="btn-sair">Sair</a>

</aside>


<!-- =========================================================
     5. CONTEÚDO PRINCIPAL
     ========================================================= -->

<main class="main">

    <section class="topo">
        <div>
            <h2>Meu Perfil</h2>
            <p>
                Atualize seus dados, gerencie sua chave Pix
                e altere sua senha quando precisar.
            </p>
        </div>
    </section>

    <!-- Exibe a mensagem de sucesso. -->
    <?php if ($mensagem_sucesso !== ""): ?>
        <div class="alerta alerta-sucesso">
            <?= htmlspecialchars(
                $mensagem_sucesso,
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </div>
    <?php endif; ?>

    <!-- Exibe mensagens de erro. -->
    <?php if ($mensagem_erro !== ""): ?>
        <div class="alerta alerta-erro">
            <?= htmlspecialchars(
                $mensagem_erro,
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </div>
    <?php endif; ?>


    <?php if (!$erroConsulta): ?>

        <div class="perfil-container">

            <!-- Formulário de dados pessoais, Pix e senha. -->
            <form method="POST" action="meu_perfil.php">

                <input type="hidden" name="acao" value="salvar">

                <div class="form-grid">

                    <!-- Nome completo da profissional. -->
                    <div class="form-grupo">
                        <label for="nome">Nome Completo *</label>

                        <input
                            type="text"
                            id="nome"
                            name="nome"
                            required
                            maxlength="150"
                            value="<?= htmlspecialchars(
                                $usuario["nome"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                        >
                    </div>


                    <!-- E-mail somente para visualização. -->
                    <div class="form-grupo">
                        <label for="email">E-mail</label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            readonly
                            value="<?= htmlspecialchars(
                                $usuario["email"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                            style="background:var(--cor-clara); opacity:0.8;"
                        >

                        <small>E-mail não pode ser alterado.</small>
                    </div>


                    <!-- Telefone de contato. -->
                    <div class="form-grupo">
                        <label for="telefone">Telefone</label>

                        <input
                            type="tel"
                            id="telefone"
                            name="telefone"
                            maxlength="20"
                            value="<?= htmlspecialchars(
                                $usuario["telefone"] ?? "",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                            placeholder="(00) 00000-0000"
                        >
                    </div>


                    <!-- Especialidade profissional. -->
                    <div class="form-grupo">
                        <label for="especialidade">Especialidade *</label>

                        <select
                            id="especialidade"
                            name="especialidade"
                            required
                        >
                            <option value="">Selecione...</option>

                            <option
                                value="lash"
                                <?= ($usuario["especialidade"] ?? "") === "lash" ? "selected" : "" ?>
                            >
                                Cílios
                            </option>

                            <option
                                value="nail"
                                <?= ($usuario["especialidade"] ?? "") === "nail" ? "selected" : "" ?>
                            >
                                Unhas
                            </option>

                            <option
                                value="eyebrow"
                                <?= ($usuario["especialidade"] ?? "") === "eyebrow" ? "selected" : "" ?>
                            >
                                Sobrancelhas
                            </option>
                        </select>
                    </div>


                    <!-- Cadastro e confirmação da chave Pix. -->
                    <div class="form-grupo linha-completa">

                        <hr style="
                            border:none;
                            border-top:1px solid var(--cor-borda);
                            margin:10px 0;
                        ">

                        <h4 style="
                            color:var(--cor-primaria);
                            margin-bottom:10px;
                        ">
                            Dados para pagamento
                        </h4>

                        <label for="chave_pix">
                            Cadastrar ou alterar chave Pix
                        </label>

                        <!-- Campo para digitar a chave Pix. -->
                        <input
                            type="text"
                            id="chave_pix"
                            name="chave_pix"
                            maxlength="255"
                            autocomplete="off"
                            value="<?= htmlspecialchars(
                                $chave_pix_formulario,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                            placeholder="CPF, e-mail, telefone ou chave aleatória"
                        >

                        <small>
                            Informe a chave que será utilizada para receber
                            os sinais dos agendamentos.
                        </small>


                        <!-- Cartão com as chaves realmente cadastradas. -->
                        <div style="
                            margin-top:18px;
                            padding:16px;
                            background:var(--fundo-cliente, #F8F0F1);
                            border:1px solid var(--cor-borda, #EED9DE);
                            border-radius:10px;
                        ">

                            <h4 style="
                                color:var(--cor-primaria);
                                margin-bottom:12px;
                            ">
                                Chaves cadastradas
                            </h4>

                            <?php if (!empty($usuario["chave_pix"])): ?>

                                <!-- Mostra a chave confirmada no banco. -->
                                <p style="margin:0 0 6px;">
                                    <strong>Chave Pix cadastrada:</strong>
                                </p>

                                <p style="
                                    margin:0;
                                    overflow-wrap:anywhere;
                                    font-weight:600;
                                    color:var(--marrom-cafe, #5A3434);
                                ">
                                    <?= htmlspecialchars(
                                        $usuario["chave_pix"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>
                                </p>

                                <small style="display:block; margin-top:10px;">
                                    Esta é a chave atualmente salva no seu perfil.
                                </small>

                            <?php else: ?>

                                <!-- Informa que não existe chave salva. -->
                                <p style="margin:0;">
                                    Nenhuma chave Pix cadastrada.
                                </p>

                                <small style="display:block; margin-top:8px;">
                                    Preencha o campo acima e clique em
                                    Salvar Alterações para cadastrar sua chave.
                                </small>

                            <?php endif; ?>

                        </div>

                        <!-- Orienta como remover a chave cadastrada. -->
                        <small style="display:block; margin-top:10px;">
                            Para remover a chave cadastrada, apague o campo
                            acima e salve as alterações.
                        </small>

                    </div>


                    <!-- Separação visual da área de senha. -->
                    <div class="form-grupo linha-completa">

                        <hr style="
                            border:none;
                            border-top:1px solid var(--cor-borda);
                            margin:10px 0;
                        ">

                        <h4 style="
                            color:var(--cor-primaria);
                            margin-bottom:10px;
                        ">
                            Alterar Senha
                        </h4>

                        <small>
                            Deixe os campos de senha em branco
                            para manter a senha atual.
                        </small>
                    </div>


                    <!-- Senha atual para autorizar a troca. -->
                    <div class="form-grupo">
                        <label for="senha_atual">Senha Atual</label>

                        <input
                            type="password"
                            id="senha_atual"
                            name="senha_atual"
                            autocomplete="current-password"
                            placeholder="Digite sua senha atual"
                        >
                    </div>


                    <!-- Nova senha desejada. -->
                    <div class="form-grupo">
                        <label for="nova_senha">Nova Senha</label>

                        <input
                            type="password"
                            id="nova_senha"
                            name="nova_senha"
                            minlength="6"
                            autocomplete="new-password"
                            placeholder="Mínimo de 6 caracteres"
                        >
                    </div>


                    <!-- Confirmação da nova senha. -->
                    <div class="form-grupo">
                        <label for="confirma_senha">
                            Confirmar Nova Senha
                        </label>

                        <input
                            type="password"
                            id="confirma_senha"
                            name="confirma_senha"
                            minlength="6"
                            autocomplete="new-password"
                            placeholder="Repita a nova senha"
                        >
                    </div>

                </div>


                <!-- Botões de navegação e salvamento. -->
                <div style="
                    margin-top:25px;
                    display:flex;
                    gap:10px;
                    flex-wrap:wrap;
                ">

                    <a
                        href="painel_profissional.php"
                        class="btn"
                        style="background:#eee; color:#444;"
                    >
                        ← Voltar
                    </a>

                    <button
                        type="submit"
                        class="btn btn-primario"
                    >
                        Salvar Alterações
                    </button>

                </div>

            </form>

        </div>

    <?php endif; ?>

</main>

</body>
</html>
