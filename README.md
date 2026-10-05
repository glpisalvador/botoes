# Botões para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv3+** · Compatível com GLPI **11.0.0 a 12.x**

Plugin que agiliza o atendimento em **chamados, problemas e mudanças**. Ele reúne botões de ação rápida, barras de SLA, campos adicionais por cliente e algumas travas de processo.

## O que o plugin faz

### Botões de ação rápida
Na barra de ações da linha do tempo, para os perfis configurados:

| Botão | O que faz |
|---|---|
| **Aceitar** | Atribui o item a quem clicou e o coloca em atendimento, direto e sem confirmação. Na mudança, o status equivalente é "Aceita". |
| **Pendente** | Abre uma janela para escrever o motivo e coloca o item em pendente, pelo mesmo mecanismo do formulário nativo. |
| **Grupo observador** | Adiciona ou substitui os grupos observadores do item. |

Tudo é feito pelas classes nativas, então fica no histórico e dispara as notificações.

### Barras de SLA
- **Lista de chamados:** colunas mais claras (SLA de atendimento e de solução, requerente, técnico) e barras de progresso coloridas no prazo.
- **Formulário do chamado:** o bloco nativo de níveis de serviço é trocado por duas barras: tempo para atender e tempo para solucionar.

### Campos adicionais (Unidade, Setor, Telefone)
- Aparecem logo abaixo da **Entidade** nos formulários de chamado, problema e mudança.
- São alimentados pelos **Dados do Cliente** cadastrados por entidade:
  - **Unidade:** só as unidades cadastradas na entidade;
  - **Setor e Telefone:** a lista cadastrada, ou texto livre quando não há cadastro.
- Cada campo pode ser ativado e tornado obrigatório.
- Depois da abertura, os valores ficam **somente leitura**.
- Viram **colunas pesquisáveis** nas listas do GLPI.

### Aviso "Público ou privado?"
Ao enviar um acompanhamento novo, uma janela pergunta se ele é **público** ou **privado** e grava conforme a escolha. Só aparece para quem pode marcar acompanhamentos como privados.

### Aba Visualizadores
No chamado, mostra quem já abriu o item, a primeira e a última vez e quantas vezes.

### Status e atores somente leitura (opcional)
- Para os perfis que usam os botões, o **status** e os **atores** (requerente, observador, atribuído) de itens já abertos não mudam pelo formulário, pelo Kanban nem por ação em massa.
- Os botões do plugin, as soluções, os acompanhamentos, as aprovações, as regras e as ações automáticas continuam funcionando.
- Na tela, esses campos aparecem visivelmente travados.

## Configuração

Em *Configurar → Plugins → Botões*:
- perfis que veem e usam os botões, e quais botões ficam ativos;
- aviso público/privado e aba Visualizadores, cada um ligado ou desligado;
- status e atores somente leitura;
- campos adicionais (ativo, obrigatório) e o cadastro de **Dados do Cliente** por entidade: unidades, setores e telefones.

A desinstalação mantém as tabelas e a configuração.

---

## Download e instalação

1. Baixe o arquivo `botoes-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/botoes/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/botoes
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install botoes -u <usuário administrador>
   php bin/console plugin:activate botoes
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/botoes` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install botoes -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v3.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).