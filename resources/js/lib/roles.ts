/** V1 roles (PRODUCT.md, "Papéis e autorização mínima"), labelled for people in pt-BR. */
export const roleLabels: Record<string, string> = {
    network_admin: 'Administrador de rede',
    unit_manager: 'Gestor de unidade',
    shift_coordinator: 'Coordenador de plantões',
    professional: 'Profissional',
    importer: 'Importador',
    reception: 'Recepção',
    bed_management: 'Gestão de leitos',
    housekeeping: 'Higienização',
    pharmacy: 'Farmácia',
    laboratory: 'Laboratório',
    imaging: 'Imagem',
    surgical_center: 'Centro cirúrgico',
    auditor: 'Auditor',
};

export const roleDescriptions: Record<string, string> = {
    network_admin:
        'Administra unidades, pessoas e permissões de toda a instituição.',
    unit_manager: 'Administra setores e profissionais de uma unidade.',
    shift_coordinator: 'Cria e publica escalas e decide mudanças na unidade.',
    professional: 'Consulta os próprios plantões e faz solicitações.',
    importer: 'Prepara importações de dados na unidade.',
    reception:
        'Cadastra pacientes, agenda consultas e confirma chegadas na unidade.',
    bed_management:
        'Acompanha o mapa de leitos, bloqueia, libera e envia leitos para higienização.',
    housekeeping: 'Marca os leitos higienizados como prontos.',
    pharmacy:
        'Avalia as prescrições válidas da unidade, item a item, vendo só a prescrição e as alergias.',
    laboratory:
        'Recebe os pedidos de exames de laboratório da unidade, registra a coleta e libera os resultados.',
    imaging:
        'Recebe os pedidos de exames de imagem da unidade, registra a realização e libera os laudos.',
    surgical_center:
        'Agenda as salas e a equipe das cirurgias da unidade, conduz o checklist de cirurgia segura e registra os materiais.',
    auditor: 'Consulta trilhas e relatórios, sem alterar registros.',
};

export const roleOrder = Object.keys(roleLabels);
