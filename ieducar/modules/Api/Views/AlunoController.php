<?php
#error_reporting(E_ALL);
#ini_set("display_errors", 1);

/**
 * i-Educar - Sistema de gestão escolar
 *
 * Copyright (C) 2006  Prefeitura Municipal de Itajaí
 *     <ctima@itajai.sc.gov.br>
 *
 * Este programa é software livre; você pode redistribuí-lo e/ou modificá-lo
 * sob os termos da Licença Pública Geral GNU conforme publicada pela Free
 * Software Foundation; tanto a versão 2 da Licença, como (a seu critério)
 * qualquer versão posterior.
 *
 * Este programa é distribuí­do na expectativa de que seja útil, porém, SEM
 * NENHUMA GARANTIA; nem mesmo a garantia implí­cita de COMERCIABILIDADE OU
 * ADEQUAÇÃO A UMA FINALIDADE ESPECÍFICA. Consulte a Licença Pública Geral
 * do GNU para mais detalhes.
 *
 * Você deve ter recebido uma cópia da Licença Pública Geral do GNU junto
 * com este programa; se não, escreva para a Free Software Foundation, Inc., no
 * endereço 59 Temple Street, Suite 330, Boston, MA 02111-1307 USA.
 *
 * @author    Lucas D'Avila <lucasdavila@portabilis.com.br>
 * @category  i-Educar
 * @license   @@license@@
 * @package   Api
 * @subpackage  Modules
 * @since   Arquivo disponível desde a versão ?
 * @version   $Id$
 */

require_once 'include/pessoa/clsCadastroFisicaFoto.inc.php';
require_once 'image_check.php';
require_once 'include/pmieducar/clsPmieducarAluno.inc.php';
require_once 'include/pmieducar/clsPmieducarProjeto.inc.php';
require_once 'include/pmieducar/clsPmieducarAlunoHistoricoAlturaPeso.inc.php';
require_once 'include/modules/clsModulesFichaMedicaAluno.inc.php';
require_once 'include/modules/clsModulesMoradiaAluno.inc.php';
require_once 'include/pmieducar/clsPermissoes.inc.php';
require_once 'App/Model/MatriculaSituacao.php';
require_once 'Portabilis/Controller/ApiCoreController.php';
require_once 'Portabilis/Array/Utils.php';
require_once 'Portabilis/String/Utils.php';
require_once 'Portabilis/Array/Utils.php';
require_once 'Portabilis/Date/Utils.php';
require_once 'include/modules/clsModulesPessoaTransporte.inc.php';
require_once 'include/modules/clsModulesAuditoriaGeral.inc.php';
require_once 'Transporte/Model/Responsavel.php';

/**
 * Class AlunoController
 * @deprecated Essa versão da API pública será descontinuada
 */
class AlunoController extends ApiCoreController
{
    protected $_processoAp = 578;
    protected $_nivelAcessoOption = App_Model_NivelAcesso::SOMENTE_ESCOLA;

    // validators
    protected function validatesPessoaId()
    {
        $existenceOptions = array('schema_name' => 'cadastro', 'field_name' => 'idpes');

        return $this->validatesPresenceOf('pessoa_id') &&
            $this->validatesExistenceOf('fisica', $this->getRequest()->pessoa_id, $existenceOptions);
    }


    protected function validatesReligiaoId()
    {
        $isValid = true;

        // beneficio is optional
        if (is_numeric($this->getRequest()->religiao_id)) {
            $isValid = $this->validatesPresenceOf('religiao_id') &&
                $this->validatesExistenceOf('religiao', $this->getRequest()->religiao_id);
        }

        return $isValid;
    }


    protected function validatesBeneficioId()
    {
        // @TODO Alterar pois foi alterado relacionamento para N:N
        $isValid = true;

        // beneficio is optional
        if (is_numeric($this->getRequest()->beneficio_id)) {
            $isValid = $this->validatesPresenceOf('beneficio_id') &&
                $this->validatesExistenceOf('aluno_beneficio', $this->getRequest()->beneficio_id);
        }

        return $isValid;
    }


    protected function validatesResponsavelId()
    {
        $isValid = true;

        if ($this->getRequest()->tipo_responsavel == 'outra_pessoa') {
            $existenceOptions = array('schema_name' => 'cadastro', 'field_name' => 'idpes');

            $isValid = $this->validatesPresenceOf('responsavel_id') &&
                $this->validatesExistenceOf('fisica', $this->getRequest()->responsavel_id, $existenceOptions);
        }

        return $isValid;
    }


    protected function validatesResponsavelTipo()
    {
        $expectedValues = array('mae', 'pai', 'outra_pessoa', 'pai_mae');

        return $this->validatesPresenceOf('tipo_responsavel') &&
            $this->validator->validatesValueInSetOf($this->getRequest()->tipo_responsavel,
                $expectedValues, 'tipo_responsavel');
    }


    protected function validatesResponsavel()
    {
        return $this->validatesResponsavelTipo() &&
            $this->validatesResponsavelId();
    }


    protected function validatesTransporte()
    {
        $expectedValues = array('nenhum', 'municipal', 'estadual');

        return $this->validatesPresenceOf('tipo_transporte') &&
            $this->validator->validatesValueInSetOf($this->getRequest()->tipo_transporte,
                $expectedValues, 'tipo_transporte');
    }


    protected function validatesUniquenessOfAlunoByPessoaId()
    {
        $existenceOptions = array('schema_name' => 'pmieducar', 'field_name' => 'ref_idpes', 'add_msg_on_error' => false);

        if (!$this->validatesUniquenessOf('aluno', $this->getRequest()->pessoa_id, $existenceOptions)) {
            $this->messenger->append("Já existe um aluno cadastrado para a pessoa {$this->getRequest()->pessoa_id}.");
            return false;
        }

        return true;
    }


    protected function validatesUniquenessOfAlunoInepId()
    {
        if ($this->getRequest()->aluno_inep_id) {
            $sql = "SELECT a.cod_aluno FROM modules.educacenso_cod_aluno eca INNER JOIN pmieducar.aluno a ON (a.cod_aluno = eca.cod_aluno) WHERE eca.cod_aluno_inep = $1 AND a.ativo = 1 ";
            $params = array($this->getRequest()->aluno_inep_id);

            if ($this->getRequest()->id) {
                $sql .= ' AND a.cod_aluno != $2';
                $params[] = $this->getRequest()->id;
            }

            $alunoId = $this->fetchPreparedQuery($sql, $params, true, 'first-field');

            if ($alunoId) {
                $this->messenger->append("Já existe o aluno $alunoId cadastrado com código inep " .
                    " {$this->getRequest()->aluno_inep_id}.");

                return false;
            }
        }

        return true;
    }


    protected function validatesUniquenessOfAlunoEstadoId()
    {
        if ($this->getRequest()->aluno_estado_id) {
            $sql = "select cod_aluno from pmieducar.aluno where aluno_estado_id = $1 AND ativo = 1 ";
            $params = array($this->getRequest()->aluno_estado_id);

            if ($this->getRequest()->id) {
                $sql .= " and cod_aluno != $2";
                $params[] = $this->getRequest()->id;
            }

            $alunoId = $this->fetchPreparedQuery($sql, $params, true, 'first-field');

            $configuracoes = new clsPmieducarConfiguracoesGerais();
            $configuracoes = $configuracoes->detalhe();

            if (!empty($configuracoes["tamanho_min_rede_estadual"])) {
                $count = strlen($this->getRequest()->aluno_estado_id);
                if ($count < $configuracoes["tamanho_min_rede_estadual"]) {
                    $this->messenger->append("O Código rede estadual informado é inválido. {$this->getRequest()->aluno_estado_id}.");

                    return false;
                }
            }

            if ($alunoId) {
                $this->messenger->append("Já existe o aluno $alunoId cadastrado com código estadual (RA) {$this->getRequest()->aluno_estado_id}.");
                return false;
            }
        }

        return true;
    }

    // validations

    protected function canGetMatriculas()
    {
        return $this->validatesId('aluno');
    }

    protected function canGetTodosAlunos()
    {
        return $this->validatesPresenceOf('instituicao_id');
    }

    protected function canGetAlunosByGuardianCpf()
    {
        return $this->validatesPresenceOf('aluno_id') && $this->validatesPresenceOf('cpf');
    }

    protected function canChange()
    {
        return $this->validatesPessoaId() &&
            $this->validatesResponsavel() &&
            $this->validatesTransporte() &&
            $this->validatesReligiaoId() &&
            $this->validatesUniquenessOfAlunoInepId() &&
            $this->validatesUniquenessOfAlunoEstadoId();
    }

    protected function canPost()
    {
        return parent::canPost() &&
            $this->validatesUniquenessOfAlunoByPessoaId();
    }


    protected function canGetOcorrenciasDisciplinares()
    {
        return $this->validatesId('aluno');
    }

    // load resources
    protected function loadNomeAluno($alunoId)
    {
        $sql = "select nome from cadastro.pessoa, pmieducar.aluno where idpes = ref_idpes and cod_aluno = $1";
        $nome = $this->fetchPreparedQuery($sql, $alunoId, false, 'first-field');

        return $this->toUtf8($nome, array('transform' => true));
    }

    protected function validaTurnoProjeto($alunoId, $turnoId)
    {
        if ($GLOBALS['coreExt']['Config']->app->projetos->ignorar_turno_igual_matricula == 1) {
            return true;
        }

        $sql = '
              SELECT 1
              FROM pmieducar.turma t
              INNER JOIN pmieducar.matricula_turma mt ON (mt.ref_cod_turma = t.cod_turma)
              INNER JOIN pmieducar.matricula m ON (m.cod_matricula = mt.ref_cod_matricula)
              WHERE t.turma_turno_id = $2
              AND mt.ativo = 1
              AND m.ativo = 1
              AND m.aprovado = 3
              AND m.ref_cod_aluno = $1
        ';

        $turnoValido = !(bool)$this->fetchPreparedQuery($sql, array($alunoId, $turnoId), false, 'first-field');
        return $turnoValido;
    }

    protected function loadTransporte($alunoId)
    {

        $tiposTransporte = array(
            Transporte_Model_Responsavel::NENHUM => 'nenhum',
            Transporte_Model_Responsavel::MUNICIPAL => 'municipal',
            Transporte_Model_Responsavel::ESTADUAL => 'estadual'
        );

        $dataMapper = $this->getDataMapperFor('transporte', 'aluno');
        $entity = $this->tryGetEntityOf($dataMapper, $alunoId);

        //Alterado para retornar null quando não houver transporte, pois
        //na validação do censo este campo é obrigatório e não deve vir pré-populado
        if (is_null($entity)) {
            $tipo = $tiposTransporte[NULL];
        } else {
            $tipo = $tiposTransporte[$entity->get('responsavel')];
        }

        return $tipo;
    }

    protected function saveSus($pessoaId)
    {

        $sus = Portabilis_String_Utils::toLatin1($this->getRequest()->sus);

        $sql = 'update cadastro.fisica set sus = $1 where idpes = $2';
        return $this->fetchPreparedQuery($sql, array($sus, $pessoaId));
    }


    protected function createOrUpdateTransporte($alunoId)
    {
        $tiposTransporte = array(
            'nenhum' => Transporte_Model_Responsavel::NENHUM,
            'municipal' => Transporte_Model_Responsavel::MUNICIPAL,
            'estadual' => Transporte_Model_Responsavel::ESTADUAL
        );

        $data = array(
            'aluno' => $alunoId,
            'responsavel' => $tiposTransporte[$this->getRequest()->tipo_transporte],
            'user' => $this->getSession()->id_pessoa,
            'created_at' => 'NOW()',
        );

        $dataMapper = $this->getDataMapperFor('transporte', 'aluno');
        $entity = $this->getOrCreateEntityOf($dataMapper, $alunoId);
        $entity->setOptions($data);

        return $this->saveEntity($dataMapper, $entity);
    }

    protected function createOrUpdateFichaMedica($id)
    {

        $obj = new clsModulesFichaMedicaAluno();

        $obj->ref_cod_aluno = $id;
        $obj->altura = Portabilis_String_Utils::toLatin1($this->getRequest()->altura);
        $obj->peso = Portabilis_String_Utils::toLatin1($this->getRequest()->peso);
        $obj->grupo_sanguineo = Portabilis_String_Utils::toLatin1($this->getRequest()->grupo_sanguineo);
        $obj->grupo_sanguineo = trim($obj->grupo_sanguineo);
        $obj->fator_rh = Portabilis_String_Utils::toLatin1($this->getRequest()->fator_rh);
        $obj->alergia_medicamento = ($this->getRequest()->alergia_medicamento == 'on' ? 'S' : 'N');
        $obj->desc_alergia_medicamento = Portabilis_String_Utils::toLatin1($this->getRequest()->desc_alergia_medicamento);
        $obj->alergia_alimento = ($this->getRequest()->alergia_alimento == 'on' ? 'S' : 'N');
        $obj->desc_alergia_alimento = Portabilis_String_Utils::toLatin1($this->getRequest()->desc_alergia_alimento);
        $obj->doenca_congenita = ($this->getRequest()->doenca_congenita == 'on' ? 'S' : 'N');
        $obj->desc_doenca_congenita = Portabilis_String_Utils::toLatin1($this->getRequest()->desc_doenca_congenita);
        $obj->fumante = ($this->getRequest()->fumante == 'on' ? 'S' : 'N');
        $obj->doenca_caxumba = ($this->getRequest()->doenca_caxumba == 'on' ? 'S' : 'N');
        $obj->doenca_sarampo = ($this->getRequest()->doenca_sarampo == 'on' ? 'S' : 'N');
        $obj->doenca_rubeola = ($this->getRequest()->doenca_rubeola == 'on' ? 'S' : 'N');
        $obj->doenca_catapora = ($this->getRequest()->doenca_catapora == 'on' ? 'S' : 'N');
        $obj->doenca_escarlatina = ($this->getRequest()->doenca_escarlatina == 'on' ? 'S' : 'N');
        $obj->doenca_coqueluche = ($this->getRequest()->doenca_coqueluche == 'on' ? 'S' : 'N');
        $obj->doenca_outras = Portabilis_String_Utils::toLatin1($this->getRequest()->doenca_outras);
        $obj->epiletico = ($this->getRequest()->epiletico == 'on' ? 'S' : 'N');
        $obj->epiletico_tratamento = ($this->getRequest()->epiletico_tratamento == 'on' ? 'S' : 'N');
        $obj->hemofilico = ($this->getRequest()->hemofilico == 'on' ? 'S' : 'N');
        $obj->hipertenso = ($this->getRequest()->hipertenso == 'on' ? 'S' : 'N');
        $obj->asmatico = ($this->getRequest()->asmatico == 'on' ? 'S' : 'N');
        $obj->diabetico = ($this->getRequest()->diabetico == 'on' ? 'S' : 'N');
        $obj->insulina = ($this->getRequest()->insulina == 'on' ? 'S' : 'N');
        $obj->tratamento_medico = ($this->getRequest()->tratamento_medico == 'on' ? 'S' : 'N');
        $obj->desc_tratamento_medico = Portabilis_String_Utils::toLatin1($this->getRequest()->desc_tratamento_medico);
        $obj->medicacao_especifica = ($this->getRequest()->medicacao_especifica == 'on' ? 'S' : 'N');
        $obj->desc_medicacao_especifica = Portabilis_String_Utils::toLatin1($this->getRequest()->desc_medicacao_especifica);
        $obj->acomp_medico_psicologico = ($this->getRequest()->acomp_medico_psicologico == 'on' ? 'S' : 'N');
        $obj->desc_acomp_medico_psicologico = Portabilis_String_Utils::toLatin1($this->getRequest()->desc_acomp_medico_psicologico);
        $obj->acomp_medico_psicologico = ($this->getRequest()->acomp_medico_psicologico == 'on' ? 'S' : 'N');
        $obj->desc_acomp_medico_psicologico = Portabilis_String_Utils::toLatin1($this->getRequest()->desc_acomp_medico_psicologico);
        $obj->restricao_atividade_fisica = ($this->getRequest()->restricao_atividade_fisica == 'on' ? 'S' : 'N');
        $obj->desc_restricao_atividade_fisica = Portabilis_String_Utils::toLatin1($this->getRequest()->desc_restricao_atividade_fisica);
        $obj->fratura_trauma = ($this->getRequest()->fratura_trauma == 'on' ? 'S' : 'N');
        $obj->desc_fratura_trauma = Portabilis_String_Utils::toLatin1($this->getRequest()->desc_fratura_trauma);
        $obj->plano_saude = ($this->getRequest()->plano_saude == 'on' ? 'S' : 'N');
        $obj->desc_plano_saude = Portabilis_String_Utils::toLatin1($this->getRequest()->desc_plano_saude);
        $obj->hospital_clinica = Portabilis_String_Utils::toLatin1($this->getRequest()->hospital_clinica);
        $obj->hospital_clinica_endereco = Portabilis_String_Utils::toLatin1($this->getRequest()->hospital_clinica_endereco);
        $obj->hospital_clinica_telefone = Portabilis_String_Utils::toLatin1($this->getRequest()->hospital_clinica_telefone);
        $obj->responsavel = Portabilis_String_Utils::toLatin1($this->getRequest()->responsavel);
        $obj->responsavel_parentesco = Portabilis_String_Utils::toLatin1($this->getRequest()->responsavel_parentesco);
        $obj->responsavel_parentesco_telefone = Portabilis_String_Utils::toLatin1($this->getRequest()->responsavel_parentesco_telefone);
        $obj->responsavel_parentesco_celular = Portabilis_String_Utils::toLatin1($this->getRequest()->responsavel_parentesco_celular);

        return ($obj->existe() ? $obj->edita() : $obj->cadastra());
    }

    protected function createOrUpdateMoradia($id)
    {

        $obj = new clsModulesMoradiaAluno();

        $obj->ref_cod_aluno = $id;
        $obj->moradia = $this->getRequest()->moradia;
        $obj->material = $this->getRequest()->material;
        $obj->casa_outra = Portabilis_String_Utils::toLatin1($this->getRequest()->casa_outra);
        $obj->moradia_situacao = $this->getRequest()->moradia_situacao;
        $obj->quartos = $this->getRequest()->quartos;
        $obj->sala = $this->getRequest()->sala;
        $obj->copa = $this->getRequest()->copa;
        $obj->banheiro = $this->getRequest()->banheiro;
        $obj->garagem = $this->getRequest()->garagem;
        $obj->empregada_domestica = ($this->getRequest()->empregada_domestica == 'on' ? 'S' : 'N');
        $obj->automovel = ($this->getRequest()->automovel == 'on' ? 'S' : 'N');
        $obj->motocicleta = ($this->getRequest()->motocicleta == 'on' ? 'S' : 'N');
        $obj->computador = ($this->getRequest()->computador == 'on' ? 'S' : 'N');
        $obj->geladeira = ($this->getRequest()->geladeira == 'on' ? 'S' : 'N');
        $obj->fogao = ($this->getRequest()->fogao == 'on' ? 'S' : 'N');
        $obj->maquina_lavar = ($this->getRequest()->maquina_lavar == 'on' ? 'S' : 'N');
        $obj->microondas = ($this->getRequest()->microondas == 'on' ? 'S' : 'N');
        $obj->video_dvd = ($this->getRequest()->video_dvd == 'on' ? 'S' : 'N');
        $obj->televisao = ($this->getRequest()->televisao == 'on' ? 'S' : 'N');
        $obj->celular = ($this->getRequest()->celular == 'on' ? 'S' : 'N');
        $obj->telefone = ($this->getRequest()->telefone == 'on' ? 'S' : 'N');
        $obj->quant_pessoas = $this->getRequest()->quant_pessoas;
        $obj->renda = floatval(preg_replace("/[^0-9\.]/", "", str_replace(",", ".", $this->getRequest()->renda)));
        $obj->agua_encanada = ($this->getRequest()->agua_encanada == 'on' ? 'S' : 'N');
        $obj->poco = ($this->getRequest()->poco == 'on' ? 'S' : 'N');
        $obj->energia = ($this->getRequest()->energia == 'on' ? 'S' : 'N');
        $obj->esgoto = ($this->getRequest()->esgoto == 'on' ? 'S' : 'N');
        $obj->fossa = ($this->getRequest()->fossa == 'on' ? 'S' : 'N');
        $obj->lixo = ($this->getRequest()->lixo == 'on' ? 'S' : 'N');

        return ($obj->existe() ? $obj->edita() : $obj->cadastra());
    }

    protected function loadAlunoInepId($alunoId)
    {
        $dataMapper = $this->getDataMapperFor('educacenso', 'aluno');
        $entity = $this->tryGetEntityOf($dataMapper, $alunoId);

        return (is_null($entity) ? null : $entity->get('alunoInep'));
    }


    protected function createUpdateOrDestroyEducacensoAluno($alunoId)
    {
        $dataMapper = $this->getDataMapperFor('educacenso', 'aluno');

        $result = $this->deleteEntityOf($dataMapper, $alunoId);
        $data = array(
            'aluno' => $alunoId,
            'alunoInep' => $this->getRequest()->aluno_inep_id,
            'fonte' => 'fonte',
            'nomeInep' => '-',
            'created_at' => 'NOW()',
        );

        $entity = $this->getOrCreateEntityOf($dataMapper, $alunoId);
        $entity->setOptions($data);

        $result = $this->saveEntity($dataMapper, $entity);

        return $result;
    }


    // #TODO mover updateResponsavel e updateDeficiencias para API pessoa ?
    protected function updateResponsavel()
    {
        $pessoa = new clsFisica();
        $pessoa->idpes = $this->getRequest()->pessoa_id;
        $pessoa->nome_responsavel = '';

        $_pessoa = $pessoa->detalhe();

        if ($this->getRequest()->tipo_responsavel == 'outra_pessoa') {
            $pessoa->idpes_responsavel = $this->getRequest()->responsavel_id;
        } else if ($this->getRequest()->tipo_responsavel == 'pai' && $_pessoa['idpes_pai']) {
            $pessoa->idpes_responsavel = $_pessoa['idpes_pai'];
        } else if ($this->getRequest()->tipo_responsavel == 'mae' && $_pessoa['idpes_mae']) {
            $pessoa->idpes_responsavel = $_pessoa['idpes_mae'];
        } else {
            $pessoa->idpes_responsavel = 'NULL';
        }

        return $pessoa->edita();
    }


    protected function updateDeficiencias()
    {
        $sql = "delete from cadastro.fisica_deficiencia where ref_idpes = $1";
        $this->fetchPreparedQuery($sql, $this->getRequest()->pessoa_id, false);

        foreach ($this->getRequest()->deficiencias as $id) {
            if (!empty($id)) {
                $deficiencia = new clsCadastroFisicaDeficiencia($this->getRequest()->pessoa_id, $id);
                $deficiencia->cadastra();
            }
        }
    }


    protected function createOrUpdateAluno($id = null)
    {
        $tiposResponsavel = array('pai' => 'p', 'mae' => 'm', 'outra_pessoa' => 'r', 'pai_mae' => 'a');

        $aluno = new clsPmieducarAluno();
        $aluno->cod_aluno = $id;

        $alunoEstadoId = strtoupper(Portabilis_String_Utils::toLatin1($this->getRequest()->aluno_estado_id));
        $alunoEstadoId = str_replace(".", "", $alunoEstadoId);
        $alunoEstadoId = str_replace("-", "", $alunoEstadoId);
        $alunoEstadoId = preg_replace('"(.{3})(.{3})(.{3})(.{1})"', '\\1.\\2.\\3-\\4', $alunoEstadoId);
        $aluno->aluno_estado_id = $alunoEstadoId;

        $aluno->codigo_sistema = Portabilis_String_Utils::toLatin1($this->getRequest()->codigo_sistema);
        $aluno->autorizado_um = Portabilis_String_Utils::toLatin1($this->getRequest()->autorizado_um);
        $aluno->parentesco_um = Portabilis_String_Utils::toLatin1($this->getRequest()->parentesco_um);
        $aluno->autorizado_dois = Portabilis_String_Utils::toLatin1($this->getRequest()->autorizado_dois);
        $aluno->parentesco_dois = Portabilis_String_Utils::toLatin1($this->getRequest()->parentesco_dois);
        $aluno->autorizado_tres = Portabilis_String_Utils::toLatin1($this->getRequest()->autorizado_tres);
        $aluno->parentesco_tres = Portabilis_String_Utils::toLatin1($this->getRequest()->parentesco_tres);
        $aluno->autorizado_quatro = Portabilis_String_Utils::toLatin1($this->getRequest()->autorizado_quatro);
        $aluno->parentesco_quatro = Portabilis_String_Utils::toLatin1($this->getRequest()->parentesco_quatro);
        $aluno->autorizado_cinco = Portabilis_String_Utils::toLatin1($this->getRequest()->autorizado_cinco);
        $aluno->parentesco_cinco = Portabilis_String_Utils::toLatin1($this->getRequest()->parentesco_cinco);
        // após cadastro não muda mais id pessoa

        if (is_null($id)) {
            $aluno->ref_idpes = $this->getRequest()->pessoa_id;
        }

        if (!$GLOBALS['coreExt']['Config']->app->alunos->nao_apresentar_campo_alfabetizado) {
            $aluno->analfabeto = $this->getRequest()->alfabetizado ? 0 : 1;
        }

        $aluno->tipo_responsavel = $tiposResponsavel[$this->getRequest()->tipo_responsavel];
        $aluno->ref_usuario_exc = $this->getSession()->id_pessoa;

        // INFORAMÇÕES PROVA INEP
        $recursosProvaInep = array_filter($this->getRequest()->recursos_prova_inep__);
        $recursosProvaInep = '{' . implode(',', $recursosProvaInep) . '}';
        $aluno->recursos_prova_inep = $recursosProvaInep;
        $aluno->recebe_escolarizacao_em_outro_espaco = $this->getRequest()->recebe_escolarizacao_em_outro_espaco;
        $aluno->justificativa_falta_documentacao = $this->getRequest()->justificativa_falta_documentacao;
        $aluno->veiculo_transporte_escolar = $this->getRequest()->veiculo_transporte_escolar;

        $this->file_foto = $_FILES["file"];
        $this->del_foto = $_POST["file_delete"];

        if (!$this->validatePhoto()) {
            $this->mensagem = "Foto inválida";
            return false;
        }

        $pessoaId = $this->getRequest()->pessoa_id;
        $this->savePhoto($pessoaId);

        //documentos
        $aluno->url_documento = Portabilis_String_Utils::toLatin1($this->getRequest()->url_documento);

        //laudo medico
        $aluno->url_laudo_medico = Portabilis_String_Utils::toLatin1($this->getRequest()->url_laudo_medico);


        if (is_null($id)) {
            $id = $aluno->cadastra();
            $aluno->cod_aluno = $id;
            $auditoria = new clsModulesAuditoriaGeral("aluno", $this->getSession()->id_pessoa, $id);
            $auditoria->inclusao($aluno->detalhe());
        } else {
            $detalheAntigo = $aluno->detalhe();
            $id = $aluno->edita();
            $auditoria = new clsModulesAuditoriaGeral("aluno", $this->getSession()->id_pessoa, $id);
            $auditoria->alteracao($detalheAntigo, $aluno->detalhe());
        }

        return $id;
    }

    protected function loadTurmaByMatriculaId($matriculaId)
    {
        $sql = '
            select ref_cod_turma as id, turma.nm_turma as nome, turma.tipo_boletim from pmieducar.matricula_turma,
            pmieducar.turma where ref_cod_matricula = $1 and matricula_turma.ativo = 1 and
            turma.cod_turma = ref_cod_turma limit 1
        ';

        $turma = Portabilis_Utils_Database::selectRow($sql, $matriculaId);
        $turma['nome'] = $this->toUtf8($turma['nome'], array('transform' => true));

        return $turma;
    }

    protected function loadEscolaNome($id)
    {
        $escola = new clsPmieducarEscola();
        $escola->cod_escola = $id;
        $escola = $escola->detalhe();

        return $this->toUtf8($escola['nome'], array('transform' => true));
    }

    protected function loadCursoNome($id)
    {
        $curso = new clsPmieducarCurso();
        $curso->cod_curso = $id;
        $curso = $curso->detalhe();

        return $this->toUtf8($curso['nm_curso'], array('transform' => true));
    }

    protected function loadSerieNome($id)
    {
        $serie = new clsPmieducarSerie();
        $serie->cod_serie = $id;
        $serie = $serie->detalhe();

        return $this->toUtf8($serie['nm_serie'], array('transform' => true));
    }

    protected function loadTransferenciaDataEntrada($matriculaId)
    {
        /*
        $sql = "select to_char(data_transferencia, 'DD/MM/YYYY') from
                pmieducar.transferencia_solicitacao where ref_cod_matricula_entrada = $1 and ativo = 1";*/
        $sql = "
            select COALESCE(to_char(data_matricula,'DD/MM/YYYY'), to_char(data_cadastro, 'DD/MM/YYYY')) from pmieducar.matricula
            where cod_matricula=$1 and ativo = 1
        ";

        return Portabilis_Utils_Database::selectField($sql, $matriculaId);
    }

    protected function loadNomeTurmaOrigem($matriculaId)
    {
        $sql = "SELECT nm_turma
              FROM pmieducar.matricula_turma mt
              LEFT JOIN pmieducar.turma t ON (t.cod_turma = mt.ref_cod_turma)
             WHERE ref_cod_matricula = $1
               AND mt.ativo = 0
               AND mt.ref_cod_turma <> COALESCE((SELECT ref_cod_turma
                                                   FROM pmieducar.matricula_turma
                                                  WHERE ref_cod_matricula = $1
                                                    AND ativo = 1
                                                  LIMIT 1), 0)
             ORDER BY mt.data_exclusao DESC
             LIMIT 1";

        return $this->toUtf8(Portabilis_Utils_Database::selectField($sql, $matriculaId), array('transform' => true));
    }

    protected function loadTransferenciaDataSaida($matriculaId)
    {
        $sql = "
            select COALESCE(to_char(data_cancel,'DD/MM/YYYY'), to_char(data_exclusao, 'DD/MM/YYYY')) from pmieducar.matricula
            where cod_matricula=$1 and ativo = 1 and ((aprovado=4 or aprovado=6) OR data_cancel is not null)
        ";

        return Portabilis_Utils_Database::selectField($sql, $matriculaId);
    }

    protected function possuiTransferenciaEmAberto($matriculaId)
    {
        $sql = "
            select count(cod_transferencia_solicitacao) from pmieducar.transferencia_solicitacao where
            ativo = 1 and ref_cod_matricula_saida = $1 and ref_cod_matricula_entrada is null and
            data_transferencia is null
        ";

        return (Portabilis_Utils_Database::selectField($sql, $matriculaId) > 0);
    }

    protected function loadTipoOcorrenciaDisciplinar($id)
    {
        if (!isset($this->_tiposOcorrenciasDisciplinares)) {
            $this->_tiposOcorrenciasDisciplinares = array();
        }

        if (!isset($this->_tiposOcorrenciasDisciplinares[$id])) {
            $ocorrencia = new clsPmieducarTipoOcorrenciaDisciplinar;
            $ocorrencia->cod_tipo_ocorrencia_disciplinar = $id;
            $ocorrencia = $ocorrencia->detalhe();

            $this->_tiposOcorrenciasDisciplinares[$id] = $this->toUtf8(
                $ocorrencia['nm_tipo'],
                array('transform' => true)
            );

        }

        return $this->_tiposOcorrenciasDisciplinares[$id];
    }


    protected function loadOcorrenciasDisciplinares()
    {
        $ocorrenciasAluno = array();

        $alunoId = $this->getRequest()->aluno_id;

        if (is_array($alunoId))
            $alunoId = implode(",", $alunoId);


        if (is_numeric($this->getRequest()->escola_id)) {
            $sql = "
                SELECT cod_matricula as matricula_id, ref_cod_aluno as aluno_id from pmieducar.matricula, pmieducar.escola where
                cod_escola = ref_ref_cod_escola and ref_cod_aluno in ({$alunoId}) and ref_ref_cod_escola =
                $1 and matricula.ativo = 1 order by ano desc, matricula_id
            ";

            $params = array($this->getRequest()->escola_id);
        } else {
            $sql = "
                SELECT cod_matricula as matricula_id,
                ref_cod_aluno as aluno_id,
                ref_ref_cod_escola as escola_id
                FROM pmieducar.matricula
                WHERE ref_cod_aluno IN ({$alunoId})
                AND matricula.ativo = 1
                ORDER BY ano DESC, matricula_id
            ";

            $params = array();
        }

        $matriculas = $this->fetchPreparedQuery($sql, $params);

        $_ocorrenciasMatricula = new clsPmieducarMatriculaOcorrenciaDisciplinar();

        foreach ($matriculas as $matricula) {
            $ocorrenciasMatricula = $_ocorrenciasMatricula->lista($matricula['matricula_id'],
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                1,
                $visivel_pais = 1);

            if (is_array($ocorrenciasMatricula)) {
                $attrsFilter = array(
                    'ref_cod_tipo_ocorrencia_disciplinar' => 'tipo',
                    'data_cadastro' => 'data_hora',
                    'observacao' => 'descricao',
                    'cod_ocorrencia_disciplinar' => 'ocorrencia_disciplinar_id'
                );

                $ocorrenciasMatricula = Portabilis_Array_Utils::filterSet($ocorrenciasMatricula, $attrsFilter);

                foreach ($ocorrenciasMatricula as $ocorrenciaMatricula) {
                    $ocorrenciaMatricula['tipo'] = $this->loadTipoOcorrenciaDisciplinar($ocorrenciaMatricula['tipo']);
                    $ocorrenciaMatricula['data_hora'] = Portabilis_Date_Utils::pgSQLToBr($ocorrenciaMatricula['data_hora']);
                    $ocorrenciaMatricula['descricao'] = $this->toUtf8($ocorrenciaMatricula['descricao']);
                    $ocorrenciaMatricula['aluno_id'] = $matricula['aluno_id'];
                    $ocorrenciaMatricula['escola_id'] = $matricula['escola_id'];
                    $ocorrenciasAluno[] = $ocorrenciaMatricula;
                }
            }
        }

        return array('ocorrencias_disciplinares' => $ocorrenciasAluno);
    }

    protected function getGradeUltimoHistorico()
    {

        $sql = '
            SELECT historico_grade_curso_id as id
            FROM historico_escolar
            WHERE ref_cod_aluno = $1
            ORDER BY sequencial DESC LIMIT 1
        ';

        $params = array($this->getRequest()->aluno_id);
        $grade_curso = $this->fetchPreparedQuery($sql, $params, false, 'first-row');

        return array('grade_curso' => $grade_curso['id']);
    }

    // search options
    protected function searchOptions()
    {
        $escolaId = $this->getRequest()->escola_id ? $this->getRequest()->escola_id : 0;

        return array(
            'sqlParams' => array($escolaId),
            'selectFields' => array('matricula_id')
        );
    }

    protected function sqlsForNumericSearch()
    {
        $sqls = array();

        // caso nao receba id da escola, pesquisa por codigo aluno em todas as escolas,
        // alunos com e sem matricula são selecionados.
        if (!$this->getRequest()->escola_id) {
            $sqls[] = "
                select distinct aluno.cod_aluno as id, pessoa.nome as name from
                pmieducar.aluno, cadastro.pessoa where pessoa.idpes = aluno.ref_idpes
                and aluno.ativo = 1 and aluno.cod_aluno::varchar(255) like $1||'%' and $2 = $2
                order by cod_aluno limit 15
            ";
        }

        $sqls[] = "
            select * from
            (select distinct ON (aluno.cod_aluno) aluno.cod_aluno as id, matricula.cod_matricula as matricula_id, pessoa.nome as name
            from pmieducar.matricula, pmieducar.aluno, cadastro.pessoa
            where aluno.cod_aluno = matricula.ref_cod_aluno
            and pessoa.idpes = aluno.ref_idpes
            and aluno.ativo = matricula.ativo
            and matricula.ativo = 1
            and (select case when $2 != 0 then matricula.ref_ref_cod_escola = $2 else 1=1 end)
            and (matricula.ref_cod_aluno::varchar(255) like $1||'%')
            and matricula.aprovado in (1, 2, 3, 4, 7, 8, 9) limit 15) as alunos
            order by id
        ";

        return $sqls;
    }


    protected function sqlsForStringSearch()
    {
        $sqls = array();

        // caso nao receba id da escola, pesquisa por nome aluno em todas as escolas,
        // alunos com e sem matricula são selecionados.
        if (!$this->getRequest()->escola_id) {
            $sqls[] = "
                select distinct aluno.cod_aluno as id,
                pessoa.nome as name from pmieducar.aluno, cadastro.pessoa where
                pessoa.idpes = aluno.ref_idpes and aluno.ativo = 1 and
                lower((pessoa.nome)) like '%'||lower(($1))||'%' and $2 = $2
                order by nome limit 15
            ";
        }

        // seleciona por nome aluno e e opcionalmente  por codigo escola,
        // apenas alunos com matricula são selecionados.
        $sqls[] = "
            select * from(select distinct ON (aluno.cod_aluno) aluno.cod_aluno as id,
            matricula.cod_matricula as matricula_id, pessoa.nome as name from pmieducar.matricula,
            pmieducar.aluno, cadastro.pessoa where aluno.cod_aluno = matricula.ref_cod_aluno and
            pessoa.idpes = aluno.ref_idpes and aluno.ativo = matricula.ativo and
            matricula.ativo = 1 and (select case when $2 != 0 then matricula.ref_ref_cod_escola = $2
            else 1=1 end) and
            translate(upper(nome),'ÅÁÀÃÂÄÉÈÊËÍÌÎÏÓÒÕÔÖÚÙÛÜÇÝÑ','AAAAAAEEEEIIIIOOOOOUUUUCYN') like translate(upper('%'|| $1 ||'%'),'ÅÁÀÃÂÄÉÈÊËÍÌÎÏÓÒÕÔÖÚÙÛÜÇÝÑ','AAAAAAEEEEIIIIOOOOOUUUUCYN') and matricula.aprovado in
            (1, 2, 3, 4, 7, 8, 9) limit 15) as alunos order by name
        ";

        return $sqls;
    }

    // api
    protected function tipoResponsavel($aluno)
    {
        $tipos = array('p' => 'pai', 'm' => 'mae', 'r' => 'outra_pessoa', 'a' => 'pai_mae');
        $tipo = $tipos[$aluno['tipo_responsavel']];

        // no antigo cadastro de aluno, caso não fosse encontrado um tipo de responsavel
        // verificava se a pessoa possua responsavel, pai ou mãe, considerando como
        // responsavel um destes, na respectiva ordem, sendo assim esta api mantem
        // compatibilidade com o antigo cadastro.
        if (!$tipo) {
            $pessoa = new clsFisica();
            $pessoa->idpes = $aluno['pessoa_id'];
            $pessoa = $pessoa->detalhe();

            if ($pessoa['idpes_responsavel'] || $pessoa['nome_responsavel']) {
                $tipo = $tipos['r'];
            } else if ($pessoa['idpes_pai'] || $pessoa['nome_pai']) {
                $tipo = $tipos['p'];
            } else if ($pessoa['idpes_mae'] || $pessoa['nome_mae']) {
                $tipo = $tipos['m'];
            }
        }

        return $tipo;
    }

    protected function loadBeneficios($alunoId)
    {
        $sql = "
            select aluno_beneficio_id as id, nm_beneficio as nome from pmieducar.aluno_aluno_beneficio,
            pmieducar.aluno_beneficio where aluno_beneficio_id = cod_aluno_beneficio and aluno_id = $1
        ";

        $beneficios = $this->fetchPreparedQuery($sql, $alunoId, false);

        // transforma array de arrays em array chave valor
        $_beneficios = array();

        foreach ($beneficios as $beneficio) {
            $nome = $this->toUtf8($beneficio['nome'], array('transform' => true));
            $_beneficios[$beneficio['id']] = $nome;
        }

        return $_beneficios;
    }

    protected function loadProjetos($alunoId)
    {
        $sql = "
            SELECT cod_projeto, nome, data_inclusao, data_desligamento, turno
            FROM  pmieducar.projeto_aluno, pmieducar.projeto
            WHERE ref_cod_projeto = cod_projeto
            AND ref_cod_aluno = $1
        ";

        $projetos = $this->fetchPreparedQuery($sql, $alunoId, false);

        // transforma array de arrays em array chave valor
        $_projetos = array();
        foreach ($projetos as $projeto) {
            $nome = $this->toUtf8($projeto['nome'], array('transform' => true));
            $_projetos[] = array(
                'projeto_cod_projeto' => $projeto['cod_projeto'] . ' - ' . $nome,
                'projeto_data_inclusao' => Portabilis_Date_Utils::pgSQLToBr($projeto['data_inclusao']),
                'projeto_data_desligamento' => Portabilis_Date_Utils::pgSQLToBr($projeto['data_desligamento']),
                'projeto_turno' => $projeto['turno']
            );
        }

        return $_projetos;
    }

    protected function loadHistoricoAlturaPeso($alunoId)
    {
        $sql = "
            SELECT to_char(data_historico, 'dd/mm/yyyy') AS data_historico, altura, peso
            FROM  pmieducar.aluno_historico_altura_peso
            WHERE ref_cod_aluno = $1
        ";

        $historicoAlturaPeso = $this->fetchPreparedQuery($sql, $alunoId, false);

        // transforma array de arrays em array chave valor
        $_historicoAlturaPeso = array();
        foreach ($historicoAlturaPeso as $alturaPeso) {
            $_historicoAlturaPeso[] = array(
                'data_historico' => $alturaPeso['data_historico'],
                'altura' => $alturaPeso['altura'],
                'peso' => $alturaPeso['peso']
            );
        }

        return $_historicoAlturaPeso;
    }

    protected function get()
    {
        if ($this->canGet()) {
            $id = $this->getRequest()->id;

            $aluno = new clsPmieducarAluno();
            $aluno->cod_aluno = $id;
            $aluno = $aluno->detalhe();

            $attrs = array(
                'cod_aluno' => 'id',
                'ref_cod_aluno_beneficio' => 'beneficio_id',
                'ref_idpes' => 'pessoa_id',
                'tipo_responsavel' => 'tipo_responsavel',
                'ref_usuario_exc' => 'destroyed_by',
                'data_exclusao' => 'destroyed_at',
                'analfabeto',
                'ativo',
                'aluno_estado_id',
                'recursos_prova_inep',
                'recebe_escolarizacao_em_outro_espaco',
                'justificativa_falta_documentacao',
                'veiculo_transporte_escolar',
                'url_laudo_medico',
                'url_documento',
                'codigo_sistema',
                'url_foto_aluno',
                'autorizado_um',
                'parentesco_um',
                'autorizado_dois',
                'parentesco_dois',
                'autorizado_tres',
                'parentesco_tres',
                'autorizado_quatro',
                'parentesco_quatro',
                'autorizado_cinco',
                'parentesco_cinco'
            );

            $aluno = Portabilis_Array_Utils::filter($aluno, $attrs);

            $aluno['nome'] = $this->loadNomeAluno($id);
            $aluno['tipo_transporte'] = $this->loadTransporte($id);
            $aluno['tipo_responsavel'] = $this->tipoResponsavel($aluno);
            $aluno['aluno_inep_id'] = $this->loadAlunoInepId($id);
            $aluno['ativo'] = $aluno['ativo'] == 1;
            $aluno['aluno_estado_id'] = Portabilis_String_Utils::toUtf8($aluno['aluno_estado_id']);
            $aluno['codigo_sistema'] = Portabilis_String_Utils::toUtf8($aluno['codigo_sistema']);
            $aluno['autorizado_um'] = Portabilis_String_Utils::toUtf8($aluno['autorizado_um']);
            $aluno['parentesco_um'] = Portabilis_String_Utils::toUtf8($aluno['parentesco_um']);
            $aluno['autorizado_dois'] = Portabilis_String_Utils::toUtf8($aluno['autorizado_dois']);
            $aluno['parentesco_dois'] = Portabilis_String_Utils::toUtf8($aluno['parentesco_dois']);
            $aluno['autorizado_tres'] = Portabilis_String_Utils::toUtf8($aluno['autorizado_tres']);
            $aluno['parentesco_tres'] = Portabilis_String_Utils::toUtf8($aluno['parentesco_tres']);
            $aluno['autorizado_quatro'] = Portabilis_String_Utils::toUtf8($aluno['autorizado_quatro']);
            $aluno['parentesco_quatro'] = Portabilis_String_Utils::toUtf8($aluno['parentesco_quatro']);
            $aluno['autorizado_cinco'] = Portabilis_String_Utils::toUtf8($aluno['autorizado_cinco']);
            $aluno['parentesco_cinco'] = Portabilis_String_Utils::toUtf8($aluno['parentesco_cinco']);


            $aluno['alfabetizado'] = $aluno['analfabeto'] == 0;
            unset($aluno['analfabeto']);

            // destroyed_by username
            $dataMapper = $this->getDataMapperFor('usuario', 'funcionario');
            $entity = $this->tryGetEntityOf($dataMapper, $aluno['destroyed_by']);

            $aluno['destroyed_by'] = is_null($entity) ? null : $entity->get('matricula');
            $aluno['destroyed_at'] = Portabilis_Date_Utils::pgSQLToBr($aluno['destroyed_at']);

            $objFichaMedica = new clsModulesFichaMedicaAluno($id);

            if ($objFichaMedica->existe()) {
                $objFichaMedica = $objFichaMedica->detalhe();

                foreach ($objFichaMedica as $chave => $value) {
                    $objFichaMedica[$chave] = Portabilis_String_Utils::toUtf8($value);
                }

                $aluno = Portabilis_Array_Utils::merge($objFichaMedica, $aluno);
            }

            $objMoradia = new clsModulesMoradiaAluno($id);
            if ($objMoradia->existe()) {
                $objMoradia = $objMoradia->detalhe();

                foreach ($objMoradia as $chave => $value) {
                    $objMoradia[$chave] = Portabilis_String_Utils::toUtf8($value);
                }

                $aluno = Portabilis_Array_Utils::merge($objMoradia, $aluno);
            }

            $objPessoaTransporte = new clsModulesPessoaTransporte(NULL, NULL, $aluno['pessoa_id']);
            $objPessoaTransporte = $objPessoaTransporte->detalhe();

            if ($objPessoaTransporte) {
                foreach ($objPessoaTransporte as $chave => $value) {
                    $objPessoaTransporte[$chave] = Portabilis_String_Utils::toUtf8($value);
                }

                $aluno = Portabilis_Array_Utils::merge($objPessoaTransporte, $aluno);
            }

            $sql = "select sus, ref_cod_religiao from cadastro.fisica where idpes = $1";
            $camposFisica = $this->fetchPreparedQuery($sql, $aluno['pessoa_id'], false, 'first-row');

            $aluno['sus'] = $camposFisica['sus'];
            $aluno['religiao_id'] = $camposFisica['ref_cod_religiao'];
            $aluno['beneficios'] = $this->loadBeneficios($id);
            $aluno['projetos'] = $this->loadProjetos($id);
            $aluno['historico_altura_peso'] = $this->loadHistoricoAlturaPeso($id);

            return $aluno;
        }
    }

    protected function getTodosAlunos()
    {
        if ($this->canGetTodosAlunos()) {
            $sql = "
                SELECT a.cod_aluno AS aluno_id,
                p.nome as nome_aluno,
                f.data_nasc as data_nascimento,
                ff.caminho as foto_aluno,
                EXISTS(SELECT 1
                        FROM cadastro.fisica_deficiencia fd
                        JOIN cadastro.deficiencia d
                        ON d.cod_deficiencia = fd.ref_cod_deficiencia
                        WHERE fd.ref_idpes = p.idpes
                        AND d.nm_deficiencia NOT ILIKE 'nenhuma') as utiliza_regra_diferenciada
                FROM pmieducar.aluno a
                INNER JOIN cadastro.pessoa p ON p.idpes = a.ref_idpes
                INNER JOIN cadastro.fisica f ON f.idpes = p.idpes
                LEFT JOIN cadastro.fisica_foto ff ON p.idpes = ff.idpes
                WHERE a.ativo = 1
                ORDER BY nome_aluno ASC
            ";

            $alunos = $this->fetchPreparedQuery($sql);

            $attrs = array('aluno_id', 'nome_aluno', 'foto_aluno', 'data_nascimento', 'utiliza_regra_diferenciada');
            $alunos = Portabilis_Array_Utils::filterSet($alunos, $attrs);
            foreach ($alunos as &$aluno) {
                $aluno['utiliza_regra_diferenciada'] = dbBool($aluno['utiliza_regra_diferenciada']);
            }

            return array('alunos' => $alunos);
        }
    }

    protected function getIdpesFromCpf($cpf)
    {

        $sql = "SELECT idpes FROM cadastro.fisica WHERE cpf = $1";
        return $this->fetchPreparedQuery($sql, $cpf, true, 'first-field');
    }

    protected function checkAlunoIdpesGuardian($idpesGuardian, $alunoId)
    {
        $sql = "
            SELECT 1
            FROM pmieducar.aluno
            INNER JOIN cadastro.fisica ON (aluno.ref_idpes = fisica.idpes)
            WHERE cod_aluno = $2
            AND (idpes_pai = $1
            OR idpes_mae = $1
            OR idpes_responsavel = $1) LIMIT 1
        ";

        return $this->fetchPreparedQuery($sql, array($idpesGuardian, $alunoId), true, 'first-field') == 1;
    }

    protected function getAlunosByGuardianCpf()
    {
        if ($this->canGetAlunosByGuardianCpf()) {
            $cpf = $this->getRequest()->cpf;
            $alunoId = $this->getRequest()->aluno_id;

            $idpesGuardian = $this->getIdpesFromCpf($cpf);

            if (is_numeric($idpesGuardian) && $this->checkAlunoIdpesGuardian($idpesGuardian, $alunoId)) {
                $sql = "
                    SELECT cod_aluno as aluno_id, pessoa.nome as nome_aluno
                    FROM pmieducar.aluno
                    INNER JOIN cadastro.fisica ON (aluno.ref_idpes = fisica.idpes)
                    INNER JOIN cadastro.pessoa ON (pessoa.idpes = fisica.idpes)
                    WHERE idpes_pai = $1
                    OR idpes_mae = $1
                    OR idpes_responsavel = $1
                ";

                $alunos = $this->fetchPreparedQuery($sql, array($idpesGuardian));

                $attrs = array('aluno_id', 'nome_aluno');
                $alunos = Portabilis_Array_Utils::filterSet($alunos, $attrs);

                foreach ($alunos as &$aluno) {
                    $aluno['nome_aluno'] = Portabilis_String_Utils::toUtf8($aluno['nome_aluno']);
                }

                return array('alunos' => $alunos);
            } else {
                $this->messenger->append('Não foi encontrado nenhum vínculos entre esse aluno e cpf.');
            }
        }
    }

    protected function getMatriculas()
    {
        if ($this->canGetMatriculas()) {
            $matriculas = new clsPmieducarMatricula();
            $matriculas->setOrderby('ano DESC, coalesce(m.data_matricula, m.data_cadastro) DESC, (CASE WHEN dependencia THEN 1 ELSE 0 END), ref_ref_cod_serie DESC, cod_matricula DESC, aprovado');

            $only_valid_boletim = $this->getRequest()->only_valid_boletim;

            $matriculas = $matriculas->lista(
                null,
                null,
                null,
                null,
                null,
                null,
                $this->getRequest()->aluno_id,
                null,
                null,
                null,
                null,
                null,
                1
            );

            $attrs = array(
                'cod_matricula' => 'id',
                'ref_cod_instituicao' => 'instituicao_id',
                'ref_ref_cod_escola' => 'escola_id',
                'ref_cod_curso' => 'curso_id',
                'ref_ref_cod_serie' => 'serie_id',
                'ref_cod_aluno' => 'aluno_id',
                'nome' => 'aluno_nome',
                'aprovado' => 'situacao',
                'ano',
                'dependencia'
            );

            $matriculas = Portabilis_Array_Utils::filterSet($matriculas, $attrs);

            foreach ($matriculas as $index => $matricula) {
                $turma = $this->loadTurmaByMatriculaId($matricula['id']);

                if (dbBool($only_valid_boletim) && (is_null($turma['id']) || is_null($turma['tipo_boletim']))) {
                    unset($matriculas[$index]);
                    continue;
                }

                $matriculas[$index]['aluno_nome'] = $this->toUtf8($matricula['aluno_nome'], array('transform' => true));
                $matriculas[$index]['turma_id'] = $turma['id'];
                $matriculas[$index]['turma_nome'] = $turma['nome'];
                $matriculas[$index]['escola_nome'] = $this->loadEscolaNome($matricula['escola_id']);
                $matriculas[$index]['curso_nome'] = $this->loadCursoNome($matricula['curso_id']);
                $matriculas[$index]['serie_nome'] = $this->loadSerieNome($matricula['serie_id']);
                $matriculas[$index]['ultima_enturmacao'] = $this->loadNomeTurmaOrigem($matricula['id']);
                $matriculas[$index]['data_entrada'] = $this->loadTransferenciaDataEntrada($matricula['id']);
                $matriculas[$index]['data_saida'] = $this->loadTransferenciaDataSaida($matricula['id']);

                $matriculas[$index]['situacao'] = App_Model_MatriculaSituacao::getInstance()->getValue(
                    $matricula['situacao']
                );

                $matriculas[$index]['codigo_situacao'] = $matricula['situacao'];
                $matriculas[$index]['user_can_access'] = Portabilis_Utils_User::canAccessEscola($matricula['escola_id']);
                $matriculas[$index]['user_can_change_date'] = $this->loadAcessoDataEntradaSaida();
                $matriculas[$index]['user_can_change_situacao'] = $this->isUsuarioAdmin();
                $matriculas[$index]['transferencia_em_aberto'] = $this->possuiTransferenciaEmAberto($matricula['id']);
            }

            $attrs = array(
                'id',
                'instituicao_id',
                'escola_id',
                'curso_id',
                'serie_id',
                'aluno_id',
                'aluno_nome',
                'situacao',
                'ano',
                'turma_id',
                'turma_nome',
                'escola_nome',
                'escola_nome',
                'curso_nome',
                'serie_nome',
                'ultima_enturmacao',
                'data_entrada',
                'data_entrada',
                'data_saida',
                'user_can_access',
                'user_can_change_date',
                'codigo_situacao',
                'user_can_change_situacao',
                'transferencia_em_aberto',
                'dependencia'
            );

            $matriculas = Portabilis_Array_Utils::filterSet($matriculas, $attrs);

            return array('matriculas' => $matriculas);
        }
    }

    protected function saveParents()
    {

        $maeId = $this->getRequest()->mae_id;
        $paiId = $this->getRequest()->pai_id;
        $pessoaId = $this->getRequest()->pessoa_id;

        $sql = "UPDATE cadastro.fisica set ";

        $virgulaOuNada = '';

        if ($maeId) {
            $sql .= " idpes_mae = {$maeId} ";
            $virgulaOuNada = ", ";
        } elseif ($maeId == '') {
            $sql .= " idpes_mae = NULL ";
            $virgulaOuNada = ", ";
        }

        if ($paiId) {
            $sql .= "{$virgulaOuNada} idpes_pai = {$paiId} ";
            $virgulaOuNada = ", ";
        } elseif ($paiId == '') {
            $sql .= "{$virgulaOuNada} idpes_pai = NULL ";
            $virgulaOuNada = ", ";
        }

        $sql .= " WHERE idpes = {$pessoaId}";
        Portabilis_Utils_Database::fetchPreparedQuery($sql);

    }

    protected function getOcorrenciasDisciplinares()
    {
        if ($this->canGetOcorrenciasDisciplinares()) {
            return $this->loadOcorrenciasDisciplinares();
        }
    }

    function updateBeneficios($id)
    {
        $obj = new clsPmieducarAlunoBeneficio();
        $obj->deletaBeneficiosDoAluno($id);

        foreach ($this->getRequest()->beneficios as $beneficioId) {
            if (!empty($beneficioId))
                $obj->cadastraBeneficiosDoAluno($id, $beneficioId);
        }
    }

    protected function retornaCodigo($palavra)
    {
        return substr($palavra, 0, strpos($palavra, " -"));
    }

    function saveProjetos($alunoId)
    {
        $obj = new clsPmieducarProjeto();
        $obj->deletaProjetosDoAluno($alunoId);

        foreach ($this->getRequest()->projeto_turno as $key => $value) {
            $projetoId = $this->retornaCodigo($this->getRequest()->projeto_cod_projeto[$key]);

            if (is_numeric($projetoId)) {
                $dataInclusao = Portabilis_Date_Utils::brToPgSQL($this->getRequest()->projeto_data_inclusao[$key]);
                $dataDesligamento = Portabilis_Date_Utils::brToPgSQL($this->getRequest()->projeto_data_desligamento[$key]);
                $turnoId = $value;

                if (is_numeric($projetoId) && is_numeric($turnoId) && !empty($dataInclusao)) {
                    if ($this->validaTurnoProjeto($alunoId, $turnoId)) {
                        if (!$obj->cadastraProjetoDoAluno($alunoId, $projetoId, $dataInclusao, $dataDesligamento, $turnoId)) {
                            $this->messenger->append('O aluno não pode ser cadastrado no mesmo projeto mais de uma vez.');
                        }
                    } else {
                        $this->messenger->append('O aluno não pode ser cadastrado em projetos no mesmo turno em que estuda, por favor, verifique.');
                    }
                } else {
                    $this->messenger->append('Para cadastrar o aluno em um projeto é necessário no mínimo informar a data de inclusão e o turno.');
                }
            }
        }
    }

    function saveHistoricoAlturaPeso($alunoId)
    {
        $obj = new clsPmieducarAlunoHistoricoAlturaPeso($alunoId);
        // exclui todos
        $obj->excluir();

        foreach ($this->getRequest()->data_historico as $key => $value) {
            $data_historico = Portabilis_Date_Utils::brToPgSQL($value);
            $altura = $this->getRequest()->historico_altura[$key];
            $peso = $this->getRequest()->historico_peso[$key];

            $obj->data_historico = $data_historico;
            $obj->altura = $altura;
            $obj->peso = $peso;

            if (!$obj->cadastra()) {
                $this->messenger->append('Erro ao cadastrar histórico de altura e peso.');
            }
        }
    }

    protected function post()
    {
        if ($this->canPost()) {
            $id = $this->createOrUpdateAluno();
            $pessoaId = $this->getRequest()->pessoa_id;

            $this->saveParents();

            if (is_numeric($id)) {
                $this->updateBeneficios($id);
                $this->updateResponsavel();
                $this->saveSus($pessoaId);
                $this->createOrUpdateTransporte($id);
                $this->createUpdateOrDestroyEducacensoAluno($id);
                $this->updateDeficiencias();
                $this->createOrUpdateFichaMedica($id);
                $this->createOrUpdateMoradia($id);
                $this->saveProjetos($id);
                $this->saveHistoricoAlturaPeso($id);
                $this->createOrUpdatePessoaTransporte($pessoaId);
                $this->createOrUpdateDocumentos($pessoaId);
                $this->createOrUpdatePessoa($pessoaId);

                $this->messenger->append('Cadastrado realizado com sucesso', 'success', false, 'error');
            } else {
                $this->messenger->append('Aparentemente o aluno não pode ser cadastrado, por favor, verifique.');
            }
        }

        return array('id' => $id);
    }

    protected function put()
    {
        $id = $this->getRequest()->id;
        $pessoaId = $this->getRequest()->pessoa_id;

        $this->saveParents();

        if ($this->canPut() && $this->createOrUpdateAluno($id)) {
            $this->updateBeneficios($id);
            $this->updateResponsavel();
            $this->saveSus($pessoaId);
            $this->createOrUpdateTransporte($id);
            $this->createUpdateOrDestroyEducacensoAluno($id);
            $this->updateDeficiencias();
            $this->createOrUpdateFichaMedica($id);
            $this->createOrUpdateMoradia($id);
            $this->saveProjetos($id);
            $this->saveHistoricoAlturaPeso($id);
            $this->createOrUpdatePessoaTransporte($pessoaId);
            $this->createOrUpdateDocumentos($pessoaId);
            $this->createOrUpdatePessoa($pessoaId);

            $this->messenger->append('Cadastro alterado com sucesso', 'success', false, 'error');
        } else {
            $this->messenger->append('Aparentemente o cadastro não pode ser alterado, por favor, verifique.', 'error', false, 'error');
        }

        return array('id' => $id);
    }

    protected function createOrUpdatePessoaTransporte($ref_idpes)
    {

        $pt = new clsModulesPessoaTransporte(NULL, NULL, $ref_idpes);
        $det = $pt->detalhe();

        $id = $det['cod_pessoa_transporte'];

        $pt = new clsModulesPessoaTransporte($id);
        // após cadastro não muda mais id pessoa
        $pt->ref_idpes = $ref_idpes;
        $pt->ref_idpes_destino = $this->getRequest()->pessoaj_id;
        $pt->ref_cod_ponto_transporte_escolar = $this->getRequest()->transporte_ponto;
        $pt->ref_cod_rota_transporte_escolar = $this->getRequest()->transporte_rota;
        $pt->observacao = Portabilis_String_Utils::toLatin1($this->getRequest()->transporte_observacao);

        return (is_null($id) ? $pt->cadastra() : $pt->edita());
    }


    protected function enable()
    {
        $id = $this->getRequest()->id;

        if ($this->canEnable()) {
            $aluno = new clsPmieducarAluno();
            $aluno->cod_aluno = $id;
            $aluno->ref_usuario_exc = $this->getSession()->id_pessoa;
            $aluno->ativo = 1;

            if ($aluno->edita()) {
                $this->messenger->append('Cadastro ativado com sucesso', 'success', false, 'error');
            } else {
                $this->messenger->append('Aparentemente o cadastro não pode ser ativado, por favor, verifique.',  'error', false, 'error');
            }
        }

        return array('id' => $id);
    }

    protected function delete()
    {
        $id = $this->getRequest()->id;
        $matriculaAtiva = dbBool($this->possuiMatriculaAtiva($id));

        if (!$matriculaAtiva) {

            if ($this->canDelete()) {
                $aluno = new clsPmieducarAluno();
                $aluno->cod_aluno = $id;
                $aluno->ref_usuario_exc = $this->getSession()->id_pessoa;

                $detalheAluno = $aluno->detalhe();

                if ($aluno->excluir()) {
                    $auditoria = new clsModulesAuditoriaGeral("aluno", $this->getSession()->id_pessoa, $id);
                    $auditoria->exclusao($detalheAluno);
                    $this->messenger->append('Cadastro removido com sucesso', 'success', false, 'error');
                } else {
                    $this->messenger->append('Aparentemente o cadastro não pode ser removido, por favor, verifique.',  'error', false, 'error');
                }
            }
        } else {
            $this->messenger->append('O cadastro não pode ser removido, pois existem matrículas vinculadas.', 'error', false, 'error');
        }

        return array('id' => $id);
    }

    protected function possuiMatriculaAtiva($alunoId)
    {
        $sql = "select exists (select 1 from pmieducar.matricula where ref_cod_aluno = $1 and ativo = 1)";
        return (Portabilis_Utils_Database::selectField($sql, $alunoId));
    }

    //envia foto e salva caminha no banco
    protected function savePhoto($id)
    {
        if ($this->objPhoto != null) {
            //salva foto com data, para evitar problemas com o cache do navegador
            $caminhoFoto = $this->objPhoto->sendPicture($id) . '?' . date('Y-m-d-H:i:s');

            if ($caminhoFoto != '') {
                //new clsCadastroFisicaFoto($id)->exclui();
                $obj = new clsCadastroFisicaFoto($id, $caminhoFoto);
                $detalheFoto = $obj->detalhe();

                if (is_array($detalheFoto) && count($detalheFoto) > 0) {
                    $obj->edita();
                } else {
                    $obj->cadastra();
                }

                return true;
            } else {
                echo '<script>alert(\'Foto não salva.\')</script>';
                return false;
            }
        } else if ($this->del_foto == 'on') {
            $obj = new clsCadastroFisicaFoto($id);
            $obj->excluir();
        }
    }

    // Retorna true caso a foto seja válida
    protected function validatePhoto()
    {
        $this->arquivoFoto = $this->file_foto;

        if (!empty($this->arquivoFoto["name"])) {
            $this->arquivoFoto["name"] = mb_strtolower($this->arquivoFoto["name"], 'UTF-8');
            $this->objPhoto = new PictureController($this->arquivoFoto);

            if ($this->objPhoto->validatePicture()) {
                return TRUE;
            } else {
                $this->messenger->append($this->objPhoto->getErrorMessage());
                return false;
            }

            return false;
        } else {
            $this->objPhoto = null;
            return true;
        }

    }

    protected function createOrUpdateDocumentos($pessoaId)
    {
        $documentos = new clsDocumento();
        $documentos->idpes = $pessoaId;

        // o tipo certidão novo padrão é apenas para exibição ao usuário,
        // não precisa ser gravado no banco
        //
        // quando selecionado um tipo diferente do novo formato,
        // é removido o valor de certidao_nascimento.
        if ($this->getRequest()->tipo_certidao_civil == 'certidao_nascimento_novo_formato') {
            $documentos->tipo_cert_civil = null;
            $documentos->certidao_casamento = '';
            $documentos->certidao_nascimento = $this->getRequest()->certidao_nascimento;
        } else if ($this->getRequest()->tipo_certidao_civil == 'certidao_casamento_novo_formato') {
            $documentos->tipo_cert_civil = null;
            $documentos->certidao_nascimento = '';
            $documentos->certidao_casamento = $this->getRequest()->certidao_casamento;
        } else {
            $documentos->tipo_cert_civil = $this->getRequest()->tipo_certidao_civil;
            $documentos->certidao_nascimento = '';
            $documentos->certidao_casamento = '';
        }

        $documentos->num_termo = $this->getRequest()->termo_certidao_civil;
        $documentos->num_livro = $this->getRequest()->livro_certidao_civil;
        $documentos->num_folha = $this->getRequest()->folha_certidao_civil;

        $documentos->rg = $this->getRequest()->rg;
        $documentos->data_exp_rg = Portabilis_Date_Utils::brToPgSQL(
            $this->getRequest()->data_emissao_rg
        );
        $documentos->sigla_uf_exp_rg = $this->getRequest()->uf_emissao_rg;
        $documentos->idorg_exp_rg = $this->getRequest()->orgao_emissao_rg;

        $documentos->data_emissao_cert_civil = Portabilis_Date_Utils::brToPgSQL(
            $this->getRequest()->data_emissao_certidao_civil
        );

        $documentos->sigla_uf_cert_civil = $this->getRequest()->uf_emissao_certidao_civil;
        $documentos->cartorio_cert_civil = addslashes($this->getRequest()->cartorio_emissao_certidao_civil);
        $documentos->passaporte = addslashes($this->getRequest()->passaporte);
        $documentos->cartorio_cert_civil_inep = $this->getRequest()->cartorio_cert_civil_inep_id;

        // Alteração de documentos compativel com a versão anterior do cadastro,
        // onde era possivel criar uma pessoa, não informando os documentos,
        // o que não criaria o registro do documento, sendo assim, ao editar uma pessoa,
        // o registro do documento será criado, caso não exista.

        $sql = "select 1 from cadastro.documento WHERE idpes = $1 limit 1";

        if (Portabilis_Utils_Database::selectField($sql, $pessoaId) != 1) {
            $documentos->cadastra();
        } else {
            $documentos->edita_aluno();
        }
    }

    protected function createOrUpdatePessoa($idPessoa)
    {
        $fisica = new clsFisica($idPessoa);
        $fisica->cpf = idFederal2int($this->getRequest()->id_federal);
        $fisica->ref_cod_religiao = $this->getRequest()->religiao_id;
        $fisica = $fisica->edita();
    }

    protected function loadAcessoDataEntradaSaida()
    {
        @session_start();
        $this->pessoa_logada = $_SESSION['id_pessoa'];
        $acesso = new clsPermissoes();
        session_write_close();

        return $acesso->permissao_cadastra(626, $this->pessoa_logada, 7, null, true);
    }

    protected function isUsuarioAdmin()
    {
        @session_start();
        $this->pessoa_logada = $_SESSION['id_pessoa'];
        $isAdmin = ($this->pessoa_logada == 1);
        session_write_close();

        return $isAdmin;

    }

    protected function canGetAlunosMatriculados()
    {
        return $this->validatesPresenceOf('instituicao_id') &&
            $this->validatesPresenceOf('escola_id') &&
            $this->validatesPresenceOf('data') &&
            $this->validatesPresenceOf('ano');
    }

    protected function getAlunosMatriculados()
    {
        if ($this->canGetAlunosMatriculados()) {
            $instituicaoId = $this->getRequest()->instituicao_id;
            $escolaId = $this->getRequest()->escola_id;
            $data = $this->getRequest()->data;
            $ano = $this->getRequest()->ano;
            $turnoId = $this->getRequest()->turno_id;
            $cursoId = $this->getRequest()->curso_id;
            $serieId = $this->getRequest()->serie_id;
            $turmaId = $this->getRequest()->turma_id;


            $sql = "SELECT a.cod_aluno as aluno_id
              FROM pmieducar.aluno a
              INNER JOIN pmieducar.matricula m ON m.ref_cod_aluno = a.cod_aluno
              INNER JOIN pmieducar.matricula_turma mt ON m.cod_matricula = mt.ref_cod_matricula
              INNER JOIN pmieducar.turma t ON mt.ref_cod_turma = t.cod_turma
              INNER JOIN cadastro.pessoa p ON p.idpes = a.ref_idpes
              INNER JOIN pmieducar.serie s ON s.cod_serie = m.ref_ref_cod_serie
              INNER JOIN pmieducar.curso c ON c.cod_curso = m.ref_cod_curso
              INNER JOIN pmieducar.escola e ON e.cod_escola = m.ref_ref_cod_escola

              WHERE m.ativo = 1
                AND a.ativo = 1
                AND t.ativo = 1
                AND t.ref_cod_instituicao = $1
                AND e.cod_escola  = $2
                AND (CASE WHEN coalesce($3, current_date)::date = current_date
                      THEN mt.ativo = 1
                     ELSE
                       (CASE WHEN mt.ativo = 0 THEN
                          mt.sequencial = ( select max(matricula_turma.sequencial)
                                              from pmieducar.matricula_turma
                                             inner join pmieducar.matricula on(matricula_turma.ref_cod_matricula = matricula.cod_matricula)
                                             where matricula_turma.ref_cod_matricula = mt.ref_cod_matricula
                                               and matricula_turma.ref_cod_turma = mt.ref_cod_turma
                                               and ($3::date >= matricula_turma.data_enturmacao::date
                                                   and $3::date < coalesce(matricula_turma.data_exclusao::date, matricula.data_cancel::date, current_date))
                                               and matricula_turma.ativo = 0
                                               and not exists(select 1
                                                                from pmieducar.matricula_turma mt_sub
                                                               where mt_sub.ativo = 1
                                                                 and mt_sub.ref_cod_matricula = mt.ref_cod_matricula
                                                                 and mt_sub.ref_cod_turma = mt.ref_cod_turma
                                                              )
                                          )
                       ELSE
                          ($3::date >= mt.data_enturmacao::date
                          and $3::date < coalesce(m.data_cancel::date, mt.data_exclusao::date, current_date))
                       END)
                      END)
              AND t.ano = $4";

            $params = array($instituicaoId, $escolaId, $data, $ano);

            if (is_numeric($turnoId)) {
                $params[] = $turnoId;
                $sql .= 'AND t.turma_turno_id = $' . count($params) . ' ';
            }

            if (is_numeric($cursoId)) {
                $params[] = $cursoId;
                $sql .= 'AND c.cod_curso = $' . count($params) . ' ';
            }

            if (is_numeric($serieId)) {
                $params[] = $serieId;
                $sql .= 'AND s.cod_serie = $' . count($params) . ' ';
            }

            if (is_numeric($turmaId)) {
                $params[] = $turmaId;
                $sql .= 'AND t.cod_turma = $' . count($params) . ' ';
            }

            $sql .= " ORDER BY (upper(p.nome))";

            $alunos = $this->fetchPreparedQuery($sql, $params);

            $attrs = array('aluno_id');
            $alunos = Portabilis_Array_Utils::filterSet($alunos, $attrs);

            return array('alunos' => $alunos);
        }
    }

    protected function getNomeBairro()
    {
        $var1 = $this->getRequest()->id;

        $sql = "SELECT relatorio.get_texto_sem_caracter_especial(bairro.nome) as nome
              FROM pmieducar.aluno
        INNER JOIN cadastro.fisica ON (aluno.ref_idpes = fisica.idpes)
        INNER JOIN cadastro.endereco_pessoa ON (fisica.idpes = endereco_pessoa.idpes)
        INNER JOIN public.bairro ON (endereco_pessoa.idbai = bairro.idbai)
             WHERE cod_aluno = $var1";

        $bairro = $this->fetchPreparedQuery($sql);

        return $bairro;
    }


    public function Gerar()
    {
        if ($this->isRequestFor('get', 'aluno')) {
            $this->appendResponse($this->get());
        } else if ($this->isRequestFor('get', 'aluno-search')) {
            $this->appendResponse($this->search());
        } else if ($this->isRequestFor('get', 'alunos-matriculados')) {
            $this->appendResponse($this->getAlunosMatriculados());
        } else if ($this->isRequestFor('get', 'matriculas')) {
            $this->appendResponse($this->getMatriculas());
        } else if ($this->isRequestFor('get', 'todos-alunos')) {
            $this->appendResponse($this->getTodosAlunos());
        } else if ($this->isRequestFor('get', 'ocorrencias_disciplinares')) {
            $this->appendResponse($this->getOcorrenciasDisciplinares());
        } else if ($this->isRequestFor('get', 'grade_ultimo_historico')) {
            $this->appendResponse($this->getGradeUltimoHistorico());
        } else if ($this->isRequestFor('get', 'alunos_by_guardian_cpf')) {
            $this->appendResponse($this->getAlunosByGuardianCpf());
        } else if ($this->isRequestFor('post', 'aluno')) {
            $this->appendResponse($this->post());
        } else if ($this->isRequestFor('put', 'aluno')) {
            $this->appendResponse($this->put());
        } else if ($this->isRequestFor('enable', 'aluno')) {
            $this->appendResponse($this->enable());
        } else if ($this->isRequestFor('delete', 'aluno')) {
            $this->appendResponse($this->delete());
        } else if ($this->isRequestFor('get', 'get-nome-bairro')) {
            $this->appendResponse($this->getNomeBairro());
        } else {
            $this->notImplementedOperationError();
        }
    }
}
