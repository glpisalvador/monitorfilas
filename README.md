# Monitor de Filas para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv3+** · Compatível com GLPI **11.0.0 a 12.x**

**Painel em tempo real** das filas dos grupos técnicos, ideal para uma TV na sala de suporte ou para o coordenador acompanhar o dia.

## O que o plugin faz

### Painel
- **Cards por grupo** com chamados, problemas e mudanças separados por **status**. São usados os status reais do GLPI, inclusive os personalizados.
- **Técnicos** de cada grupo e quantos itens cada um tem.
- **Faixas de SLA:** no prazo, crítico (percentual configurável) e vencido, tanto de atendimento quanto de solução.
- **Itens parados** há mais de X horas.
- **Rankings do dia** (ou do período configurado), carregados sob demanda.
- **Todos os números são clicáveis** e abrem a busca nativa do GLPI já filtrada pelo grupo e pelo status.

### Uso
- **Atualização automática** sem recarregar a página, pausada quando a aba fica oculta.
- **Filtro de grupos** que fica no endereço da página, então dá para salvar um link por equipe.
- Botão de **tela cheia**.
- Os grupos entram nas filas como **atribuído**, **observador** ou **ambos**.
- Sempre restrito às **entidades ativas** do usuário, com um cache curto para vários usuários olhando o mesmo painel.

## Configuração

Opções da página de configuração:
- **Acesso:** perfis e usuários que podem ver o painel;
- **Grupos** monitorados, **tipos** de item (chamado, problema, mudança) e o vínculo do grupo (atribuído, observador ou ambos);
- **Painel:**
  - intervalo de atualização;
  - percentual do SLA crítico;
  - horas para considerar um item parado e quantos itens parados exibir;
  - tamanho e período dos rankings.

O painel fica em **Ferramentas → Monitor de filas**.

---

## Download e instalação

1. Baixe o arquivo `monitorfilas-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/monitorfilas/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/monitorfilas
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install monitorfilas -u <usuário administrador>
   php bin/console plugin:activate monitorfilas
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/monitorfilas` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install monitorfilas -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v3.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).